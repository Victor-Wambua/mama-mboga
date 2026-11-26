<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http;

/**
 * Maps METHOD + path to a controller action. Patterns use {name} for a
 * path segment and {name:regex} to constrain it: /owner/orders/{id:\d+}.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];
    private string $prefix = '';
    /** @var list<string> */
    private array $stack = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                      $middleware
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $paramNames = [];
        $full = $this->prefix . $pattern;
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}|[^{]+/',
            static function (array $m) use (&$paramNames): string {
                if (!isset($m[1])) {
                    return preg_quote($m[0], '#');
                }
                $paramNames[] = $m[1];

                return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            },
            $full,
        ) ?? $full;

        $this->routes[] = new Route(strtoupper($method), $full, $handler, [...$this->stack, ...$middleware], '#^' . $regex . '/?$#', $paramNames);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                      $middleware
     */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                      $middleware
     */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** @param list<string> $middleware applied to every route defined inside */
    public function group(string $prefix, array $middleware, \Closure $define): void
    {
        $savedPrefix = $this->prefix;
        $savedStack = $this->stack;
        $this->prefix .= $prefix;
        $this->stack = [...$this->stack, ...$middleware];
        $define($this);
        $this->prefix = $savedPrefix;
        $this->stack = $savedStack;
    }

    /**
     * @return array{route: Route, params: array<string, string>}
     * @throws HttpException 404 when nothing matches, 405 (with Allow) when only the method is wrong
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = $path === '' ? '/' : $path;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $path, $m) !== 1) {
                continue;
            }
            if ($route->method !== $method && !($method === 'HEAD' && $route->method === 'GET')) {
                $allowed[] = $route->method;
                continue;
            }
            $params = [];
            foreach ($route->paramNames as $name) {
                $params[$name] = rawurldecode($m[$name]);
            }

            return ['route' => $route, 'params' => $params];
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Method not allowed', ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404, 'Not found');
    }
}
