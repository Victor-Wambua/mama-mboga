<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\Session;
use MamaMboga\Infrastructure\View\PageRenderer;

abstract class BaseController
{
    public function __construct(protected readonly PageRenderer $pages, protected readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $data */
    protected function page(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        return $this->pages->html($request, $template, $data, $status);
    }

    protected function session(Request $request): Session
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('No session on the request.');
        }

        return $session;
    }

    protected function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }

    protected function flash(Request $request, string $type, string $message): void
    {
        $this->session($request)->flash($type, $message);
    }

    /** Only same-site relative paths: never redirect to a URL supplied by the client. */
    protected function safeNext(string $next, string $fallback): string
    {
        return preg_match('#^/[A-Za-z0-9/_-]*$#', $next) === 1 && !str_starts_with($next, '//') ? $next : $fallback;
    }
}
