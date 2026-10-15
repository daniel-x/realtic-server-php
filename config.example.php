<?php
// Copy to config.php and adjust. Every key is optional; defaults are in signal.php.
return [
    // Keep the database outside the document root when the host allows it.
    'db' => __DIR__ . '/data/realtic.sqlite',
    // WAL needs shared memory; use DELETE on network file systems (NFS, some shared hosts).
    'journal_mode' => 'WAL',
    'member_timeout_s' => 30,
    'poll_fast_ms' => 250,
    'poll_idle_ms' => 3000,
    // Origins allowed to call signal.php from another site, or ['*'].
    'allowed_origins' => [],
    // One line per request that carried messages or changed membership, rotated by size.
    'log_file' => __DIR__ . '/data/requests.log',
    'log_max_bytes' => 1048576,
    'log_files' => 5,
    // Directory for log.php (forwardLog): one file per group. Keep it outside the document root,
    // the logs show addresses and ids of every peer.
    // 'client_log_dir' => '/var/log/realtic',
    'client_log_max_bytes' => 16777216,
    'client_log_files' => 5,
    // router.php only: the realtic client files (library and demos), e.g. a git checkout or
    // node_modules/realtic. Default: a realtic checkout next to this directory.
    // 'client_dir' => '/srv/realtic',
];
