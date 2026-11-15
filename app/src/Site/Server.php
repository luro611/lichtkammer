<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Liefert die fertigen Seiten der aktiven Fassung aus. Kein Zugriff auf Datenbank oder Anmeldung:
 * nur data/site/current und eine Datei.
 */
final class Server
{
    public function __construct(private readonly Config $config)
    {
    }

    public function serve(Request $req): Response
    {
        $dir = $this->config->dataDir() . '/site';
        $id = trim((string) @file_get_contents($dir . '/current'));
        if (!Builder::validId($id) || !is_dir($dir . '/releases/' . $id)) {
            return new Response("Diese Seite ist noch nicht veröffentlicht.\n", 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '300', 'Cache-Control' => 'no-store']);
        }
        $root = $dir . '/releases/' . $id;
        $path = $req->path();
        if (str_contains($path, "\0") || str_contains($path, '..') || preg_match('#/\.#', $path) === 1) {
            return $this->notFound($root);
        }
        if (in_array($path, ['/robots.txt', '/sitemap.xml'], true)) {
            $file = $root . $path;
            $type = $path === '/robots.txt' ? 'text/plain; charset=utf-8' : 'application/xml; charset=utf-8';
        } elseif (preg_match('#^/[^.]*$#', $path) === 1) {
            if ($path !== '/' && !str_ends_with($path, '/')) {
                return Response::redirect($path . '/', 301);
            }
            $file = $root . $path . 'index.html';
            $type = 'text/html; charset=utf-8';
        } else {
            return $this->notFound($root);
        }
        if (!is_file($file) || !str_starts_with((string) realpath($file), (string) realpath($root) . '/')) {
            return $this->notFound($root);
        }
        $body = (string) file_get_contents($file);
        $etag = '"' . md5($body) . '"';
        $h = ['Content-Type' => $type, 'ETag' => $etag, 'Cache-Control' => 'public, max-age=300, must-revalidate', 'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT'];
        if ($req->header('If-None-Match') === $etag) {
            return new Response('', 304, $h);
        }
        return new Response($body, 200, $h);
    }

    private function notFound(string $root): Response
    {
        $f = $root . '/404.html';
        $body = is_file($f) ? (string) file_get_contents($f) : 'Nicht gefunden';
        return new Response($body, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=60']);
    }
}
