<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Router
{
    /** @var array<string,callable> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $handler = $this->routes[$method . ' ' . $path] ?? null;
        if ($handler === null) {
            $known = array_filter(array_keys($this->routes), static fn (string $k): bool => str_ends_with($k, ' ' . $path));
            return $known !== []
                ? new Response('Methode nicht erlaubt', 405, ['Allow' => 'GET, HEAD'])
                : Response::notFound();
        }
        return $handler($request);
    }
}
