<?php
// realtic signaling endpoint for plain PHP hosting: repeated polling (pull), no long-lived
// requests, SQLite storage. One request both sends and receives:
//
//   POST signal.php?room=<room>&id=<client id>
//   body     {"send": [{"to": "<id>", "msg": {...}}], "leave": false}
//   response {"messages": [{"from": "<id>", "msg": {...}}], "members": ["<id>", ...],
//             "fastMs": 250, "idleMs": 3000}
//
// The body is sent as text/plain, so cross-origin use needs no preflight. Messages are delivered
// at most once: a request returns the pending messages of its client and deletes exactly those
// rows in the same transaction. Members that stop polling expire after `member_timeout_s`.
// Settings: the defaults below, overridden by an optional config.php next to this file that
// returns an array.

declare(strict_types=1);

const REALTIC_DEFAULTS = [
    'db' => __DIR__ . '/data/realtic.sqlite',
    'journal_mode' => 'WAL',
    'busy_timeout_ms' => 5000,
    'member_timeout_s' => 30,
    'message_ttl_s' => 30,
    'max_body_bytes' => 262144,
    'max_messages' => 64,
    'max_message_bytes' => 65536,
    'max_room_members' => 64,
    'poll_fast_ms' => 250,
    'poll_idle_ms' => 3000,
    'allowed_origins' => [],
    'log_file' => null,
    'log_polls' => false,
    'log_max_bytes' => 1048576,
    'log_files' => 5,
    'test_controls' => null,
];

const REALTIC_NAME = '/^[A-Za-z0-9_.-]{1,64}$/';

/** Merged settings: defaults, config.php, and REALTIC_TEST_CONTROLS for the test harness. */
function realtic_config(): array
{
    $config = REALTIC_DEFAULTS;
    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        $config = array_merge($config, (array) require $file);
    }
    $controls = getenv('REALTIC_TEST_CONTROLS');
    if ($controls !== false && $controls !== '') {
        $config['test_controls'] = $controls;
    }
    $db = getenv('REALTIC_DB');
    if ($db !== false && $db !== '') {
        $config['db'] = $db;
    }
    return $config;
}

/** Test controls (down, delayMs, duplicate, redeliver), read from a JSON file when enabled. */
function realtic_controls(array $config): array
{
    $controls = ['down' => false, 'delayMs' => 0, 'duplicate' => false, 'redeliver' => false];
    if ($config['test_controls'] && is_file($config['test_controls'])) {
        $read = json_decode((string) file_get_contents($config['test_controls']), true);
        if (is_array($read)) {
            $controls = array_merge($controls, $read);
        }
    }
    return $controls;
}

function realtic_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

