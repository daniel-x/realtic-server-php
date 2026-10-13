<?php
// Router for PHP's built-in development server, run from the repository root:
//   php -S localhost:8080 server/php/router.php
// It serves signal.php, log.php and the static files of the repository (demos, library sources) with
// Cache-Control: no-store, because the built-in server sends no validators and browsers would
// reuse stale files. Not for production: there, the web server serves the files.

declare(strict_types=1);

const REALTIC_TYPES = [
    'js' => 'text/javascript', 'mjs' => 'text/javascript', 'html' => 'text/html', 'css' => 'text/css',
    'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
];

$root = realpath(__DIR__ . '/../..');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (str_ends_with($path, '/signal.php') || str_ends_with($path, '/log.php')) {
    require __DIR__ . '/' . basename($path);
    return true;
}
$file = realpath($root . $path . (str_ends_with($path, '/') ? 'index.html' : ''));
if ($file === false || !str_starts_with($file, $root . '/') || !is_file($file) || str_starts_with($file, __DIR__ . '/data/')) {
    http_response_code(404);
    return true;
}
header('Content-Type: ' . (REALTIC_TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: no-store');
readfile($file);
return true;
