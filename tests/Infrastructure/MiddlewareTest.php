<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Infrastructure;

use MamaMboga\Infrastructure\Container\Container;
use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Kernel;
use MamaMboga\Infrastructure\Http\Middleware;
use MamaMboga\Infrastructure\Http\Middleware\Authenticate;
use MamaMboga\Infrastructure\Http\Middleware\Csrf;
use MamaMboga\Infrastructure\Http\Middleware\RateLimit;
use MamaMboga\Infrastructure\Http\Middleware\SecurityHeaders;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Http\Router;
use MamaMboga\Infrastructure\Security\RateLimiter;
use MamaMboga\Infrastructure\Security\Session;
use MamaMboga\Tests\Support\FixedClock;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class Trace implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $name = 'x')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        self::$log[] = 'in:' . $this->name;
        $response = $next($request);
        self::$log[] = 'out:' . $this->name;

        return $response->withAddedHeader('X-Trace', $this->name);
    }
}

final class Ping
{
    public function pong(Request $request): Response
    {
        Trace::$log[] = 'controller';

        return Response::text('pong:' . $request->param('n'));
    }
}

final class MiddlewareTest extends TestCase
{
    public function testMiddlewareWrapsOnionStyleInListedOrder(): void
    {
        Trace::$log = [];
        $router = new Router();
        $router->get('/ping/{n}', [Ping::class, 'pong'], [Trace::class . ':inner']);
        $kernel = new Kernel(new Container(), $router, [Trace::class . ':outer']);

        $response = $kernel->handle(Request::create('GET', '/ping/7'));

        self::assertSame('pong:7', $response->body);
        self::assertSame(['in:outer', 'in:inner', 'controller', 'out:inner', 'out:outer'], Trace::$log);
        self::assertSame(['inner', 'outer'], $response->headerValues('X-Trace'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $router = new Router();
        $router->get('/ping/{n}', [Ping::class, 'pong']);
        $kernel = new Kernel(new Container(), $router, [Authenticate::class]);

        $session = new Session('s');
        $response = (new Authenticate())->handle(Request::create('GET', '/owner/x')->withAttribute('session', $session), static fn (): Response => Response::text('secret'));

        self::assertSame(303, $response->status);
        self::assertSame('/owner/login?next=%2Fowner%2Fx', $response->header('Location'));
        unset($kernel);
    }

    public function testAuthenticatePassesOwnersAndMarksResponsesNoStore(): void
    {
        $session = new Session('s');
        $session->put('owner', true);

        $response = (new Authenticate())->handle(Request::create('GET', '/owner')->withAttribute('session', $session), static fn (): Response => Response::text('ok'));

        self::assertSame('ok', $response->body);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function testAuthenticateDoesNotRedirectPostsToAGetTarget(): void
    {
        $response = (new Authenticate())->handle(Request::create('POST', '/owner/x')->withAttribute('session', new Session('s')), static fn (): Response => Response::text('no'));

        self::assertSame('/owner/login', $response->header('Location'));
    }

    public function testCsrfRejectsPostsWithoutAToken(): void
    {
        $session = new Session('s');
        $request = Request::create('POST', '/x', ['a' => 'b'])->withAttribute('session', $session);

        $this->expectException(HttpException::class);
        (new Csrf())->handle($request, static fn (): Response => Response::text('ok'));
    }

    public function testCsrfRejectsAWrongToken(): void
    {
        $session = new Session('s');
        Csrf::token($session);
        $request = Request::create('POST', '/x', ['_csrf' => str_repeat('a', 64)])->withAttribute('session', $session);

        try {
            (new Csrf())->handle($request, static fn (): Response => Response::text('ok'));
            self::fail();
        } catch (HttpException $e) {
            self::assertSame(419, $e->status);
        }
    }

    public function testCsrfAcceptsFormFieldOrHeader(): void
    {
        $session = new Session('s');
        $token = Csrf::token($session);
        $next = static fn (): Response => Response::text('ok');

        $form = Request::create('POST', '/x', ['_csrf' => $token])->withAttribute('session', $session);
        $header = Request::create('POST', '/x', [], ['X-CSRF-Token' => $token])->withAttribute('session', $session);

        self::assertSame('ok', (new Csrf())->handle($form, $next)->body);
        self::assertSame('ok', (new Csrf())->handle($header, $next)->body);
    }

    public function testCsrfDoesNotGuardSafeMethodsAndTokenIsStablePerSession(): void
    {
        $session = new Session('s');

        self::assertSame('ok', (new Csrf())->handle(Request::create('GET', '/x')->withAttribute('session', $session), static fn (): Response => Response::text('ok'))->body);
        self::assertSame(Csrf::token($session), Csrf::token($session));
        self::assertSame(64, strlen(Csrf::token($session)));
    }

    public function testCsrfFailsClosedWithoutASessionToken(): void
    {
        // A request whose session never issued a token must not pass with an empty one.
        $request = Request::create('POST', '/x', ['_csrf' => ''])->withAttribute('session', new Session('s'));

        $this->expectException(HttpException::class);
        (new Csrf())->handle($request, static fn (): Response => Response::text('ok'));
    }

    public function testSecurityHeaders(): void
    {
        $response = (new SecurityHeaders())->handle(Request::create('GET', '/'), static fn (): Response => Response::text('ok'));

        self::assertStringContainsString("default-src 'self'", (string) $response->header('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-inline', (string) $response->header('Content-Security-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertNull($response->header('Strict-Transport-Security'));
    }

    public function testHstsOnlyOverHttps(): void
    {
        $request = Request::create('GET', '/', server: ['HTTPS' => 'on']);

        self::assertNotNull((new SecurityHeaders())->handle($request, static fn (): Response => Response::text('ok'))->header('Strict-Transport-Security'));
    }

    public function testRateLimitAllowsUpToTheLimitThenBlocks(): void
    {
        $app = new TestApp();
        $limit = new RateLimit($app->container->get(RateLimiter::class), 'login', 3, 60);
        $next = static fn (): Response => Response::text('ok');
        $post = Request::create('POST', '/x', server: ['REMOTE_ADDR' => '9.9.9.9']);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame('ok', $limit->handle($post, $next)->body);
        }
        try {
            $limit->handle($post, $next);
            self::fail('fourth attempt should be blocked');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status);
            self::assertArrayHasKey('Retry-After', $e->headers);
        }
    }

    public function testRateLimitIsPerClientAndPerWindow(): void
    {
        $app = new TestApp();
        $limit = new RateLimit($app->container->get(RateLimiter::class), 'login', 1, 60);
        $next = static fn (): Response => Response::text('ok');

        $limit->handle(Request::create('POST', '/x', server: ['REMOTE_ADDR' => '1.1.1.1']), $next);
        self::assertSame('ok', $limit->handle(Request::create('POST', '/x', server: ['REMOTE_ADDR' => '2.2.2.2']), $next)->body);

        $app->clock->set('2026-03-12 07:31:30'); // next 60s window
        self::assertSame('ok', $limit->handle(Request::create('POST', '/x', server: ['REMOTE_ADDR' => '1.1.1.1']), $next)->body);
    }

    public function testRateLimitIgnoresGets(): void
    {
        $app = new TestApp();
        $limit = new RateLimit($app->container->get(RateLimiter::class), 'x', 1, 60);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('ok', $limit->handle(Request::create('GET', '/x'), static fn (): Response => Response::text('ok'))->body);
        }
        self::assertInstanceOf(FixedClock::class, $app->clock);
    }
}
