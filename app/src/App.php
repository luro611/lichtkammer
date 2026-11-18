<?php
declare(strict_types=1);

namespace Lichtkammer;

use Lichtkammer\Admin\AdminController;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\ConfigError;
use Lichtkammer\Auth\Services;
use Lichtkammer\Site\Server;

/** Verdrahtet öffentliche Seiten und Admin. public/index.php und die Tests benutzen dieselbe Klasse. */
final class App
{
    private ?Services $services = null;
    private ?AdminController $admin = null;
    private readonly View $view;
    private readonly Server $site;

    /** @param array{memory_cost:int,time_cost:int,threads:int}|null $argonOptions nur für Tests */
    public function __construct(
        private readonly Config $config,
        private readonly ?Clock $clock = null,
        private readonly ?array $argonOptions = null,
    ) {
        $this->view = new View($config->appDir . '/templates', $config);
        $this->site = new Server($config);
    }

    public function services(): Services
    {
        return $this->services ??= Services::create($this->config, $this->clock, $this->argonOptions);
    }

    public function handle(Request $request): Response
    {
        $path = $request->path();
        // Andere Adressen der Seite (www/ohne www, alte Testadresse) in einem Schritt auf die Hauptadresse (Entwurf 20, E4/M1).
        // Nur eingetragene Hosts (LK_REDIRECT_HOSTS); Zwischenspeicher kurz, damit ein Rückfall möglich bleibt.
        $host = $request->host();
        if ($host !== '' && $host !== $this->config->baseHost() && $this->config->baseHost() !== '' && in_array($host, $this->config->redirectHosts(), true)) {
            return Response::redirect($this->config->baseUrl() . $request->requestUri(), 301)->withHeaders(['Cache-Control' => 'public, max-age=3600']);
        }
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
        if ($path === '/health') {
            return new Response('ok ' . Config::VERSION . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return Response::notFound();
        }
        return $this->site->serve($request);
    }
}
