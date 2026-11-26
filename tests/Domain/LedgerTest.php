<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\Ledger;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Ledger\LedgerViolation;
use MamaMboga\Domain\Money\Money;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    private function charge(int $amount, string $when = '2026-03-01 10:00', ?int $orderId = null, int $customer = 1): LedgerEntry
    {
        return new LedgerEntry(null, $customer, EntryType::Charge, Money::kes($amount), new \DateTimeImmutable($when), null, null, $orderId, 'test');
    }

    private function pay(int $amount, string $code = 'SGH1A2B3C4', string $when = '2026-03-02 10:00', string $method = 'mpesa'): LedgerEntry
    {
        return new LedgerEntry(null, 1, EntryType::Payment, Money::kes($amount), new \DateTimeImmutable($when), $method, $code);
    }

    private function book(int $limit = 5000): Ledger
    {
        return Ledger::open(1, Money::kes($limit));
    }

    public function testBalanceIsChargesMinusPaymentsAndCredits(): void
    {
        $ledger = $this->book()
            ->append($this->charge(1000))
            ->append($this->charge(500, '2026-03-02 09:00'))
            ->append($this->pay(700))
            ->append(new LedgerEntry(null, 1, EntryType::CreditNote, Money::kes(100), new \DateTimeImmutable('2026-03-03'), null, null, null, 'wilted kale'));

        self::assertSame(700, $ledger->balance()->amount);
    }

    public function testAppendNeverMutatesTheOriginal(): void
    {
        $empty = $this->book();
        $next = $empty->append($this->charge(100));

        self::assertCount(0, $empty->entries);
        self::assertCount(1, $next->entries);
        self::assertSame(0, $empty->balance()->amount);
    }

    public function testCannotOverpay(): void
    {
        $ledger = $this->book()->append($this->charge(300));
        $this->expectException(LedgerViolation::class);
        $ledger->append($this->pay(301));
    }

    public function testCanPayExactBalance(): void
    {
        $ledger = $this->book()->append($this->charge(300))->append($this->pay(300));
        self::assertTrue($ledger->balance()->isZero());
    }

    public function testChargeCannotExceedCreditLimit(): void
    {
        $ledger = $this->book(1000)->append($this->charge(800));
        $this->expectException(LedgerViolation::class);
        $ledger->append($this->charge(201, '2026-03-02 10:00'));
    }

    public function testChargeUpToTheLimitIsAllowed(): void
    {
        $ledger = $this->book(1000)->append($this->charge(800))->append($this->charge(200, '2026-03-02 10:00'));
        self::assertSame(1000, $ledger->balance()->amount);
        self::assertTrue($ledger->available()->isZero());
    }

    public function testZeroLimitMeansNoCredit(): void
    {
        $this->expectException(LedgerViolation::class);
        $this->book(0)->append($this->charge(1));
    }

    public function testReceiptCodeCanBeUsedOnce(): void
    {
        $ledger = $this->book()->append($this->charge(1000))->append($this->pay(100));
        $this->expectException(LedgerViolation::class);
        $ledger->append($this->pay(100, 'SGH1A2B3C4', '2026-03-03 10:00'));
    }

    public function testOrderIsChargedOnce(): void
    {
        $ledger = $this->book()->append($this->charge(100, orderId: 7));
        $this->expectException(LedgerViolation::class);
        $ledger->append($this->charge(100, '2026-03-02 10:00', 7));
    }

    public function testNoBackdatingBeforeLatestEntry(): void
    {
        $ledger = $this->book()->append($this->charge(100, '2026-03-05 10:00'));
        $this->expectException(LedgerViolation::class);
        $ledger->append($this->charge(100, '2026-03-04 10:00'));
    }

    public function testOtherCustomersEntriesAreRejected(): void
    {
        $this->expectException(LedgerViolation::class);
        $this->book()->append($this->charge(100, customer: 2));
    }

    public function testEntriesNeedPositiveAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->charge(0);
    }

    public function testPaymentNeedsReceiptAndKnownMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(null, 1, EntryType::Payment, Money::kes(10), new \DateTimeImmutable(), 'cheque', 'X1');
    }

    public function testPaymentWithoutReceiptIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(null, 1, EntryType::Payment, Money::kes(10), new \DateTimeImmutable(), 'cash', null);
    }

    public function testCreditNoteNeedsAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(null, 1, EntryType::CreditNote, Money::kes(10), new \DateTimeImmutable(), null, null, null, ' ');
    }

    public function testStatementHasRunningBalance(): void
    {
        $rows = $this->book()->append($this->charge(1000))->append($this->pay(400))->statement();

        self::assertSame([1000, 600], array_map(static fn (array $r): int => $r['balance']->amount, $rows));
    }

    public function testOpeningFromHistoryDoesNotReapplyTheLimit(): void
    {
        // The limit may have been lowered after the debt was taken on: history must still load.
        $ledger = Ledger::open(1, Money::kes(100), [$this->charge(500)]);
        self::assertSame(500, $ledger->balance()->amount);
        self::assertTrue($ledger->available()->isZero());
    }

    public function testAgingAllocatesPaymentsToOldestChargesFirst(): void
    {
        $asOf = new \DateTimeImmutable('2026-03-12');
        $ledger = $this->book(10000)
            ->append($this->charge(1000, '2026-01-20 10:00'))   // 51 days old
            ->append($this->charge(500, '2026-02-20 10:00'))    // 20 days
            ->append($this->charge(300, '2026-03-05 10:00'))    // 7 days
            ->append($this->charge(200, '2026-03-09 10:00'))    // 3 days
            ->append($this->pay(1200, 'AAAAAAAAAA', '2026-03-10 10:00'));

        $aging = $ledger->aging($asOf);

        self::assertSame(0, $aging->over30->amount);       // oldest 1000 fully paid
        self::assertSame(300, $aging->days15to30->amount); // 500 - 200 left of payment
        self::assertSame(500, $aging->current->amount);    // 300 + 200
        self::assertSame($ledger->balance()->amount, $aging->total()->amount);
        self::assertSame('15-30', $aging->oldestBucket());
    }

    public function testAgingBucketsByDays(): void
    {
        $asOf = new \DateTimeImmutable('2026-03-31');
        $ledger = $this->book(100000)
            ->append($this->charge(800, '2026-02-01 10:00'))
            ->append($this->charge(400, '2026-03-10 10:00'))
            ->append($this->charge(200, '2026-03-20 10:00'))
            ->append($this->charge(100, '2026-03-30 10:00'));
        $aging = $ledger->aging($asOf);

        self::assertSame([100, 200, 400, 800], [$aging->current->amount, $aging->days8to14->amount, $aging->days15to30->amount, $aging->over30->amount]);
        self::assertSame('30+', $aging->oldestBucket());
    }

    public function testAgingOfAClearedBookIsEmpty(): void
    {
        $aging = $this->book()->append($this->charge(100))->append($this->pay(100))->aging(new \DateTimeImmutable('2026-04-01'));

        self::assertTrue($aging->total()->isZero());
        self::assertNull($aging->oldestBucket());
    }
}
