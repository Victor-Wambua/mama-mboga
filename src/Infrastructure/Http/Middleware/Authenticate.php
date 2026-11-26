<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http\Middleware;

use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\Session;

/** Guards the owner console: no owner flag in the session, no entry. */
final class Authenticate implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $session = $request->attribute('session');
        if ($session instanceof Session && $session->get('owner') === true) {
            return $next($request)->withHeader('Cache-Control', 'no-store');
        }

        $target = $request->method === 'GET' ? '?next=' . rawurlencode($request->path) : '';

        return Response::redirect('/owner/login' . $target);
    }
}
