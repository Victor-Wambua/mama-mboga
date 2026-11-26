<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderLine;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;
use MamaMboga\Domain\Shopping\ShoppingList;
use PHPUnit\Framework\TestCase;

final class ShoppingListTest extends TestCase
{
    /** @param list<array{int, Unit, int}> $lines item id, unit, milli */
    private function order(int $id, OrderStatus $status, array $lines): Order
    {
        $names = [1 => 'Tomatoes', 2 => 'Kale', 3 => 'Onions'];

        return new Order(
            $id,
            "MM-{$id}",
            '2026-03-12',
            null,
            'X',
            PhoneNumber::parse('0700000001'),
            Fulfilment::Pickup,
            null,
            '',
            '',
            $status,
            PaymentMode::PayOnDelivery,
            null,
            array_map(static fn (array $l): OrderLine => new OrderLine($l[0], $names[$l[0]], 'sw', $l[1], Quantity::milli($l[2]), Money::kes(10)), $lines),
            'web',
            new \DateTimeImmutable('2026-03-12 07:00'),
        );
    }

    public function testAddsQuantitiesAcrossOrders(): void
    {
        $list = ShoppingList::fromOrders([
            $this->order(1, OrderStatus::Received, [[1, Unit::Kg, 1500], [2, Unit::Bunch, 2000]]),
            $this->order(2, OrderStatus::Packing, [[1, Unit::Kg, 2500]]),
        ]);

        self::assertCount(2, $list);
        self::assertSame('Kale', $list[0]->nameEn);
        self::assertSame('2', $list[0]->quantity->decimal());
        self::assertSame('Tomatoes', $list[1]->nameEn);
        self::assertSame('4', $list[1]->quantity->decimal());
        self::assertSame(2, $list[1]->orderCount);
    }

    public function testIgnoresOrdersAlreadyBoughtOrCancelled(): void
    {
        $list = ShoppingList::fromOrders([
            $this->order(1, OrderStatus::Out, [[1, Unit::Kg, 1000]]),
            $this->order(2, OrderStatus::Delivered, [[1, Unit::Kg, 1000]]),
            $this->order(3, OrderStatus::Cancelled, [[1, Unit::Kg, 1000]]),
            $this->order(4, OrderStatus::Received, [[3, Unit::Kg, 500]]),
        ]);

        self::assertCount(1, $list);
        self::assertSame('Onions', $list[0]->nameEn);
    }

    public function testSameItemInDifferentUnitsStaysSeparate(): void
    {
        $list = ShoppingList::fromOrders([$this->order(1, OrderStatus::Received, [[1, Unit::Kg, 1000], [1, Unit::Heap, 2000]])]);

        self::assertCount(2, $list);
        self::assertNotSame($list[0]->key(), $list[1]->key());
    }

    public function testFractionsAddExactly(): void
    {
        $list = ShoppingList::fromOrders([
            $this->order(1, OrderStatus::Received, [[1, Unit::Kg, 250]]),
            $this->order(2, OrderStatus::Received, [[1, Unit::Kg, 250]]),
            $this->order(3, OrderStatus::Received, [[1, Unit::Kg, 750]]),
        ]);

        self::assertSame('1.25', $list[0]->quantity->decimal());
    }

    public function testEmpty(): void
    {
        self::assertSame([], ShoppingList::fromOrders([]));
    }
}
