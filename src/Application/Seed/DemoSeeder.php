<?php

declare(strict_types=1);

namespace MamaMboga\Application\Seed;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Domain\Catalogue\Item;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\Ledger;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;

/**
 * Loads "Mama Njeri Greens": a fictional shop, synthetic customers and a week
 * of orders. Deterministic (fixed seed) so screenshots and tests repeat.
 * All names and numbers are made up; the 0700 000 0xx range is not assigned.
 */
final class DemoSeeder
{
    public const DEMO_PIN = '246810';

    private const CUSTOMERS = [
        ['Wanjiru Kamau', 5000], ['Otieno Odhiambo', 3000], ['Achieng Atieno', 0], ['Mwangi Njoroge', 4000],
        ['Chebet Kiptoo', 0], ['Hassan Mohamed', 2000], ['Faith Mutheu', 0], ['Baraka Mwakio', 0],
        ['Njeri Gathoni', 3000], ['Kevin Omondi', 0], ['Amina Yusuf', 0], ['Peter Kariuki', 0],
    ];

    public function __construct(
        private readonly Transaction $tx,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly PriceBoardRepository $board,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
        private readonly LedgerRepository $ledger,
        private readonly SlotPlanner $planner,
    ) {
    }

    /** @return array{items: int, customers: int, orders: int, ledger: int} */
    public function seed(): array
    {
        return $this->tx->run(function (): array {
            mt_srand(20260312);
            $now = $this->clock->now();

            $this->settings->set('shop_name', 'Mama Njeri Greens');
            $this->settings->set('shop_tagline', 'Fresh from Wakulima market, priced every morning.');
            $this->settings->set('shop_whatsapp', '254700000001');
            $this->settings->set('default_slot_capacity', '6');
            $this->settings->set('owner_pin_hash', password_hash(self::DEMO_PIN, PASSWORD_DEFAULT));

            $items = [];
            foreach (DemoCatalogue::items() as $row) {
                $items[$row['key']] = $this->board->saveItem(new Item(null, $row['en'], $row['sw'], $row['aliases'], $row['unit']));
            }

            $customers = [];
            foreach (self::CUSTOMERS as $i => [$name, $limit]) {
                $customers[] = $this->customers->save(new Customer(null, $name, PhoneNumber::parse(sprintf('0700 000 %03d', 10 + $i)), Money::kes($limit)));
            }

            $orderCount = 0;
            for ($back = 6; $back >= 0; $back--) {
                $day = $now->modify("-{$back} days");
                $this->seedDay($day, $back === 0, $items, $customers, $orderCount);
            }

            $ledgerCount = $this->seedLedger($customers, $now);

            return ['items' => count($items), 'customers' => count($customers), 'orders' => $orderCount, 'ledger' => $ledgerCount];
        });
    }

    /**
     * @param array<string, Item> $items
     * @param list<Customer>      $customers
     */
    private function seedDay(\DateTimeImmutable $day, bool $isToday, array $items, array $customers, int &$orderCount): void
    {
        $date = $day->format('Y-m-d');
        $dayNumber = (int) $day->format('z');

        // The list changes a little every morning.
        foreach (DemoCatalogue::items() as $i => $row) {
            $skip = !$isToday && (($i * 7 + $dayNumber) % 6 === 0);
            if ($skip) {
                continue;
            }
            $jitter = 100 + (($i * 13 + $dayNumber * 5) % 21) - 10; // 90..110 %
            $price = max(5, (int) (round($row['price'] * $jitter / 100 / 5) * 5));
            $special = $isToday && isset($row['special']);
            $this->board->saveEntry($date, (int) $items[$row['key']]->id, $row['unit'], Money::kes($price), Quantity::units($row['qty']), $special, $row['special'] ?? '');
        }
        $slots = $this->planner->ensure($date);
        $stock = array_values(array_filter($this->board->board($date), static fn (StockPrice $s): bool => $s->entryId !== null));

        $count = $isToday ? 9 : 6 + ($dayNumber % 3);
        for ($n = 0; $n < $count; $n++) {
            $customer = $customers[mt_rand(0, count($customers) - 1)];
            $picked = array_rand($stock, mt_rand(2, 4));

            $lines = [];
            foreach ($picked as $index) {
                $entry = $stock[$index];
                $qty = $entry->unit->value === 'kg' ? Quantity::milli(mt_rand(1, 6) * 500) : Quantity::units(mt_rand(1, 5));
                $lines[] = $entry->lineFor($qty);
                $this->board->adjustAvailable((int) $entry->entryId, -$qty->milli);
            }

            $pickup = mt_rand(0, 5) === 0;
            $slot = $slots[$n % count($slots)];
            $deni = $customer->hasCredit() && mt_rand(0, 2) > 0;
            $status = $this->statusFor($isToday, $n);
            $createdAt = $day->setTime(6, 40 + $n * 6);

            $order = new Order(
                null,
                self::reference($orderCount + 1),
                $date,
                $customer->id,
                $customer->name,
                $customer->phone,
                $pickup ? Fulfilment::Pickup : Fulfilment::Delivery,
                $pickup ? null : $slot->id,
                $pickup ? '' : ['Kasarani, Mwiki Rd', 'Roysambu, Zimmerman', 'Ruaka, Kiambu Rd', 'Githurai 45', 'Umoja 1, Kangundo Rd', 'Kileleshwa, Githunguri Rd'][mt_rand(0, 5)],
                '',
                $status,
                $deni ? PaymentMode::Deni : PaymentMode::PayOnDelivery,
                $status === OrderStatus::Delivered ? ($deni ? 'deni' : (mt_rand(0, 1) === 1 ? 'mpesa' : 'cash')) : null,
                $lines,
                mt_rand(0, 2) === 0 ? 'whatsapp' : 'web',
                $createdAt,
            );
            $this->orders->insert($order);
            $orderCount++;
        }
    }