/** UTC timestamp in the library's log format, yyyy-MM-dd_HH-mm-ss.SSS. */
function realtic_utc(): string
{
    $t = microtime(true);
    return gmdate('Y-m-d_H-i-s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
}

function realtic_respond(int $status, ?array $body = null): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

function realtic_cors(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = $config['allowed_origins'];
    if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}

/** Opens the database and creates the schema on first use. */
function realtic_db(array $config): PDO
{
    $dir = dirname($config['db']);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $db = new PDO('sqlite:' . $config['db'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = ' . (int) $config['busy_timeout_ms']);
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 0) {
        $db->exec('PRAGMA journal_mode = ' . preg_replace('/[^A-Za-z]/', '', $config['journal_mode']));
        $db->exec('BEGIN IMMEDIATE');
        if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 0) {
            $db->exec('CREATE TABLE members (room TEXT NOT NULL, id TEXT NOT NULL, last_seen INTEGER NOT NULL,
                PRIMARY KEY (room, id)) WITHOUT ROWID');
            $db->exec('CREATE TABLE messages (seq INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL,
                recipient TEXT NOT NULL, sender TEXT NOT NULL, body TEXT NOT NULL, due INTEGER NOT NULL,
                created INTEGER NOT NULL)');
            $db->exec('CREATE INDEX messages_recipient ON messages (room, recipient, seq)');
            $db->exec('CREATE INDEX messages_created ON messages (created)');
            $db->exec('CREATE TABLE history (room TEXT NOT NULL, recipient TEXT NOT NULL, sender TEXT NOT NULL,
                body TEXT NOT NULL, created INTEGER NOT NULL)');
            $db->exec('PRAGMA user_version = 1');
        }
        $db->exec('COMMIT');
    }
    return $db;
}

/** Appends one line to the request log and rotates it by size, keeping `log_files` files. */
function realtic_log(array $config, string $line): void
{
    $file = $config['log_file'];
    if (!$file) {
        return;
    }
    if (is_file($file) && filesize($file) > $config['log_max_bytes']) {
        for ($i = $config['log_files'] - 1; $i >= 1; $i--) {
            $from = $i === 1 ? $file : "$file." . ($i - 1);
            if (is_file($from)) {
                @rename($from, "$file.$i");
            }
        }
    }
    @file_put_contents($file, realtic_utc() . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Applies one request: stores the sent messages, returns and deletes the pending ones, and
 * updates membership. Returns the response body and log fields.
 */
function realtic_exchange(PDO $db, array $config, array $controls, string $room, string $id, array $send, bool $leave): array
{
    $now = realtic_now_ms();
    $alive = $now - $config['member_timeout_s'] * 1000;
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM messages WHERE created < ?')->execute([$now - $config['message_ttl_s'] * 1000]);
        $db->prepare('DELETE FROM history WHERE created < ?')->execute([$now - $config['message_ttl_s'] * 1000]);
        $db->prepare('DELETE FROM members WHERE last_seen < ?')->execute([$alive]);

        $seen = $db->prepare('SELECT 1 FROM members WHERE room = ? AND id = ?');
        $seen->execute([$room, $id]);
        $joined = !$leave && !$seen->fetchColumn();
        if ($joined) {
            $count = $db->prepare('SELECT COUNT(*) FROM members WHERE room = ?');
            $count->execute([$room]);
            if ((int) $count->fetchColumn() >= $config['max_room_members']) {
                $db->exec('ROLLBACK');
                return ['status' => 403, 'body' => ['error' => 'room full'], 'log' => 'room-full'];
            }
        }

        $isMember = $db->prepare('SELECT 1 FROM members WHERE room = ? AND id = ?');
        $insert = $db->prepare('INSERT INTO messages (room, recipient, sender, body, due, created) VALUES (?, ?, ?, ?, ?, ?)');
        $keep = $db->prepare('INSERT INTO history (room, recipient, sender, body, created) VALUES (?, ?, ?, ?, ?)');
        $kinds = [];
        $sent = 0;
        foreach ($send as $item) {
            $to = $item['to'] ?? null;
            $msg = $item['msg'] ?? null;
            if (!is_string($to) || !preg_match(REALTIC_NAME, $to) || !is_array($msg)) {
                continue;
            }
            $body = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false || strlen($body) > $config['max_message_bytes']) {
                continue;
            }
            $isMember->execute([$room, $to]);
            if (!$isMember->fetchColumn()) {
                continue;
            }
            $times = $controls['duplicate'] ? 2 : 1;
            for ($i = 0; $i < $times; $i++) {
                $insert->execute([$room, $to, $id, $body, $now + (int) $controls['delayMs'], $now]);
            }
            if ($controls['redeliver']) {
                $keep->execute([$room, $to, $id, $body, $now]);
            }
            $sent++;
            $kind = is_string($msg['t'] ?? null) ? $msg['t'] : '?';
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }

        $messages = [];
        if ($leave) {
            $db->prepare('DELETE FROM members WHERE room = ? AND id = ?')->execute([$room, $id]);
            $db->prepare('DELETE FROM messages WHERE room = ? AND recipient = ?')->execute([$room, $id]);
        } else {
            $db->prepare('INSERT INTO members (room, id, last_seen) VALUES (?, ?, ?)
                ON CONFLICT (room, id) DO UPDATE SET last_seen = excluded.last_seen')->execute([$room, $id, $now]);
            if ($joined && $controls['redeliver']) {
                $db->prepare('INSERT INTO messages (room, recipient, sender, body, due, created)
                    SELECT room, recipient, sender, body, ?, ? FROM history WHERE room = ? AND recipient = ?')
                    ->execute([$now, $now, $room, $id]);
            }
            $pending = $db->prepare('SELECT seq, sender, body FROM messages WHERE room = ? AND recipient = ? AND due <= ?
                ORDER BY seq LIMIT 256');
            $pending->execute([$room, $id, $now]);
            $delete = $db->prepare('DELETE FROM messages WHERE seq = ?');
            foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $messages[] = ['from' => $row['sender'], 'msg' => json_decode($row['body'], true)];
                $delete->execute([$row['seq']]);
            }
        }

        $list = $db->prepare('SELECT id FROM members WHERE room = ? ORDER BY id');
        $list->execute([$room]);
        $members = $list->fetchAll(PDO::FETCH_COLUMN);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        throw $error;
    }

    $kindText = implode(',', array_map(fn ($k, $n) => "$k:$n", array_keys($kinds), $kinds));
    $action = $leave ? 'leave' : ($joined ? 'join' : 'poll');
    return [
        'status' => 200,
        'body' => [
            'messages' => $messages,
            'members' => $members,
            'fastMs' => $config['poll_fast_ms'],
            'idleMs' => $config['poll_idle_ms'],
        ],
        'log' => sprintf('%s sent=%d%s recv=%d members=%d', $action, $sent, $kindText ? "($kindText)" : '', count($messages), count($members)),
        'quiet' => $action === 'poll' && $sent === 0 && !$messages,
    ];
}

function realtic_main(): void
{
    $start = microtime(true);
    $config = realtic_config();
    realtic_cors($config);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        realtic_respond(204);
        return;
    }
    if ($method !== 'POST') {
        realtic_respond(405, ['error' => 'use POST']);
        return;
    }
    $controls = realtic_controls($config);
    if ($controls['down']) {
        realtic_respond(503, ['error' => 'down']);
        return;
    }
    $room = (string) ($_GET['room'] ?? 'default');
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match(REALTIC_NAME, $room) || !preg_match(REALTIC_NAME, $id)) {
        realtic_respond(400, ['error' => 'bad room or id']);
        return;
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, $config['max_body_bytes'] + 1);
    if (strlen($raw) > $config['max_body_bytes']) {
        realtic_respond(413, ['error' => 'body too large']);
        return;
    }
    $request = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($request)) {
        realtic_respond(400, ['error' => 'bad body']);
        return;
    }
    $send = is_array($request['send'] ?? null) ? array_slice($request['send'], 0, $config['max_messages']) : [];
    $leave = ($request['leave'] ?? false) === true;

    try {
        $result = realtic_exchange(realtic_db($config), $config, $controls, $room, $id, $send, $leave);
    } catch (Throwable $error) {
        realtic_log($config, sprintf('error room=%s id=%s %s', $room, $id, $error->getMessage()));
        realtic_respond(500, ['error' => 'server error']);
        return;
    }
    realtic_respond($result['status'], $result['body']);
    if (!($result['quiet'] ?? false) || $config['log_polls']) {
        realtic_log($config, sprintf('%s room=%s id=%s ms=%d addr=%s', $result['log'], $room, $id,
            (int) round((microtime(true) - $start) * 1000), $_SERVER['REMOTE_ADDR'] ?? '-'));
    }
}

realtic_main();
