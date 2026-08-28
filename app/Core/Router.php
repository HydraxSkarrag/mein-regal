<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Routing for roughly fifteen paths.
 *
 * A pattern is a literal path with optional {name} segments. That covers every
 * address this application has, and it is small enough that its behaviour is
 * obvious from reading it - which is the whole argument for not pulling in a
 * router library that cannot be installed on the server anyway.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use (&$names): string {
                $names[] = $match[1];

                return '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{handler: callable, params: array<string,string>}|null null
     *         when no route matches; 405 is not distinguished from 404 on
     *         purpose - the shape of the routing table is not public
     *         information.
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = rawurldecode($matches[$index] ?? '');
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return null;
    }

    public function dispatch(Request $request): ?Response
    {
        $route = $this->match($request->method, $request->path);
        if ($route === null) {
            return null;
        }

        $result = ($route['handler'])($request, $route['params']);

        return $result instanceof Response ? $result : null;
    }
}
