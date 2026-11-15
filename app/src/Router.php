<?php
declare(strict_types=1);

namespace Lichtkammer;

/**
 * Einfacher Router. Pfade mit {name} liefern den Wert als zweites Argument an den Handler.
 * Bekannte Pfade mit falscher Methode liefern 404 statt 405 (405 würde gesperrte Routen verraten).
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];

    /** @var (callable(Request):Response)|null */
    private $fallback = null;

    /** Antwort für unbekannte Pfade (statt des schlichten Textes). */
    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\\\{([a-z]+)\\\}#', '(?P<$1>[^/]+)', preg_quote($path, '#')) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $path, 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method || preg_match($r['regex'], $path, $m) !== 1) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($r['handler'])($request, $params);
        }
        return $this->fallback !== null ? ($this->fallback)($request) : Response::notFound();
    }
}
