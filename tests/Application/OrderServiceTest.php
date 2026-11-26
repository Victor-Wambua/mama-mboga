<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Application;

use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private TestApp $app;
    private OrderService $orders;
    private int $evening;
    private int $morning;

    protected function setUp(): void
    {
        $this->app = (new TestApp())->withBoard();
        $this->orders = $this->app->container->get(OrderService::class);
        $slots = $this->app->container->get(SlotPlanner::class)->ensure('2026-03-12');
        $this->morning = (int) $slots[0]->id; // 10am-12pm
        $this->evening = (int) $slots[3]->id; // 6pm-8pm
    }

    /** @param array<string, string> $qty key => quantity */
    private function cmd(array $qty, ?int $slot = null, string $fulfilment = 'delivery', bool $deni = false, string $phone = '0700 000 010'): PlaceOrder
    {
        $quantities = [];
        foreach ($qty as $key => $value) {
            $quantities[$this->app->entries[$key]] = $value;
        }

        return new PlaceOrder('Wanjiru Kamau', $phone, $fulfilment, $fulfilment === 'delivery' ? ($slot ?? $this->evening) : null, 'Kasarani', '', $quantities, $deni);
    }

    private function stock(string $key): int
    {
        return $this->app->container->get(PriceBoardRepository::class)->entry($this->app->entries[$key])?->available->milli ?? -1;
    }

    /**
     * @param callable(): mixed $fn
     * @return array<string, string>
     */
    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('Expected a ValidationException');
    }

    public function testPlacesAnOrderAndTotalsIt(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '3', 'tomato' => '1.5']));

        self::assertSame(OrderStatus::Received, $order->status);
        self::assertSame(60 + 180, $order->total()->amount);
        self::assertMatchesRegularExpression('/^MM-[2-9A-HJKMNP-Z]{4}$/', $order->reference);
        self::assertNotNull($order->id);
        self::assertSame('254700000010', $order->customerPhone->e164);
    }

    public function testStockIsReservedAndRestoredOnCancel(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '4']));
        self::assertSame(6000, $this->stock('kale'));

        $this->orders->changeStatus((int) $order->id, OrderStatus::Cancelled);
        self::assertSame(10000, $this->stock('kale'));
    }

    public function testCannotOrderMoreThanIsLeft(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['tomato' => '5.5'])));

        self::assertStringContainsString('Only 5 kg of Tomatoes left', $errors['items']);
        self::assertSame(5000, $this->stock('tomato'));
    }

    public function testSoldOutItemIsRejected(): void
    {
        $this->orders->place($this->cmd(['tomato' => '5']));
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['tomato' => '1'], phone: '0700 000 011')));

        self::assertStringContainsString('sold out', $errors['items']);
    }

    public function testFailedOrderLeavesNoTrace(): void
    {
        $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '3', 'tomato' => '9'])));

        self::assertSame(10000, $this->stock('kale'), 'the valid line must not be reserved when another line fails');
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM orders'));
    }

    public function testBunchesMustBeWhole(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1.5'])));

        self::assertStringContainsString('whole', $errors['items']);
    }

    public function testKgMustBeInQuarterKiloSteps(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['tomato' => '0.3'])));

        self::assertStringContainsString('steps of 0.25', $errors['items']);
    }

    public function testValidationCollectsEveryFieldError(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place(new PlaceOrder('', '123', 'teleport', null, '', '', [])));

        self::assertEqualsCanonicalizing(['name', 'phone', 'fulfilment', 'items'], array_keys($errors));
    }

    public function testDeliveryNeedsAddressAndSlot(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place(new PlaceOrder('Wanjiru', '0700000010', 'delivery', null, '', '', [$this->app->entries['kale'] => '1'])));

        self::assertArrayHasKey('address', $errors);
        self::assertArrayHasKey('slot', $errors);
    }

    public function testPickupNeedsNoSlotOrAddress(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '1'], fulfilment: 'pickup'));

        self::assertNull($order->slotId);
        self::assertSame('', $order->address);
    }

    public function testSlotCapacityIsEnforced(): void
    {
        $this->app->container->get(SlotRepository::class)->setCapacity($this->evening, 2);
        $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 011'));
        $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 012'));

        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 013')));

        self::assertStringContainsString('full', $errors['slot']);
    }

    public function testCancellingFreesTheSlot(): void
    {
        $this->app->container->get(SlotRepository::class)->setCapacity($this->evening, 1);
        $first = $this->orders->place($this->cmd(['kale' => '1']));
        $this->orders->changeStatus((int) $first->id, OrderStatus::Cancelled);

        $second = $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 011'));

        self::assertSame($this->evening, $second->slotId);
    }

    public function testSlotThatHasStartedCannotBeBooked(): void
    {
        $this->app->clock->set('2026-03-12 10:10:00');
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1'], $this->morning)));

        self::assertArrayHasKey('slot', $errors);
    }

    public function testSlotFromAnotherDayIsRejected(): void
    {
        $this->app->container->get(SlotRepository::class)->ensureDefaults('2026-03-13', [['10:00', '12:00', 5]]);
        $tomorrow = $this->app->container->get(SlotRepository::class)->forDate('2026-03-13')[0];

        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1'], (int) $tomorrow->id)));

        self::assertArrayHasKey('slot', $errors);
    }

    public function testItemNotOnTodaysBoardIsRejected(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place(new PlaceOrder('Wanjiru', '0700000010', 'pickup', null, '', '', [99999 => '1'])));

        self::assertStringContainsString('no longer', $errors['items']);
    }

    public function testRepeatCustomerReusesTheirRecord(): void
    {
        $this->orders->place($this->cmd(['kale' => '1']));
        $this->orders->place($this->cmd(['kale' => '1'], phone: '+254700000010'));

        self::assertSame(1, $this->app->db->scalar('SELECT COUNT(*) FROM customers'));
    }

    public function testDeniNeedsACreditLimit(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1'], deni: true)));

        self::assertArrayHasKey('payment', $errors);
    }

    public function testDeniRespectsTheLimitIncludingUnchargedOrders(): void
    {
        $this->app->customer('Wanjiru Kamau', '0700 000 010', 500);

        $first = $this->orders->place($this->cmd(['tomato' => '3'], deni: true)); // 360
        self::assertSame(PaymentMode::Deni, $first->paymentMode);

        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['tomato' => '2'], deni: true))); // +240 > 500
        self::assertStringContainsString('deni limit', $errors['payment']);
    }

    public function testDeliveredDeniOrderIsChargedToTheLedgerOnce(): void
    {
        $customer = $this->app->customer('Wanjiru Kamau', '0700 000 010', 5000);
        $order = $this->orders->place($this->cmd(['tomato' => '2'], deni: true));

        foreach ([OrderStatus::Packing, OrderStatus::Out, OrderStatus::Delivered] as $to) {
            $order = $this->orders->changeStatus((int) $order->id, $to);
        }

        $ledger = $this->app->container->get(LedgerService::class)->ledgerFor((int) $customer->id);
        self::assertSame(240, $ledger->balance()->amount);
        self::assertSame('deni', $order->settledVia);
        self::assertCount(1, $ledger->entries);
    }

    public function testCancelledDeniOrderIsNeverCharged(): void
    {
        $customer = $this->app->customer('Wanjiru Kamau', '0700 000 010', 5000);
        $order = $this->orders->place($this->cmd(['tomato' => '2'], deni: true));
        $this->orders->changeStatus((int) $order->id, OrderStatus::Cancelled);

        self::assertSame(0, $this->app->container->get(LedgerService::class)->ledgerFor((int) $customer->id)->balance()->amount);
    }

    public function testPayOnDeliveryRecordsHowItWasPaid(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '1']));
        foreach ([OrderStatus::Packing, OrderStatus::Out] as $to) {
            $this->orders->changeStatus((int) $order->id, $to);
        }
        $done = $this->orders->changeStatus((int) $order->id, OrderStatus::Delivered, 'mpesa');

        self::assertSame('mpesa', $done->settledVia);
    }

    public function testIllegalTransitionIsAValidationError(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '1']));
        $errors = $this->errorsOf(fn () => $this->orders->changeStatus((int) $order->id, OrderStatus::Delivered));

        self::assertArrayHasKey('status', $errors);
    }

    public function testDeliveredOrderCannotBeCancelled(): void
    {
        $order = $this->orders->place($this->cmd(['kale' => '1']));
        foreach ([OrderStatus::Packing, OrderStatus::Out, OrderStatus::Delivered] as $to) {
            $this->orders->changeStatus((int) $order->id, $to);
        }

        $this->errorsOf(fn () => $this->orders->changeStatus((int) $order->id, OrderStatus::Cancelled));
        self::assertSame(OrderStatus::Delivered, $this->orders->find((int) $order->id)?->status);
    }

    public function testTheLastBunchGoesToOnlyOneCustomer(): void
    {
        $repo = $this->app->container->get(PriceBoardRepository::class);
        $repo->adjustAvailable($this->app->entries['kale'], -9000); // one bunch left

        $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 011'));
        $errors = $this->errorsOf(fn () => $this->orders->place($this->cmd(['kale' => '1'], phone: '0700 000 012')));

        self::assertArrayHasKey('items', $errors);
        self::assertSame(0, $this->stock('kale'));
    }

    public function testStockCannotGoNegativeAtTheDatabase(): void
    {
        $repo = $this->app->container->get(PriceBoardRepository::class);

        self::assertFalse($repo->adjustAvailable($this->app->entries['kale'], -10001));
        self::assertTrue($repo->adjustAvailable($this->app->entries['kale'], -10000));
        self::assertSame(0, $this->stock('kale'));
    }

    public function testOrdersLineSnapshotKeepsThePriceItWasBoughtAt(): void
    {
        $order = $this->orders->place($this->cmd(['tomato' => '1']));
        $this->app->db->execute('UPDATE board_entries SET price_kes = 999 WHERE id = ?', [$this->app->entries['tomato']]);

        self::assertSame(120, $this->orders->find((int) $order->id)?->total()->amount);
    }
}
