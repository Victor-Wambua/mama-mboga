<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\App;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Domain\Catalogue\Item;
use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Container\Container;
use MamaMboga\Infrastructure\Http\Kernel;
use MamaMboga\Infrastructure\Persistence\Database;
use MamaMboga\Infrastructure\Persistence\Migrator;

/** A fully wired app on an in-memory SQLite database and a frozen clock (2026-03-12 07:30, Nairobi). */
final class TestApp
{
    public const PIN = 'test-pin-123';

    public Container $container;
    public FixedClock $clock;
    public Kernel $kernel;
    public Database $db;

    /** @var array<string, int> board entry ids by short key */
    public array $entries = [];

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        $config = new Config($root, 'testing', true, str_repeat('k', 40), ':memory:', 'Africa/Nairobi', '');
        $this->clock = new FixedClock();
        $this->container = App::container($config, $this->clock);
        $this->container->get(Migrator::class)->migrate();
        $this->db = $this->container->get(Database::class);
        $this->kernel = App::kernel($this->container);

        $settings = $this->container->get(SettingsRepository::class);
        $settings->set('shop_name', 'Test Greens');
        $settings->set('shop_whatsapp', '254700000001');
        $settings->set('owner_pin_hash', password_hash(self::PIN, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    /** Four items on today's board: kale (bunch), tomato (kg), onion (kg), banana (piece). */
    public function withBoard(): self
    {
        $repo = $this->container->get(PriceBoardRepository::class);
        $today = $this->clock->now()->format('Y-m-d');
        $rows = [
            'kale' => ['Kale', 'Sukuma wiki', ['sukuma'], Unit::Bunch, 20, 10],
            'tomato' => ['Tomatoes', 'Nyanya', [], Unit::Kg, 120, 5],
            'onion' => ['Onions', 'Vitunguu', [], Unit::Kg, 110, 20],
            'banana' => ['Bananas', 'Ndizi', [], Unit::Piece, 10, 30],
        ];
        foreach ($rows as $key => [$en, $sw, $aliases, $unit, $price, $qty]) {
            $item = $repo->saveItem(new Item(null, $en, $sw, $aliases, $unit));
            $repo->saveEntry($today, (int) $item->id, $unit, Money::kes($price), Quantity::units($qty), false, '');
            foreach ($repo->board($today) as $row) {
                if ($row->itemId === $item->id) {
                    $this->entries[$key] = (int) $row->entryId;
                }
            }
        }

        return $this;
    }

    public function customer(string $name, string $phone, int $limit = 0): Customer
    {
        return $this->container->get(CustomerRepository::class)->save(new Customer(null, $name, PhoneNumber::parse($phone), Money::kes($limit)));
    }

    public function client(): HttpClient
    {
        return new HttpClient($this->kernel);
    }

    public function ownerClient(): HttpClient
    {
        $client = $this->client();
        $client->login(self::PIN);

        return $client;
    }
}
