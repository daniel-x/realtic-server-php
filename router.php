<?php
// Router for PHP's built-in development server, run from this directory:
//   php -S localhost:8080 router.php
// It serves signal.php and log.php, and the realtic client files (library and demos) from
// `client_dir` (config.php or REALTIC_CLIENT_DIR; default: a realtic checkout next to this
// directory) at the root, with Cache-Control: no-store, because the built-in server sends no
// validators and browsers would reuse stale files. Not for production: there, the web server
// serves the files.

declare(strict_types=1);

const REALTIC_TYPES = [
    'js' => 'text/javascript', 'mjs' => 'text/javascript', 'html' => 'text/html', 'css' => 'text/css',
    'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
];

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (str_ends_with($path, '/signal.php') || str_ends_with($path, '/log.php')) {
    require __DIR__ . '/' . basename($path);
    return true;
}
define('REALTIC_LIBRARY', true);
require __DIR__ . '/signal.php';
$root = realpath(realtic_config()['client_dir'] ?? __DIR__ . '/../realtic');
if ($root === false) {
    http_response_code(404);
    echo "no client files: set client_dir in config.php or REALTIC_CLIENT_DIR\n";
    return true;
}
$file = realpath($root . $path . (str_ends_with($path, '/') ? 'index.html' : ''));
if ($file === false || !str_starts_with($file, $root . '/') || !is_file($file)
    || preg_match('#/\.|/node_modules/#', substr($file, strlen($root))) === 1) {
    http_response_code(404);
    return true;
}
header('Content-Type: ' . (REALTIC_TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($file);
return true;
