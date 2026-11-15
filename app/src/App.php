<?php
declare(strict_types=1);

namespace Lichtkammer;

use Lichtkammer\Admin\AdminController;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\ConfigError;
use Lichtkammer\Auth\Services;

/** Verdrahtet öffentliche Seiten und Admin. public/index.php und die Tests benutzen dieselbe Klasse. */
final class App
{
    private ?Services $services = null;
    private ?AdminController $admin = null;
    private readonly View $view;
    private readonly Router $public;

    /** @param array{memory_cost:int,time_cost:int,threads:int}|null $argonOptions nur für Tests */
    public function __construct(
        private readonly Config $config,
        private readonly ?Clock $clock = null,
        private readonly ?array $argonOptions = null,
    ) {
        $this->view = new View($config->appDir . '/templates', $config);
        $this->public = new Router();
        $this->public->get('/', fn (Request $request): Response => Response::html($this->view->render('home', [
            'title' => $this->config->siteName(),
            'request' => $request,
        ])));
        $this->public->get('/health', static fn (): Response => new Response('ok ' . Config::VERSION . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']));
    }

    public function services(): Services
    {
        return $this->services ??= Services::create($this->config, $this->clock, $this->argonOptions);
    }

    public function handle(Request $request): Response
    {
        $path = $request->path();
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            try {
                $this->admin ??= new AdminController($this->services(), $this->view, $this->config);
            } catch (ConfigError $e) {
                return Response::html($this->view->render('admin/config_error', [
                    'title' => 'Admin nicht verfügbar',
                    'problem' => $e->getMessage(),
                ]), 503)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
            }
            return $this->admin->handle($request);
        }
        return $this->public->dispatch($request);
    }
}
