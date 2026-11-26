<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http;

use MamaMboga\Infrastructure\Container\Container;

/**
 * request -> global middleware -> route match -> route middleware -> controller.
 * Each middleware wraps the next, so the first listed is the outermost.
 */
final class Kernel
{
    /** @param list<string> $middleware global middleware class names, outermost first */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $middleware,
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->pipe($this->middleware, $request, fn (Request $r): Response => $this->dispatch($r));
    }

    private function dispatch(Request $request): Response
    {
        ['route' => $route, 'params' => $params] = $this->router->match($request->method, $request->path);
        $request = $request->withAttribute('route.params', $params);

        return $this->pipe($route->middleware, $request, function (Request $r) use ($route): Response {
            [$class, $method] = $route->handler;
            $controller = $this->container->get($class);
            /** @var Response */
            return $controller->{$method}($r);
        });
    }

    /**
     * @param list<string>                    $stack
     * @param callable(Request): Response     $core
     */
    private function pipe(array $stack, Request $request, callable $core): Response
    {
        $next = $core;
        foreach (array_reverse($stack) as $spec) {
            $middleware = $this->resolve($spec);
            $next = static fn (Request $r): Response => $middleware->handle($r, $next);
        }

        return $next($request);
    }

    private function resolve(string $spec): Middleware
    {
        [$class, $args] = array_pad(explode(':', $spec, 2), 2, '');
        /** @var class-string<Middleware> $class */
        if ($args === '') {
            return $this->container->get($class);
        }

        return $this->container->make($class, array_map(
            static fn (string $a): int|string => ctype_digit($a) ? (int) $a : $a,
            explode(',', $args),
        ));
    }
}
