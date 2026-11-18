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
        // Nur Zeichen, die Adressen der Seiten enthalten (Kleinbuchstaben, Ziffern, Bindestrich) – keine Steuerzeichen,
        // Backslashes oder Sonderzeichen, die in einer Weiterleitung (Location) Schaden anrichten könnten.
        if (preg_match('#^[A-Za-z0-9_/.\-]*$#', $path) !== 1 || str_contains($path, '..') || preg_match('#/\.#', $path) === 1) {
            return $this->notFound($root);
        }
        $to = $this->redirectFor($root, $path);
        if ($to !== null) {
            // Umzugs-Weiterleitung: in der Übergangszeit nur kurz im Browser merken (Rückfall auf die alte Seite, Entwurf 20, M2).
            $r = Response::redirect($to, 301);
            return $this->config->redirectsPermanent() ? $r : $r->withHeaders(['Cache-Control' => 'public, max-age=3600']);
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

    /**
     * Alte Adresse (Umzug) -> neue Adresse aus _redirects.json der aktiven Fassung. Das Ziel stammt nur aus unserer eigenen
     * Datei und wird trotzdem noch einmal auf erlaubte Zeichen geprüft.
     */
    private function redirectFor(string $root, string $path): ?string
    {
        $file = $root . '/_redirects.json';
        if ($path === '/' || !is_file($file)) {
            return null;
        }
        $map = json_decode((string) file_get_contents($file), true);
        $key = strtolower(rtrim($path, '/'));
        $to = is_array($map) ? ($map[$key] ?? null) : null;
        if (!is_string($to) || preg_match('#^/[A-Za-z0-9_/.\-]*$#', $to) !== 1 || str_starts_with($to, '//') || str_contains($to, '..')) {
            return null;
        }
        return $to;
    }

    private function notFound(string $root): Response
    {
        $f = $root . '/404.html';
        $body = is_file($f) ? (string) file_get_contents($f) : 'Nicht gefunden';
        return new Response($body, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=60']);
    }
}
