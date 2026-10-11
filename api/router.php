<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https?://localhost(?::\d+)?$#', $origin) === 1) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Client-Platform, X-Client-Version, X-Seat-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/public/index.php';
