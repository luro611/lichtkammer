<?php
declare(strict_types=1);

use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Response;
use Lichtkammer\Router;
use Lichtkammer\SecurityHeaders;
use Lichtkammer\View;

// all-inkl: app/ liegt neben index.php im Webroot (per .htaccess gesperrt).
// Docker/Entwicklung: app/ liegt neben public/.
$appDir = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : dirname(__DIR__) . '/app';
require $appDir . '/bootstrap.php';

$config = Config::load($appDir, __DIR__, getenv() ?: []);
$request = new Request($_SERVER, $config);
$view = new View($appDir . '/templates', $config);

ini_set('display_errors', $config->isDebug() ? '1' : '0');

$router = new Router();
$router->get('/', static fn (): Response => Response::html($view->render('home', [
    'title' => $config->siteName(),
    'request' => $request,
])));
$router->get('/health', static fn (): Response => new Response('ok ' . Config::VERSION . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']));

try {
    $response = $router->dispatch($request);
} catch (\Throwable $t) {
    error_log('Lichtkammer: ' . $t::class . ': ' . $t->getMessage());
    $response = Response::html($view->render('error', [
        'title' => 'Fehler',
        'detail' => $config->isDebug() ? $t->getMessage() : null,
    ]), 500);
}

$response = new Response($response->body, $response->status, $response->headers + SecurityHeaders::for($request, $config));
$response->send($request->method() === 'HEAD');
