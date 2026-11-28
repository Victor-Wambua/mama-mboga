<?php

declare(strict_types=1);

namespace MamaMboga;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\GatewayClient;
use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Application\Port\PaymentRequestRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Application\Port\ShoppingCheckRepository;
use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Container\Container;
use MamaMboga\Infrastructure\Gateway\CurlGatewayClient;
use MamaMboga\Infrastructure\Gateway\UnconfiguredGatewayClient;
use MamaMboga\Infrastructure\Http\Kernel;
use MamaMboga\Infrastructure\Http\Middleware\Csrf;
use MamaMboga\Infrastructure\Http\Middleware\ErrorHandler;
use MamaMboga\Infrastructure\Http\Middleware\SecurityHeaders;
use MamaMboga\Infrastructure\Http\Middleware\SessionMiddleware;
use MamaMboga\Infrastructure\Http\Router;
use MamaMboga\Infrastructure\Persistence\Database;
use MamaMboga\Infrastructure\Persistence\Migrator;
use MamaMboga\Infrastructure\Persistence\SqliteCustomerRepository;
use MamaMboga\Infrastructure\Persistence\SqliteLedgerRepository;
use MamaMboga\Infrastructure\Persistence\SqliteOrderRepository;
use MamaMboga\Infrastructure\Persistence\SqlitePaymentRequestRepository;
use MamaMboga\Infrastructure\Persistence\SqlitePriceBoardRepository;
use MamaMboga\Infrastructure\Persistence\SqliteSettingsRepository;
use MamaMboga\Infrastructure\Persistence\SqliteShoppingCheckRepository;
use MamaMboga\Infrastructure\Persistence\SqliteSlotRepository;
use MamaMboga\Infrastructure\Security\UrlSigner;
use MamaMboga\Infrastructure\Time\SystemClock;
use MamaMboga\Infrastructure\View\View;

/** Composition root: the one place that knows which implementation backs each port. */
final class App
{
    public static function container(Config $config, ?Clock $clock = null): Container
    {
        $c = new Container();
        $c->instance(Config::class, $config);
        $c->instance(Clock::class, $clock ?? new SystemClock($config->timezone));
        $c->bind(Database::class, static fn (): Database => Database::connect($config->dbPath));
        $c->bind(Transaction::class, static fn (Container $c): Database => $c->get(Database::class));
        $c->bind(Migrator::class, static fn (Container $c): Migrator => new Migrator($c->get(Database::class), $config->root . '/migrations'));

        $c->bind(PriceBoardRepository::class, SqlitePriceBoardRepository::class);
        $c->bind(SlotRepository::class, SqliteSlotRepository::class);
        $c->bind(OrderRepository::class, SqliteOrderRepository::class);
        $c->bind(CustomerRepository::class, SqliteCustomerRepository::class);
        $c->bind(LedgerRepository::class, SqliteLedgerRepository::class);
        $c->bind(PaymentRequestRepository::class, SqlitePaymentRequestRepository::class);
        $c->bind(GatewayClient::class, static fn (): GatewayClient => $config->gatewayUrl !== '' && $config->gatewaySecretKey !== ''
            ? new CurlGatewayClient($config->gatewayUrl, $config->gatewaySecretKey)
            : new UnconfiguredGatewayClient());
        $c->bind(SettingsRepository::class, SqliteSettingsRepository::class);
        $c->bind(ShoppingCheckRepository::class, SqliteShoppingCheckRepository::class);

        $c->bind(UrlSigner::class, static fn (): UrlSigner => new UrlSigner($config->appKey));
        $c->bind(View::class, static fn (): View => new View($config->root . '/templates'));

        $c->bind(Router::class, static function () use ($config): Router {
            $router = new Router();
            /** @var callable(Router): void $routes */
            $routes = require $config->root . '/config/routes.php';
            $routes($router);

            return $router;
        });

        return $c;
    }

    public static function kernel(Container $container): Kernel
    {
        return new Kernel($container, $container->get(Router::class), [
            SecurityHeaders::class,
            SessionMiddleware::class,
            ErrorHandler::class,
            Csrf::class,
        ]);
    }
}
