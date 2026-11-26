<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http;

final readonly class Route
{
    /**
     * @param array{0: class-string, 1: string} $handler controller class and method
     * @param list<string>                      $middleware class names, optionally "Class:arg,arg"
     * @param list<string>                      $paramNames
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public array $handler,
        public array $middleware,
        public string $regex,
        public array $paramNames,
    ) {
    }
}
