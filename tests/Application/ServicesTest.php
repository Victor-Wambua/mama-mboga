<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Application;

use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\PriceBoardService;
use MamaMboga\Application\Service\ShoppingListService;
use MamaMboga\Application\Service\TakingsService;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = (new TestApp())->withBoard();
    }

    private function ledger(): LedgerService
    {
        return $this->app->container->get(LedgerService::class);
    }

    /** @param callable(): mixed $fn */
    private function fails(callable $fn, string $field): void
    {
        try {
            $fn();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors);
        }
    }

    public function testCopyPreviousBringsPricesForwardWithoutSpecials(): void
    {
        $board = $this->app->container->get(PriceBoardService::class);
        $repo = $this->app->container->get(PriceBoardRepository::class);
        $board->setEntry('2026-03-12', $repo->board('2026-03-12')[0]->itemId, 'bunch', '25', '10', true, 'sweet');

        $copied = $board->copyPrevious('2026-03-13');

        self::assertSame(4, $copied);
        $next = $repo->board('2026-03-13');
        self::assertCount(4, $next);
        foreach ($next as $row) {
            self::assertFalse($row->special);
        }
    }

    public function testCopyPreviousSkipsItemsAlreadyOnTheBoard(): void
    {
        $board = $this->app->container->get(PriceBoardService::class);
        $board->copyPrevious('2026-03-13');

        self::assertSame(0, $board->copyPrevious('2026-03-13'));
    }

    public function testCopyWithNothingEarlierIsAnError(): void
    {
        $board = $this->app->container->get(PriceBoardService::class);

        $this->fails(fn () => $board->copyPrevious('2026-03-01'), 'copy');
    }

    public function testBoardEntryValidation(): void
    {
        $board = $this->app->container->get(PriceBoardService::class);
        $item = $board->items()[0];

        $this->fails(fn () => $board->setEntry('2026-03-12', (int) $item->id, 'kg', '12.5', '3', false, ''), 'price');
        $this->fails(fn () => $board->setEntry('2026-03-12', (int) $item->id, 'kg', '0', '3', false, ''), 'price');
        $this->fails(fn () => $board->setEntry('2026-03-12', (int) $item->id, 'litre', '10', '3', false, ''), 'unit');
        $this->fails(fn () => $board->setEntry('2026-03-12', (int) $item->id, 'bunch', '10', '2.5', false, ''), 'available');
        $this->fails(fn () => $board->setEntry('2026-03-12', (int) $item->id, 'kg', '10', 'lots', false, ''), 'available');
        $this->fails(fn () => $board->setEntry('2026-03-12', 424242, 'kg', '10', '3', false, ''), 'item');
    }

    public function testItemNamesAreValidatedAndAliasesSplit(): void
    {
        $board = $this->app->container->get(PriceBoardService::class);

        $this->fails(fn () => $board->saveItem(null, '', 'Mchele', '', 'kg'), 'name_en');
        $item = $board->saveItem(null, 'Rice', 'Mchele', 'wali, , pilau rice ,wali', 'kg');

        self::assertSame(['wali', 'pilau rice'], $item->aliases);
    }

    public function testCashPaymentsGetNumberedReceipts(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(1000));

        $a = $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'cash');
        $b = $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'cash');

        self::assertSame('CASH-20260312-001', $a->receiptCode);
        self::assertSame('CASH-20260312-002', $b->receiptCode);
    }

    public function testMpesaCodeIsValidatedAndNormalised(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(1000));

        $this->fails(fn () => $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'mpesa', 'short'), 'receipt');
        $entry = $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'mpesa', ' sgh1 a2b3c4 ');

        self::assertSame('SGH1A2B3C4', $entry->receiptCode);
    }

    public function testSameMpesaCodeCannotBeRecordedTwice(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(1000));
        $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'mpesa', 'SGH1A2B3C4');

        $this->fails(fn () => $this->ledger()->recordPayment((int) $customer->id, Money::kes(100), 'mpesa', 'SGH1A2B3C4'), 'amount');
    }

    public function testPaymentLargerThanBalanceIsRejectedAndNothingStored(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(300));

        $this->fails(fn () => $this->ledger()->recordPayment((int) $customer->id, Money::kes(301), 'cash'), 'amount');
        self::assertCount(1, $this->app->container->get(LedgerRepository::class)->entriesFor((int) $customer->id));
    }

    public function testPaymentMethodAndAmountValidation(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);

        $this->fails(fn () => $this->ledger()->recordPayment((int) $customer->id, Money::kes(10), 'cheque'), 'method');
        $this->fails(fn () => $this->ledger()->recordPayment((int) $customer->id, Money::zero(), 'cash'), 'amount');
    }

    public function testCreditNoteNeedsReasonAndCannotExceedBalance(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(300));

        $this->fails(fn () => $this->ledger()->creditNote((int) $customer->id, Money::kes(50), ''), 'note');
        $this->fails(fn () => $this->ledger()->creditNote((int) $customer->id, Money::kes(500), 'too much'), 'amount');
        $this->ledger()->creditNote((int) $customer->id, Money::kes(50), 'wilted kale');

        self::assertSame(250, $this->ledger()->ledgerFor((int) $customer->id)->balance()->amount);
    }

    public function testCreditLimitChanges(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 1000);

        self::assertSame(2500, $this->ledger()->setCreditLimit((int) $customer->id, 2500)->creditLimit->amount);
        $this->fails(fn () => $this->ledger()->setCreditLimit((int) $customer->id, -1), 'limit');
        $this->fails(fn () => $this->ledger()->setCreditLimit(999, 10), 'customer');
    }

    public function testAddCustomerValidatesAndRejectsDuplicatePhone(): void
    {
        $this->ledger()->addCustomer('Amina Yusuf', '0700 000 021', 2000);

        $this->fails(fn () => $this->ledger()->addCustomer('Amina Again', '+254700000021', 100), 'phone');
        $this->fails(fn () => $this->ledger()->addCustomer('A', 'bad', 9999999), 'name');
    }

    public function testDebtorsAreSortedByWhatTheyOwe(): void
    {
        $a = $this->app->customer('A One', '0700 000 031', 5000);
        $b = $this->app->customer('B Two', '0700 000 032', 5000);
        $this->ledger()->charge((int) $a->id, Money::kes(100));
        $this->ledger()->charge((int) $b->id, Money::kes(900));

        $rows = $this->ledger()->debtors();

        self::assertSame(['B Two', 'A One'], array_map(static fn (array $r): string => $r['customer']->name, $rows));
    }

    public function testShoppingListChecksPersist(): void
    {
        $shopping = $this->app->container->get(ShoppingListService::class);

        self::assertSame([], $shopping->forDate('2026-03-12'));
        $shopping->mark('2026-03-12', 1, 'kg', true);
        $shopping->mark('2026-03-12', 1, 'kg', true);
        self::assertSame(1, $this->app->db->scalar('SELECT COUNT(*) FROM shopping_checks'));
        $shopping->mark('2026-03-12', 1, 'kg', false);
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM shopping_checks'));
    }

    public function testTakingsServiceReadsTodaysLedgerPayments(): void
    {
        $customer = $this->app->customer('Otieno', '0700 000 020', 5000);
        $this->ledger()->charge((int) $customer->id, Money::kes(1000));
        $this->ledger()->recordPayment((int) $customer->id, Money::kes(400), 'cash');

        $t = $this->app->container->get(TakingsService::class)->forDate('2026-03-12');

        self::assertSame(400, $t->cashInHand()->amount);
    }
}
