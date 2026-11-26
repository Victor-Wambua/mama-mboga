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
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    /** @param list<OrderLine>|null $lines */
    private function order(?array $lines = null, Fulfilment $f = Fulfilment::Delivery, ?int $slot = 1, string $address = 'Kasarani', OrderStatus $s = OrderStatus::Received): Order
    {
        return new Order(
            null,
            'MM-TEST',
            '2026-03-12',
            null,
            'Wanjiru',
            PhoneNumber::parse('0700000001'),
            $f,
            $slot,
            $address,
            '',
            $s,
            PaymentMode::PayOnDelivery,
            null,
            $lines ?? [new OrderLine(1, 'Tomatoes', 'Nyanya', Unit::Kg, Quantity::milli(1500), Money::kes(120)), new OrderLine(2, 'Kale', 'Sukuma', Unit::Bunch, Quantity::units(3), Money::kes(20))],
            'web',
            new \DateTimeImmutable('2026-03-12 07:00'),
        );
    }

    public function testTotalSumsLineTotals(): void
    {
        self::assertSame(180 + 60, $this->order()->total()->amount);
    }

    public function testNeedsAtLeastOneLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->order([]);
    }

    public function testDeliveryNeedsSlotAndAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->order(null, Fulfilment::Delivery, null);
    }

    public function testDeliveryNeedsAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->order(null, Fulfilment::Delivery, 1, '  ');
    }

    public function testPickupNeedsNeither(): void
    {
        $order = $this->order(null, Fulfilment::Pickup, null, '');
        self::assertSame(Fulfilment::Pickup, $order->fulfilment);
    }

    public function testStatusFlowHappyPath(): void
    {
        $order = $this->order();
        foreach ([OrderStatus::Packing, OrderStatus::Out, OrderStatus::Delivered] as $next) {
            $order = $order->transitionTo($next);
        }
        self::assertSame(OrderStatus::Delivered, $order->status);
    }

    public function testCannotSkipOrGoBack(): void
    {
        $this->expectException(\DomainException::class);
        $this->order()->transitionTo(OrderStatus::Delivered);
    }

    public function testFinalStatesAreFinal(): void
    {
        foreach ([OrderStatus::Delivered, OrderStatus::Cancelled] as $final) {
            self::assertTrue($final->isFinal());
            self::assertFalse($final->canMoveTo(OrderStatus::Packing));
        }
    }

    public function testCancelFromAnyOpenState(): void
    {
        foreach ([OrderStatus::Received, OrderStatus::Packing, OrderStatus::Out] as $open) {
            self::assertTrue($open->canMoveTo(OrderStatus::Cancelled));
        }
    }

    public function testOnlyReceivedAndPackingNeedBuying(): void
    {
        self::assertTrue(OrderStatus::Received->needsBuying());
        self::assertTrue(OrderStatus::Packing->needsBuying());
        self::assertFalse(OrderStatus::Out->needsBuying());
        self::assertFalse(OrderStatus::Cancelled->needsBuying());
    }

    public function testStatusLabelsFollowFulfilment(): void
    {
        self::assertSame('Out for delivery', OrderStatus::Out->label(Fulfilment::Delivery));
        self::assertSame('Ready', OrderStatus::Out->label(Fulfilment::Pickup));
        self::assertSame('Collected', OrderStatus::Delivered->label(Fulfilment::Pickup));
    }
}
