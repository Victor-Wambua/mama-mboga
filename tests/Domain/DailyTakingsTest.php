<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Domain\Order\DailyTakings;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderLine;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;
use PHPUnit\Framework\TestCase;

final class DailyTakingsTest extends TestCase
{
    private function order(OrderStatus $status, int $kes, ?string $via): Order
    {
        return new Order(null, 'MM-X', '2026-03-12', null, 'X', PhoneNumber::parse('0700000001'), Fulfilment::Pickup, null, '', '', $status, $via === 'deni' ? PaymentMode::Deni : PaymentMode::PayOnDelivery, $via, [new OrderLine(1, 'A', 'B', Unit::Piece, Quantity::units(1), Money::kes($kes))], 'web', new \DateTimeImmutable());
    }

    public function testSummarisesTheDay(): void
    {
        $t = DailyTakings::summarise([
            $this->order(OrderStatus::Delivered, 500, 'cash'),
            $this->order(OrderStatus::Delivered, 300, 'mpesa'),
            $this->order(OrderStatus::Delivered, 700, 'deni'),
            $this->order(OrderStatus::Cancelled, 900, null),
            $this->order(OrderStatus::Packing, 100, null),
        ], [
            new LedgerEntry(1, 1, EntryType::Payment, Money::kes(250), new \DateTimeImmutable(), 'cash', 'CASH-1'),
            new LedgerEntry(2, 1, EntryType::Payment, Money::kes(400), new \DateTimeImmutable(), 'mpesa', 'SGH1234567'),
            new LedgerEntry(3, 1, EntryType::Charge, Money::kes(700), new \DateTimeImmutable()),
        ]);

        self::assertSame(3, $t->delivered);
        self::assertSame(1, $t->cancelled);
        self::assertSame(1, $t->open);
        self::assertSame(1500, $t->sales->amount);
        self::assertSame(700, $t->onDeni->amount);
        self::assertSame(750, $t->cashInHand()->amount);
        self::assertSame(700, $t->mpesaReceived()->amount);
        self::assertSame(1450, $t->totalReceived()->amount);
    }

    public function testEmptyDay(): void
    {
        $t = DailyTakings::summarise([], []);
        self::assertSame(0, $t->totalReceived()->amount);
        self::assertSame(0, $t->delivered);
    }
}
