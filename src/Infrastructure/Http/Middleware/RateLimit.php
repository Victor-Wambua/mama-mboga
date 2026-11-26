<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http\Middleware;

use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\RateLimiter;

/** Route middleware spec: "RateLimit:login,8,900" = 8 POSTs per 15 minutes per client address. */
final class RateLimit implements Middleware
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $bucket = 'default',
        private readonly int $limit = 60,
        private readonly int $window = 60,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->method !== 'POST') {
            return $next($request);
        }

        $hits = $this->limiter->hit($this->bucket . ':' . $request->ip(), $this->window);
        if ($hits > $this->limit) {
            throw new HttpException(429, 'Too many attempts', ['Retry-After' => (string) $this->limiter->retryAfter($this->window)]);
        }

        return $next($request);
    }
}
