<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Application;

use MamaMboga\Application\Collection\Attention;
use MamaMboga\Application\Collection\PaymentRequest;
use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Application\Port\GatewayClient;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\PaymentCollectionService;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Tests\Support\FakeGatewayClient;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Collecting deni by prompt: the book is written only when the gateway says succeeded, and only through the ledger's own rules. */
final class PaymentCollectionTest extends TestCase
{
    private TestApp $app;
    private FakeGatewayClient $gateway;
    private PaymentCollectionService $collect;
    private LedgerService $ledger;
    private int $customer;

    protected function setUp(): void
    {
        $this->app = new TestApp();
        $this->gateway = new FakeGatewayClient();
        $this->app->container->instance(GatewayClient::class, $this->gateway);
        $this->collect = $this->app->container->get(PaymentCollectionService::class);
        $this->ledger = $this->app->container->get(LedgerService::class);
        $this->customer = (int) $this->app->customer('Wanjiru Kamau', '0700 000 010', 5000)->id;
        $this->ledger->charge($this->customer, Money::kes(2400), null, 'Notebook balance');
    }

    private function balance(): int
    {
        return $this->ledger->ledgerFor($this->customer)->balance()->amount;
    }

    private function entries(): int
    {
        return count($this->ledger->ledgerFor($this->customer)->entries);
    }

    private function sent(?int $amount = null): PaymentRequest
    {
        return $this->collect->request($this->customer, $amount);
    }

