<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http\Middleware;

use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

/** Turns exceptions into friendly pages. Details are logged, never shown to visitors unless APP_DEBUG is on. */
final class ErrorHandler implements Middleware
{
    private const TITLES = [
        404 => ['Page not found', 'That page is not here. It may have moved, or the link is wrong.'],
        405 => ['Not allowed', 'That action is not allowed on this page.'],
        419 => ['Page expired', 'This page was open for too long, or the form was sent twice. Go back, reload it and try again.'],
        429 => ['Too many tries', 'Please wait a little and try again.'],
    ];

    public function __construct(private readonly PageRenderer $pages, private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (HttpException $e) {
            [$title, $text] = self::TITLES[$e->status] ?? ['Something went wrong', 'Please try again.'];

            return $this->page($request, $e->status, $title, $text, $e->headers);
        } catch (\Throwable $e) {
            error_log(sprintf('[mama-mboga] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $detail = $this->config->debug ? $e::class . ': ' . $e->getMessage() : '';

            return $this->page($request, 500, 'Something went wrong', 'We could not complete that. Nothing was lost, please try again in a moment.', [], $detail);
        }
    }

    /** @param array<string, string> $headers */
    private function page(Request $request, int $status, string $title, string $text, array $headers, string $detail = ''): Response
    {
        $html = $this->pages->render($request, 'public/error', ['status' => $status, 'title' => $title, 'text' => $text, 'detail' => $detail]);
        $response = Response::html($html, $status)->withHeader('Cache-Control', 'no-store');
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
