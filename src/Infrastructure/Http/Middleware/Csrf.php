<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http\Middleware;

use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\Session;

/**
 * Every state-changing request must carry the session's token, as a form field
 * (_csrf) or an X-CSRF-Token header. Compared in constant time.
 */
final class Csrf implements Middleware
{
    public const FIELD = '_csrf';

    public static function token(Session $session): string
    {
        $token = $session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->put('_csrf', $token);
        }

        return $token;
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = $request->attribute('session');
        if (!$session instanceof Session) {
            throw new \LogicException('Csrf middleware needs SessionMiddleware before it.');
        }

        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $expected = $session->get('_csrf');
            $given = $request->input(self::FIELD, $request->header('x-csrf-token'));
            if (!is_string($expected) || $expected === '' || $given === '' || !hash_equals($expected, $given)) {
                throw new HttpException(419, 'Page expired');
            }
        }

        return $next($request);
    }
}
