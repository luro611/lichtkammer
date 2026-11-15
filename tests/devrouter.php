<?php
// Nur für die lokale Ansicht (php -S): liefert Dateien aus public/ und /media/ aus LK_MEDIA_DIR, sonst index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$media = getenv('LK_MEDIA_DIR');
if ($media && str_starts_with($path, '/media/')) {
    $file = realpath($media . '/' . substr($path, 7));
    if ($file !== false && str_starts_with($file, realpath($media) . '/') && is_file($file)) {
        header('Content-Type: ' . mime_content_type($file));
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}
$file = __DIR__ . '/../public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
