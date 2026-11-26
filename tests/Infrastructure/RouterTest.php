<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Infrastructure;

use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', [\stdClass::class, 'home']);
        $this->router->get('/orders/{id:\d+}', [\stdClass::class, 'show']);
        $this->router->post('/orders/{id:\d+}/status', [\stdClass::class, 'status']);
        $this->router->get('/s/{id}/{sig:[A-Za-z0-9_-]+}', [\stdClass::class, 'statement']);
        $this->router->group('/owner', ['Auth'], function (Router $r): void {
            $r->get('', [\stdClass::class, 'dash']);
            $r->get('/board', [\stdClass::class, 'board'], ['Extra']);
        });
    }

    public function testMatchesStaticRoute(): void
    {
        $m = $this->router->match('GET', '/');

        self::assertSame('home', $m['route']->handler[1]);
        self::assertSame([], $m['params']);
    }

    public function testExtractsParameters(): void
    {
        $m = $this->router->match('GET', '/orders/42');

        self::assertSame(['id' => '42'], $m['params']);
    }

    public function testParameterConstraintIsEnforced(): void
    {
        $this->expectException(HttpException::class);
        $this->router->match('GET', '/orders/abc');
    }

    public function testMultipleParametersAndDecoding(): void
    {
        $m = $this->router->match('GET', '/s/a%20b/tok_en-1');

        self::assertSame(['id' => 'a b', 'sig' => 'tok_en-1'], $m['params']);
    }

    public function testUnknownPathIs404(): void
    {
        try {
            $this->router->match('GET', '/nope');
            self::fail();
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        try {
            $this->router->match('GET', '/orders/1/status');
            self::fail();
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST', $e->headers['Allow']);
        }
    }

    public function testHeadIsServedByGetRoutes(): void
    {
        self::assertSame('home', $this->router->match('HEAD', '/')['route']->handler[1]);
    }

    public function testTrailingSlashIsTolerated(): void
    {
        self::assertSame('board', $this->router->match('GET', '/owner/board/')['route']->handler[1]);
    }

    public function testGroupsAddPrefixAndMiddleware(): void
    {
        self::assertSame(['Auth'], $this->router->match('GET', '/owner')['route']->middleware);
        self::assertSame(['Auth', 'Extra'], $this->router->match('GET', '/owner/board')['route']->middleware);
    }

    public function testGroupMiddlewareDoesNotLeakOut(): void
    {
        self::assertSame([], $this->router->match('GET', '/')['route']->middleware);
    }

    public function testStaticPartsAreEscapedInTheRegex(): void
    {
        $router = new Router();
        $router->get('/a.b', [\stdClass::class, 'x']);

        $this->expectException(HttpException::class);
        $router->match('GET', '/aXb');
    }
}
