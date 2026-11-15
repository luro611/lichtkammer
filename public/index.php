<?php
declare(strict_types=1);

use Lichtkammer\App;
use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Response;
use Lichtkammer\SecurityHeaders;
use Lichtkammer\View;

// Fehler beim Start (Konfiguration, Autoloader) nie anzeigen; in Entwicklung wird unten freigegeben.
ini_set('display_errors', '0');

// all-inkl: app/ liegt neben index.php im Webroot (per .htaccess gesperrt).
// Docker/Entwicklung: app/ liegt neben public/.
$appDir = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : dirname(__DIR__) . '/app';
require $appDir . '/bootstrap.php';

$config = Config::load($appDir, __DIR__, getenv() ?: []);
$cookies = array_filter($_COOKIE, 'is_string');
$request = new Request($_SERVER, $config, $_POST, $cookies);
$view = new View($appDir . '/templates', $config);

ini_set('display_errors', $config->isDebug() ? '1' : '0');

try {
    $response = (new App($config))->handle($request);
} catch (\Throwable $t) {
    error_log('Lichtkammer: ' . $t::class . ': ' . $t->getMessage());
    $response = Response::html($view->render('error', [
        'title' => 'Fehler',
        'detail' => $config->isDebug() ? $t->getMessage() : null,
    ]), 500);
}

$response = $response->withDefaults(SecurityHeaders::for($request, $config));
$response->send($request->method() === 'HEAD');