    /** Distinct, readable-looking references: a bijection of the order number onto 4 characters. */
    private static function reference(int $number): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $space = strlen($alphabet) ** 4;
        $value = ($number * 977 + 12345) % $space;
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code = $alphabet[$value % 31] . $code;
            $value = intdiv($value, 31);
        }

        return 'MM-' . $code;
    }

    private function statusFor(bool $isToday, int $n): OrderStatus
    {
        if (!$isToday) {
            return $n % 11 === 10 ? OrderStatus::Cancelled : OrderStatus::Delivered;
        }

        return match (true) {
            $n === 0 => OrderStatus::Delivered,
            $n === 1 => OrderStatus::Out,
            $n === 2, $n === 3 => OrderStatus::Packing,
            $n === 8 => OrderStatus::Cancelled,
            default => OrderStatus::Received,
        };
    }

    /**
     * Charge every delivered deni order, add one old notebook balance for
     * aging, and record a few M-Pesa and cash payments.
     *
     * @param list<Customer> $customers
     */
    private function seedLedger(array $customers, \DateTimeImmutable $now): int
    {
        $written = 0;
        foreach ($customers as $customer) {
            if (!$customer->hasCredit()) {
                continue;
            }
            $customerId = (int) $customer->id;
            $book = Ledger::open($customerId, $customer->creditLimit);
            $entries = [];

            if ($customerId % 2 === 1) {
                $entries[] = new LedgerEntry(null, $customerId, EntryType::Charge, Money::kes(900 + $customerId * 100), $now->modify('-26 days')->setTime(18, 0), null, null, null, 'Notebook balance carried over');
            }
            foreach ($this->orders->forCustomer($customerId, 100) as $order) {
                if ($order->settledVia === 'deni') {
                    $entries[] = new LedgerEntry(null, $customerId, EntryType::Charge, $order->total(), $order->createdAt->setTime(18, 30), null, null, $order->id, "Order {$order->reference}");
                }
            }
            usort($entries, static fn (LedgerEntry $a, LedgerEntry $b): int => $a->occurredAt <=> $b->occurredAt);

            $sequence = 0;
            foreach ($entries as $entry) {
                $book = $this->tryAppend($book, $entry, $written);
                if (++$sequence % 3 === 0 && $customerId % 2 === 0 && $book->balance()->amount > 400) {
                    $pay = Money::kes((int) (intdiv($book->balance()->amount, 2) / 50) * 50);
                    $code = 'SGH' . strtoupper(substr(md5($customerId . '-' . $sequence), 0, 7));
                    $book = $this->tryAppend($book, new LedgerEntry(null, $customerId, EntryType::Payment, $pay, $entry->occurredAt->modify('+1 day'), 'mpesa', $code, null, 'M-Pesa'), $written);
                }
            }
        }

        return $written;
    }

    private function tryAppend(Ledger $book, LedgerEntry $entry, int &$written): Ledger
    {
        try {
            $next = $book->append($entry);
        } catch (\DomainException) {
            return $book; // would break an invariant: simply not part of the sample data
        }
        $this->ledger->append($entry);
        $written++;

        return $next;
    }
}