    private function succeed(string $receipt = 'UKSI33RRGY', ?int $amount = null): void
    {
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Succeeded, $receipt, [], $amount);
    }

    public function testTheAmountDefaultsToTheBalanceAndIsNeverAboveIt(): void
    {
        self::assertSame(2400, $this->sent()->amount, 'blank means the whole balance');

        $this->collect->reconcileOpen();
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Cancelled);
        $this->collect->reconcileOpen();
        $partial = $this->sent(1000);
        self::assertSame(1000, $partial->amount);
        self::assertSame(1000, $this->gateway->creates[1]['amount'], 'the gateway is asked for exactly the amount chosen');

        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Failed);
        $this->collect->reconcile((int) $partial->id);
        foreach ([2401, 99999] as $tooMuch) {
            try {
                $this->sent($tooMuch);
                self::fail("KSh {$tooMuch} is above the balance");
            } catch (ValidationException $e) {
                self::assertStringContainsString('up to the KSh 2400 owed', $e->errors['amount']);
            }
        }
        foreach ([0, -5] as $none) {
            try {
                $this->sent($none);
                self::fail("KSh {$none} is not an amount");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('amount', $e->errors);
            }
        }
    }

    public function testNothingOwedMeansNothingToCollect(): void
    {
        $clear = (int) $this->app->customer('Clear Account', '0700 000 011', 1000)->id;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('owes nothing');
        $this->collect->request($clear);
    }

    public function testThePromptGoesToTheCustomersOwnNumberWithAShortGatewaySafeReference(): void
    {
        $request = $this->sent(600);

        self::assertMatchesRegularExpression('/^DENI-[A-Z0-9]{6}$/', $request->reference);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,64}$/', $request->reference, 'the gateway accepts it as a reference');
        self::assertSame('254700000010', $this->gateway->creates[0]['phone']);
        self::assertSame($request->reference, $this->gateway->creates[0]['reference']);
        self::assertGreaterThanOrEqual(8, strlen($request->idempotencyKey), 'the gateway needs 8 to 128 characters');
        self::assertNotNull($request->gatewayPaymentId);
        self::assertSame(2400, $this->balance(), 'sending a prompt writes nothing in the book');
    }

    public function testOnlyOnePromptCanBeWaitingPerCustomer(): void
    {
        $this->sent(500);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('still waiting');
        $this->sent(500);
    }

    public function testPendingAndConfirmingAreNeverRecorded(): void
    {
        $request = $this->sent();
        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(GatewayStatus::Pending, $after->status);
        self::assertSame('waiting', $after->display()['state']);
        self::assertSame(0, $this->entries() - 1, 'only the original charge is in the book');
        self::assertSame(2400, $this->balance());
        self::assertNull($after->ledgerEntryId);
    }

    public function testSucceededIsRecordedThroughTheLedger(): void
    {
        $request = $this->sent(1000);
        $this->succeed('UKSI33RRGY', 1000);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame('recorded', $after->display()['state']);
        self::assertSame(1400, $this->balance());
        $entry = $this->ledger->ledgerFor($this->customer)->entries[1];
        self::assertSame('mpesa', $entry->method);
        self::assertSame('UKSI33RRGY', $entry->receiptCode);
        self::assertSame(1000, $entry->amount->amount);
        self::assertStringContainsString($request->reference, $entry->note);
        self::assertStringContainsString((string) $request->gatewayPaymentId, $entry->note);
        self::assertSame($entry->id, $after->ledgerEntryId);
    }

    public function testRecordingIsIdempotentByGatewayPaymentAndReceipt(): void
    {
        $request = $this->sent(1000);
        $this->succeed();

        for ($i = 0; $i < 4; $i++) {
            $this->collect->reconcile((int) $request->id);
        }
        $this->collect->attach((int) $request->id, $this->gateway->getPayment($this->gateway->lastId()));
        $this->collect->reconcileOpen();

        self::assertSame(2, $this->entries(), 'one charge and one payment, however often it is reconciled');
        self::assertSame(1400, $this->balance());
    }

    public function testOneGatewayPaymentCannotBeAttachedToTwoRequests(): void
    {
        $first = $this->sent(500);
        $this->succeed('UKSI33RRGY', 500);
        $this->collect->reconcile((int) $first->id);
        $second = $this->collect->open($this->customer, 500);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already attached');
        $this->collect->attach((int) $second->id, $this->gateway->getPayment((string) $first->gatewayPaymentId));
    }

    public function testAReceiptAlreadyInTheBookIsRefusedAndNeverRecordedTwice(): void
    {
        $this->ledger->recordPayment($this->customer, Money::kes(100), 'mpesa', 'UKSI33RRGY');
        $request = $this->sent(500);
        $this->succeed('UKSI33RRGY', 500);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::BookRefused, $after->attention);
        self::assertStringContainsString('already been recorded', $after->attentionNote);
        self::assertSame(2300, $this->balance(), 'only the manual KSh 100 payment is in the book');
        self::assertTrue($after->needsOwner());
        self::assertSame(2, $this->entries());
    }

    public function testMoreThanIsOwedIsRefusedWhenTheBalanceFellInTheMeantime(): void
    {
        $request = $this->sent(2400);
        $this->ledger->recordPayment($this->customer, Money::kes(1000), 'cash');
        $this->succeed('UKSI33RRGY', 2400);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::BookRefused, $after->attention);
        self::assertStringContainsString('more than the', $after->attentionNote);
        self::assertSame(1400, $this->balance());
        self::assertNull($after->ledgerEntryId);
    }

    /** @return array<string, array{list<string>, Attention}> */
    public static function reviewFlags(): array
    {
        return [
            'late success' => [['late_success', 'no_result_before_deadline'], Attention::LateSuccess],
            'callback and query disagree' => [['callback_query_mismatch'], Attention::CallbackQueryMismatch],
            'callback fields differ' => [['callback_field_mismatch'], Attention::CallbackFieldMismatch],
            'unverified' => [['unverified_success'], Attention::UnverifiedSuccess],
            'outcome unknown' => [['push_outcome_unknown'], Attention::PushOutcomeUnknown],
            'no flag' => [[], Attention::NeedsReview],
        ];
    }

    /**
     * @param list<string> $flags
     */
    #[DataProvider('reviewFlags')]
    public function testNeedsReviewIsShownToTheOwnerAndNeverRecorded(array $flags, Attention $expected): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::NeedsReview, 'UKSI33RRGY', $flags);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame($expected, $after->attention);
        self::assertSame('needs_owner', $after->display()['state']);
        self::assertSame(2400, $this->balance(), 'a held payment is never written, even with a receipt on it');
        self::assertSame([$after->id], array_map(static fn (PaymentRequest $r): ?int => $r->id, $this->collect->needingOwner()));
        $this->collect->reconcileOpen();
        self::assertSame(2400, $this->balance());
    }

    public function testALateSuccessAfterATimeoutBecomesAnItemForTheOwner(): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::TimedOut);
        $timedOut = $this->collect->reconcile((int) $request->id);
        self::assertSame('timed_out', $timedOut->display()['state']);
        self::assertSame(2400, $this->balance());

        $this->gateway->report($this->gateway->lastId(), GatewayStatus::NeedsReview, 'UKSI33RRGY', ['late_success']);
        $late = $this->collect->reconcileOpen()[0];

        self::assertSame(Attention::LateSuccess, $late->attention);
        self::assertSame(2400, $this->balance());
    }

    #[DataProvider('blockingFlags')]
    public function testASucceededPaymentWithADisagreementFlagIsNotRecorded(string $flag, Attention $expected): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Succeeded, 'UKSI33RRGY', [$flag]);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame($expected, $after->attention);
        self::assertSame(2400, $this->balance());
    }

    /** @return array<string, array{string, Attention}> */
    public static function blockingFlags(): array
    {
        return [
            'late' => ['late_success', Attention::LateSuccess],
            'query' => ['callback_query_mismatch', Attention::CallbackQueryMismatch],
            'fields' => ['callback_field_mismatch', Attention::CallbackFieldMismatch],
            'unverified' => ['unverified_success', Attention::UnverifiedSuccess],
        ];
    }

    public function testASuccessAfterAnAnswerOfNotPaidDisagreesWithItselfAndIsNotRecorded(): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Cancelled);
        $this->collect->reconcile((int) $request->id);

        $this->succeed('UKSI33RRGY', 700);
        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::StatusChanged, $after->attention);
        self::assertSame(2400, $this->balance());
    }

    public function testAPaymentWithTheWrongAmountIsNotRecorded(): void
    {
        $request = $this->sent(700);
        $this->succeed('UKSI33RRGY', 70);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::DetailsMismatch, $after->attention);
        self::assertStringContainsString('KSh 700', $after->attentionNote);
        self::assertSame(2400, $this->balance());
    }

    public function testAPaymentForADifferentReferenceIsNotRecorded(): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Succeeded, 'UKSI33RRGY', [], null, 'DENI-OTHER1');

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::DetailsMismatch, $after->attention);
        self::assertSame(2400, $this->balance());
    }

    public function testASucceededPaymentWithoutAUsableReceiptIsNotRecorded(): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Succeeded, null);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(Attention::DetailsMismatch, $after->attention);
        self::assertSame(2400, $this->balance());
    }

    /** @return array<string, array{GatewayStatus}> */
    public static function notPaid(): array
    {
        return ['failed' => [GatewayStatus::Failed], 'cancelled' => [GatewayStatus::Cancelled], 'timed out' => [GatewayStatus::TimedOut]];
    }

    #[DataProvider('notPaid')]
    public function testFailedCancelledAndTimedOutWriteNothing(GatewayStatus $status): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), $status);

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame($status, $after->status);
        self::assertNull($after->attention);
        self::assertSame(2400, $this->balance());
        self::assertFalse($after->waiting());
        $this->sent(100); // and the customer can be asked again
    }

    public function testMarkingAnItemSeenNeverTouchesTheBook(): void
    {
        $request = $this->sent(700);
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::NeedsReview, 'UKSI33RRGY', ['late_success']);
        $held = $this->collect->reconcile((int) $request->id);

        $seen = $this->collect->markReviewed((int) $held->id, 'Checked M-Pesa: no money arrived');

        self::assertFalse($seen->needsOwner());
        self::assertSame('reviewed', $seen->display()['state']);
        self::assertSame([], $this->collect->needingOwner());
        self::assertSame(2400, $this->balance());
        self::assertSame(Attention::LateSuccess, $this->collect->reconcile((int) $held->id)->attention, 'reconciling does not reopen or record it');
        self::assertSame(2400, $this->balance());
    }

    public function testNothingToMarkSeenOnAnOrdinaryRequest(): void
    {
        $request = $this->sent(700);

        $this->expectException(ValidationException::class);
        $this->collect->markReviewed((int) $request->id, 'x');
    }

    public function testAnUnreachableGatewayLeavesASavedRequestThatResendsWithTheSameKey(): void
    {
        $this->gateway->failNextCreate = new GatewayException(GatewayException::UNREACHABLE, 'down');
        try {
            $this->sent(700);
            self::fail('expected the owner to be told');
        } catch (ValidationException $e) {
            self::assertStringContainsString('cannot go out twice', $e->errors['amount']);
        }
        $saved = $this->collect->forCustomer($this->customer)[0];
        self::assertFalse($saved->sent());
        self::assertSame('not_sent', $saved->display()['state']);

        $after = $this->collect->reconcile((int) $saved->id);

        self::assertTrue($after->sent());
        self::assertSame([$saved->idempotencyKey, $saved->idempotencyKey], array_column($this->gateway->creates, 'key'));
        self::assertCount(1, $this->gateway->payments, 'the gateway holds one payment, however often it was asked');
        $this->collect->reconcile((int) $saved->id);
        self::assertCount(1, $this->gateway->payments);
    }

    public function testAGatewayRefusalClosesTheRequestAndIsNotRetried(): void
    {
        $this->gateway->failNextCreate = new GatewayException(GatewayException::REJECTED, 'phone must be a Kenyan mobile number');
        try {
            $this->sent(700);
            self::fail('expected the owner to be told');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Nothing was sent', $e->errors['amount']);
        }
        $failed = $this->collect->forCustomer($this->customer)[0];

        $this->collect->reconcile((int) $failed->id);

        self::assertCount(1, $this->gateway->creates, 'a refused request is not sent again');
        self::assertSame('failed', $failed->display()['state']);
        $this->sent(100); // a fresh request is allowed
    }

    public function testARequestTheGatewayNeverAcceptedCanBeAbandonedButASentOneCannot(): void
    {
        $unsent = $this->collect->open($this->customer, 100);
        $abandoned = $this->collect->abandon((int) $unsent->id, 'Not sent from this browser.');
        self::assertSame('failed', $abandoned->display()['state']);
        self::assertSame([], $this->gateway->creates);

        $sent = $this->sent(100);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('was sent');
        $this->collect->abandon((int) $sent->id, 'x');
    }

    public function testRateLimitingKeepsTheRequestAndTellsTheOwnerHowLong(): void
    {
        $this->gateway->failNextCreate = new GatewayException(GatewayException::RATE_LIMITED, 'slow down', 45);

        try {
            $this->sent(700);
            self::fail('expected the owner to be told');
        } catch (ValidationException $e) {
            self::assertStringContainsString('45 seconds', $e->errors['amount']);
        }
        self::assertFalse($this->collect->forCustomer($this->customer)[0]->sent());
    }

    public function testAnUnreachableGatewayOnReadKeepsTheStateAndNotesTheError(): void
    {
        $request = $this->sent(700);
        $this->gateway->failNextRead = new GatewayException(GatewayException::UNREACHABLE, 'down');

        $after = $this->collect->reconcile((int) $request->id);

        self::assertSame(GatewayStatus::Pending, $after->status);
        self::assertSame('down', $after->lastError);
        self::assertSame(2400, $this->balance());
    }

    public function testReconcileOpenOnlyAsksAboutRequestsStillOpen(): void
    {
        $done = $this->sent(500);
        $this->succeed('UKSI33RRGY', 500);
        $this->collect->reconcile((int) $done->id);
        $reads = $this->gateway->reads;
        $second = $this->sent(300);

        $results = $this->collect->reconcileOpen();

        self::assertSame([$second->id], array_map(static fn (PaymentRequest $r): ?int => $r->id, $results));
        self::assertSame($reads + 1, $this->gateway->reads);
    }

    public function testAnUnconfiguredGatewaySaysSoInsteadOfCreatingARequest(): void
    {
        $this->app->container->instance(GatewayClient::class, new FakeGatewayClient(false));
        $collect = $this->app->container->make(PaymentCollectionService::class);

        self::assertFalse($collect->enabled());
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('GATEWAY_URL');
        $collect->request($this->customer);
    }

    public function testTheLedgerStaysAppendOnlyAndItsTablesAreUntouchedByTheNewMigration(): void
    {
        $request = $this->sent(500);
        $this->succeed('UKSI33RRGY', 500);
        $this->collect->reconcile((int) $request->id);
        $db = $this->app->db;

        self::assertSame(2, $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND name IN ('ledger_no_update', 'ledger_no_delete')"));
        foreach (['UPDATE ledger_entries SET amount_kes = 1', 'DELETE FROM ledger_entries'] as $sql) {
            try {
                $db->execute($sql);
                self::fail("{$sql} must be refused");
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        self::assertSame(1900, $this->balance());
    }

    public function testThePaymentFromTheGatewayParsesAndRejectsShapesThatAreNotPayments(): void
    {
        $p = GatewayPayment::fromArray(['id' => 'pay_1', 'reference' => 'DENI-AAAAAA', 'amount' => 5, 'status' => 'needs_review', 'flags' => ['late_success', 7], 'mpesa_receipt' => 'MOCK123456']);
        self::assertSame(GatewayStatus::NeedsReview, $p->status);
        self::assertSame(['late_success'], $p->flags);

        $this->expectException(\InvalidArgumentException::class);
        GatewayPayment::fromArray(['id' => 'pay_1', 'reference' => 'x', 'amount' => '5', 'status' => 'paid']);
    }
}
