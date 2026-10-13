<?php
// realtic log endpoint for forwardLog: appends the forwarded log entries of all peers of a group
// to one file per group, in arrival order, so a communication problem can be read in one place.
//
//   POST log.php?group=<group>&id=<client id>
//   body {"entries": [{"seq": 1, "time": <UTC ms with a microsecond fraction>, "level": "info",
//          "area": "...", "peer": "...", "session": "...", "msg": "...", "data": {...}}]}
//
// Each line: receive time, the client's entry time (both UTC, microseconds), client id, #seq,
// level, area, ids, message, data. Disabled (404) unless `client_log_dir` is set in config.php.
// Files rotate at `client_log_max_bytes`, keeping `client_log_files` files per group.

declare(strict_types=1);

define('REALTIC_LIBRARY', true);
require __DIR__ . '/signal.php';

const REALTIC_LEVELS = ['debug', 'info', 'warn', 'error'];

/** One forwarded entry as a line of the group file. */
function realtic_entry_line(string $received, string $id, array $e): string
{
    $str = fn ($v, int $max = 64): ?string => is_string($v) ? substr($v, 0, $max) : null;
    $level = in_array($e['level'] ?? null, REALTIC_LEVELS, true) ? $e['level'] : 'info';
    $ids = implode(' ', array_filter([
        $str($e['peer'] ?? null) !== null ? 'peer=' . $str($e['peer']) : null,
        $str($e['session'] ?? null) !== null ? 's=' . $str($e['session']) : null,
    ]));
    $data = array_key_exists('data', $e) && $e['data'] !== null
        ? ' ' . json_encode($e['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : '';
    $text = sprintf('%-5s [%s]%s %s%s', strtoupper($level), $str($e['area'] ?? null) ?? '?', $ids !== '' ? ' ' . $ids : '',
        substr((string) ($e['msg'] ?? ''), 0, 4096), $data);
    $time = realtic_format_us((int) floor((float) $e['time'] * 1000 + 0.5));
    return sprintf("%s %s %s #%d %s\n", $received, $time, $id, (int) ($e['seq'] ?? 0), preg_replace('/[\r\n]+/', ' ', $text));
}

function realtic_log_main(): void
{
    $received = realtic_utc();
    $config = realtic_config();
    realtic_cors($config);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        realtic_respond(204);
        return;
    }
    if (!$config['client_log_dir']) {
        realtic_respond(404, ['error' => 'log forwarding is disabled']);
        return;
    }
    if ($method !== 'POST') {
        realtic_respond(405, ['error' => 'use POST']);
        return;
    }
    $group = (string) ($_GET['group'] ?? 'default');
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match(REALTIC_NAME, $group) || !preg_match(REALTIC_NAME, $id)) {
        realtic_respond(400, ['error' => 'bad group or id']);
        return;
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, $config['max_body_bytes'] + 1);
    if (strlen($raw) > $config['max_body_bytes']) {
        realtic_respond(413, ['error' => 'body too large']);
        return;
    }
    $request = json_decode($raw, true);
    if (!is_array($request) || !is_array($request['entries'] ?? null)) {
        realtic_respond(400, ['error' => 'bad body']);
        return;
    }
    $lines = '';
    foreach ($request['entries'] as $e) {
        if (is_array($e) && is_numeric($e['time'] ?? null)) {
            $lines .= realtic_entry_line($received, $id, $e);
        }
    }
    $dir = $config['client_log_dir'];
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    if ($lines !== '') {
        realtic_append("$dir/$group.log", $lines, $config['client_log_max_bytes'], $config['client_log_files']);
    }
    realtic_respond(204);
}

realtic_log_main();
