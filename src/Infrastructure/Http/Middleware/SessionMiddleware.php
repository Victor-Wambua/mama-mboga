<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http\Middleware;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\Session;
use MamaMboga\Infrastructure\Security\SessionStore;

/** Loads the visitor's session from its cookie and saves it again after the response is built. */
final class SessionMiddleware implements Middleware
{
    public const COOKIE = 'mm_session';
    private const TTL = 28800; // 8 hours

    public function __construct(private readonly SessionStore $store, private readonly Clock $clock)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $now = $this->clock->now()->getTimestamp();
        $cookie = $request->cookie(self::COOKIE);
        $oldId = null;

        $session = null;
        if ($cookie !== null && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1) {
            $session = $this->store->load($cookie, $now);
            $oldId = $cookie;
        }
        $session ??= new Session(Session::newId());

        $response = $next($request->withAttribute('session', $session));

        if ($session->isDestroyed()) {
            if ($oldId !== null) {
                $this->store->destroy($oldId);
            }

            return $response->withAddedHeader('Set-Cookie', $this->cookie('', 0, $request->isSecure()));
        }

        if ($session->isDirty() || (!$session->isNew() && $session->all() !== [])) {
            if ($oldId !== null && $oldId !== $session->id()) {
                $this->store->destroy($oldId); // regenerated: the old id must stop working
            }
            $this->store->save($session, $now + self::TTL);
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($session->id(), self::TTL, $request->isSecure()));
        }

        if (random_int(1, 100) === 1) {
            $this->store->collectGarbage($now);
        }

        return $response;
    }

    private function cookie(string $value, int $maxAge, bool $secure): string
    {
        return sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            self::COOKIE,
            $value,
            $maxAge,
            $secure ? '; Secure' : '',
        );
    }
}
