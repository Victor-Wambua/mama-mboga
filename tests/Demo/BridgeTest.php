<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Demo;

use MamaMboga\Demo\Bridge;
use PHPUnit\Framework\TestCase;

/** The browser demo's JSON bridge, run on native PHP: it is the same file the page runs under PHP-WASM. */
final class BridgeTest extends TestCase
{
    private Bridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = Bridge::boot(dirname(__DIR__, 2), ':memory:', new \DateTimeImmutable('2026-03-12 14:00:00', new \DateTimeZone('Africa/Nairobi')));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function ok(string $action, array $args = []): array
    {
        $r = $this->bridge->handle($action, $args);
        self::assertTrue($r['ok'], $action . ' failed: ' . json_encode($r));
        self::assertIsArray($r['data']);

        return $r['data'];
    }

    /** @return array<string, array<string, mixed>> board entries by English name */
    private function board(): array
    {
        $out = [];
        foreach ($this->ok('snapshot')['board'] as $row) {
            $out[$row['nameEn']] = $row;
        }

        return $out;
    }

    public function testSeededShopHasATodayBoardOrdersAndDeni(): void
    {
        $s = $this->ok('snapshot');

        self::assertSame('2026-03-12', $s['today']);
        self::assertSame('Mama Njeri Greens', $s['shop']);
        self::assertGreaterThan(10, count($s['board']));
        self::assertNotEmpty($s['orders']);
        self::assertNotEmpty(array_filter($s['customers'], static fn (array $c): bool => $c['balance'] > 0));
        self::assertTrue($s['hasPrevious']);
    }

    public function testPricesCanBeChangedAndInvalidPricesAreRefusedWithAFieldMessage(): void
    {
        $kale = $this->board()['Kale'];
        $this->ok('setEntry', ['itemId' => $kale['itemId'], 'unit' => 'bunch', 'price' => '35', 'available' => '12', 'special' => true, 'note' => 'Fresh']);
        self::assertSame(35, $this->board()['Kale']['price']);

        $bad = $this->bridge->handle('setEntry', ['itemId' => $kale['itemId'], 'unit' => 'bunch', 'price' => '0', 'available' => '1']);
        self::assertFalse($bad['ok']);
        self::assertArrayHasKey('price', $bad['errors']);
    }

    public function testParserFlagsWhatItCannotBeSureOf(): void
    {
        $r = $this->ok('parse', ['message' => "Hi Njeri\n2 kg nyanya, sukuma wiki 3 bunch, vitunguu nusu kilo\nviazi 2kg\nsend to Kasarani\nsaa 6 jioni"]);

        self::assertSame('Kasarani', $r['address']);
        self::assertNotSame('', $r['time']);
        $byFragment = [];
        foreach ($r['lines'] as $line) {
            $byFragment[] = $line;
        }
        $statuses = array_column($byFragment, 'status');
        self::assertContains('matched', $statuses);
        self::assertContains('ambiguous', $statuses, '"viazi" is Irish or sweet potatoes and must be flagged');
        foreach ($byFragment as $line) {
            if ($line['status'] === 'ambiguous') {
                self::assertTrue($line['review']);
                self::assertGreaterThanOrEqual(2, count($line['alternatives']));
            }
        }
    }

    public function testQuoteUsesTheBoardPriceAndRefusesBadAmounts(): void
    {
        $tomato = $this->board()['Tomatoes'];
        $q = $this->ok('quote', ['lines' => [['entry' => $tomato['entryId'], 'qty' => '1.5'], ['entry' => $tomato['entryId'], 'qty' => '0.3']]]);

        self::assertSame((int) round($tomato['price'] * 1.5), $q['lines'][0]['total']);
        self::assertNotNull($q['lines'][1]['problem']);
    }

    public function testParsedOrderBecomesAnOrderOnTheMarketListAndStockDrops(): void
    {
        $tomato = $this->board()['Tomatoes'];
        $before = (float) $tomato['available'];
        $placed = $this->ok('placeOrder', [
            'lines' => [['entry' => $tomato['entryId'], 'qty' => '2'], ['entry' => $tomato['entryId'], 'qty' => '1']],
            'name' => 'Test Buyer',
            'phone' => '0700 000 777',
            'fulfilment' => 'pickup',
        ]);

        self::assertMatchesRegularExpression('/^MM-/', $placed['reference']);
        self::assertEqualsWithDelta($before - 3, (float) $this->board()['Tomatoes']['available'], 0.0001);
        $line = array_values(array_filter($placed['snapshot']['shopping'], static fn (array $l): bool => $l['itemId'] === $tomato['itemId']))[0];
        self::assertGreaterThanOrEqual(3.0, (float) $line['quantity']);
    }

    public function testPlacingAnOrderWithoutAContactIsRefused(): void
    {
        $r = $this->bridge->handle('placeOrder', ['lines' => [], 'name' => '', 'phone' => 'abc', 'fulfilment' => 'delivery']);

        self::assertFalse($r['ok']);
        self::assertArrayHasKey('name', $r['errors']);
        self::assertArrayHasKey('phone', $r['errors']);
        self::assertArrayHasKey('items', $r['errors']);
    }

    public function testDeniPaymentRulesHold(): void
    {
        $debtor = array_values(array_filter($this->ok('snapshot')['customers'], static fn (array $c): bool => $c['balance'] > 0))[0];
        $id = $debtor['id'];

        $this->ok('payment', ['customerId' => $id, 'amount' => '100', 'method' => 'mpesa', 'receipt' => 'QWE1234567']);
        $after = $this->ok('customer', ['customerId' => $id]);
        self::assertSame($debtor['balance'] - 100, $after['balance']);

        $dup = $this->bridge->handle('payment', ['customerId' => $id, 'amount' => '50', 'method' => 'mpesa', 'receipt' => 'QWE1234567']);
        self::assertFalse($dup['ok'], 'a receipt code is used once');

        $over = $this->bridge->handle('payment', ['customerId' => $id, 'amount' => '9999999', 'method' => 'cash']);
        self::assertFalse($over['ok'], 'cannot pay more than is owed');
        self::assertSame($after['balance'], $this->ok('customer', ['customerId' => $id])['balance']);

        $badCode = $this->bridge->handle('payment', ['customerId' => $id, 'amount' => '50', 'method' => 'mpesa', 'receipt' => 'short']);
        self::assertFalse($badCode['ok']);
    }

    public function testChargeStopsAtTheCreditLimit(): void
    {
        $id = array_values(array_filter($this->ok('snapshot')['customers'], static fn (array $c): bool => $c['limit'] > 0))[0]['id'];
        $detail = $this->ok('customer', ['customerId' => $id]);

        $r = $this->bridge->handle('charge', ['customerId' => $id, 'amount' => (string) ($detail['available'] + 1), 'note' => 'Over the limit']);

        self::assertFalse($r['ok']);
        self::assertSame($detail['balance'], $this->ok('customer', ['customerId' => $id])['balance']);
    }

    public function testDeliveringADeniOrderChargesTheLedger(): void
    {
        $customer = array_values(array_filter($this->ok('snapshot')['customers'], static fn (array $c): bool => $c['available'] > 500))[0];
        $onion = $this->board()['Onions'];
        $placed = $this->ok('placeOrder', [
            'lines' => [['entry' => $onion['entryId'], 'qty' => '1']],
            'name' => $customer['name'],
            'phone' => $customer['phone'],
            'fulfilment' => 'pickup',
            'payment' => 'deni',
        ]);
        $order = array_values(array_filter($placed['snapshot']['orders'], static fn (array $o): bool => $o['reference'] === $placed['reference']))[0];

        foreach (['packing', 'out', 'delivered'] as $to) {
            $this->ok('changeStatus', ['orderId' => $order['id'], 'to' => $to]);
        }

        self::assertSame($customer['balance'] + $onion['price'], $this->ok('customer', ['customerId' => $customer['id']])['balance']);
    }

    public function testReminderIsComposedInBothLanguages(): void
    {
        $id = array_values(array_filter($this->ok('snapshot')['customers'], static fn (array $c): bool => $c['balance'] > 0))[0]['id'];

        self::assertStringContainsString('Habari', $this->ok('reminder', ['customerId' => $id, 'lang' => 'sw'])['text']);
        self::assertStringContainsString('Hello', $this->ok('reminder', ['customerId' => $id, 'lang' => 'en'])['text']);
    }

    // ---- collect by prompt: the page talks to the gateway, PHP runs the app's own rules ------

    /** A new customer who owes exactly this much, so every test here knows what the book says. */
    private function debtorOwing(int $amount): int
    {
        $this->ok('addCustomer', ['name' => 'Prompt Tester', 'phone' => '0711 222 333', 'limit' => '5000']);
        $id = $this->customerNamed('Prompt Tester');
        $this->ok('charge', ['customerId' => $id, 'amount' => (string) $amount, 'note' => 'Notebook balance']);

        return $id;
    }

    private function customerNamed(string $name): int
    {
        foreach ($this->ok('snapshot')['customers'] as $c) {
            if ($c['name'] === $name) {
                return $c['id'];
            }
        }
        self::fail("No customer {$name}");
    }

    /**
     * @param list<string> $flags
     * @return array<string, mixed>
     */
    private function gatewayPayment(string $reference, int $amount, string $status, ?string $receipt = null, array $flags = [], string $id = 'pay_demo0001'): array
    {
        return ['id' => $id, 'reference' => $reference, 'amount' => $amount, 'currency' => 'KES', 'phone_masked' => '2547****010', 'status' => $status, 'flags' => $flags]
            + ($receipt === null ? [] : ['mpesa_receipt' => $receipt, 'result_code' => 0]);
    }

    /**
     * @param array<string, mixed> $prompt
     * @param list<string> $flags
     * @return array<string, mixed>
     */
    private function reconcile(array $prompt, string $status, ?string $receipt = null, array $flags = [], ?int $amount = null): array
    {
        return $this->ok('collectReconcile', ['requestId' => $prompt['id'], 'payment' => $this->gatewayPayment($prompt['reference'], $amount ?? $prompt['charge'], $status, $receipt, $flags, 'pay_demo' . $prompt['id'])]);
    }

    /** @return array<string, mixed> a sent, pending prompt (its gateway payment id is pay_demo<request id>) */
    private function sentPrompt(int $customerId, string $amount = '', string $mode = 'mock'): array
    {
        $prompt = $this->ok('collectOpen', ['customerId' => $customerId, 'amount' => $amount, 'mode' => $mode]);

        return $this->ok('collectSent', ['requestId' => $prompt['id'], 'payment' => $this->gatewayPayment($prompt['reference'], $prompt['charge'], 'pending', null, [], 'pay_demo' . $prompt['id'])]);
    }

    private function owed(int $customerId): int
    {
        return $this->ok('customer', ['customerId' => $customerId])['balance'];
    }

    public function testMockPromptDefaultsToTheBalanceCappedAtTheDemoKeyLimitAndNeverExceedsTheBalance(): void
    {
        $id = $this->debtorOwing(1500);

        $big = $this->ok('collectOpen', ['customerId' => $id, 'mode' => 'mock']);
        self::assertSame(1000, $big['amount'], 'blank means the balance, up to what the public demo key can take in one prompt');
        self::assertSame(1000, $big['charge']);
        self::assertMatchesRegularExpression('/^DENI-[A-Z0-9]{6}$/', $big['reference']);
        self::assertFalse($big['sent']);
        $this->ok('collectDiscard', ['requestId' => $big['id']]);

        $tooBigForTheKey = $this->bridge->handle('collectOpen', ['customerId' => $id, 'amount' => '1001', 'mode' => 'mock']);
        self::assertFalse($tooBigForTheKey['ok']);
        self::assertStringContainsString('limited to KSh 1000', $tooBigForTheKey['errors']['amount']);
        $over = $this->bridge->handle('collectOpen', ['customerId' => $id, 'amount' => '1501', 'mode' => 'mock']);
        self::assertFalse($over['ok']);
        $small = $this->ok('collectOpen', ['customerId' => $id, 'amount' => '600', 'mode' => 'mock']);
        self::assertSame(600, $small['amount']);
    }

    public function testAPromptForASmallBalanceDefaultsToExactlyTheBalance(): void
    {
        $id = $this->debtorOwing(525);

        self::assertSame(525, $this->ok('collectOpen', ['customerId' => $id, 'mode' => 'mock'])['amount']);
    }

    public function testSandboxPromptChargesAFlatKesOneButKeepsTheAmountItStandsFor(): void
    {
        $id = $this->debtorOwing(1800);

        $prompt = $this->ok('collectOpen', ['customerId' => $id, 'amount' => '1800', 'mode' => 'sandbox']);

        self::assertSame(1800, $prompt['amount']);
        self::assertSame(1, $prompt['charge']);
        self::assertSame('sandbox', $prompt['mode']);
        $this->ok('collectDiscard', ['requestId' => $prompt['id']]);
        $over = $this->bridge->handle('collectOpen', ['customerId' => $id, 'amount' => '1801', 'mode' => 'sandbox']);
        self::assertFalse($over['ok'], 'KES 1 standing in for it does not let the amount exceed the balance');
    }

    public function testNothingIsRecordedUntilTheGatewayReportsSucceeded(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id);

        self::assertSame('waiting', $prompt['state']);
        self::assertSame(900, $this->owed($id));
        $stillPending = $this->reconcile($prompt, 'pending');
        self::assertSame('waiting', $stillPending['state']);
        self::assertSame(900, $this->owed($id), 'pending, and the gateway still confirming, is never paid');
    }

    public function testASucceededMockPaymentIsRecordedByTheRealLedgerCodeAndMarkedAsSimulated(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '300');

        $done = $this->reconcile($prompt, 'succeeded', 'MOCKEBFCD9');

        self::assertSame('recorded', $done['state']);
        self::assertSame(600, $this->owed($id));
        $rows = $this->ok('customer', ['customerId' => $id])['rows'];
        $last = end($rows);
        self::assertSame('MOCKEBFCD9', $last['receipt']);
        self::assertSame('mpesa', $last['method']);
        self::assertSame(300, $last['amount']);
        self::assertStringContainsString('Mock gateway, simulated', $last['note']);
        self::assertStringContainsString($prompt['reference'], $last['note']);
    }

    public function testRecordingIsIdempotentByTheGatewayPayment(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '300');

        foreach (range(1, 4) as $_) {
            $this->reconcile($prompt, 'succeeded', 'MOCKEBFCD9');
        }
        $this->ok('collectSent', ['requestId' => $prompt['id'], 'payment' => $this->gatewayPayment($prompt['reference'], 300, 'succeeded', 'MOCKEBFCD9', [], 'pay_demo' . $prompt['id'])]);

        self::assertSame(600, $this->owed($id), 'five reports of the same success, one payment in the book');
    }

    public function testASandboxSuccessIsMarkedAsATestAndStandsInForTheAmount(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '400', 'sandbox');

        $done = $this->reconcile($prompt, 'succeeded', 'UKSI33RRGY');

        self::assertSame('recorded', $done['state']);
        self::assertSame(500, $this->owed($id));
        $rows = $this->ok('customer', ['customerId' => $id])['rows'];
        self::assertStringContainsString('Sandbox test: KSh 1 charged stands in for KSh 400', end($rows)['note']);
        self::assertStringContainsString('KES 1 sandbox test charge', $done['detail']);
    }

    public function testTheLedgersDuplicateReceiptRefusalStillApplies(): void
    {
        $id = $this->debtorOwing(900);
        $this->ok('payment', ['customerId' => $id, 'amount' => '25', 'method' => 'mpesa', 'receipt' => 'MOCKEBFCD9']);
        $prompt = $this->sentPrompt($id, '300');

        $dup = $this->reconcile($prompt, 'succeeded', 'MOCKEBFCD9');

        self::assertSame('needs_owner', $dup['state']);
        self::assertStringContainsString('already been recorded', $dup['detail']);
        self::assertSame(875, $this->owed($id));
    }

    public function testTheLedgersOverpaymentRefusalStillApplies(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '900');
        $this->ok('payment', ['customerId' => $id, 'amount' => '500', 'method' => 'cash']);

        $over = $this->reconcile($prompt, 'succeeded', 'MOCKAAAAAA');

        self::assertSame('needs_owner', $over['state']);
        self::assertStringContainsString('more than the', $over['detail']);
        self::assertSame(400, $this->owed($id));
    }

    public function testHeldAndDisagreeingPaymentsShowAsItemsForTheOwnerAndAreNeverRecorded(): void
    {
        $id = $this->debtorOwing(900);

        foreach ([
            ['needs_review', ['late_success'], 'late_success'],
            ['needs_review', ['callback_query_mismatch'], 'callback_query_mismatch'],
            ['succeeded', ['callback_field_mismatch'], 'callback_field_mismatch'],
        ] as $n => [$status, $flags, $why]) {
            $prompt = $this->sentPrompt($id, '100');
            $held = $this->reconcile($prompt, $status, 'MOCK00000' . $n, $flags);
            self::assertSame('needs_owner', $held['state'], $why);
            self::assertSame(900, $this->owed($id), $why);
            $needs = $this->ok('snapshot')['needsYou'];
            self::assertContains($prompt['id'], array_column($needs, 'id'));
            self::assertSame('Prompt Tester', $needs[0]['customerName']);
            $this->ok('collectReview', ['requestId' => $prompt['id'], 'note' => 'Looked']);
            self::assertNotContains($prompt['id'], array_column($this->ok('snapshot')['needsYou'], 'id'));
        }
    }

    public function testAFailedOrCancelledPromptWritesNothingAndTheCustomerCanBeAskedAgain(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '100');

        $cancelled = $this->reconcile($prompt, 'cancelled');

        self::assertSame('cancelled', $cancelled['state']);
        self::assertSame(900, $this->owed($id));
        $this->sentPrompt($id, '100'); // would be refused if the first were still waiting
    }

    public function testAPaymentWithTheWrongAmountOrFromAnotherRequestIsHeldOrRefused(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '100');

        $wrong = $this->reconcile($prompt, 'succeeded', 'MOCKBBBBBB', [], 99);
        self::assertSame('needs_owner', $wrong['state']);
        self::assertSame(900, $this->owed($id));

        $second = $this->anotherPrompt($id);
        $other = $this->bridge->handle('collectReconcile', ['requestId' => $second['id'], 'payment' => $this->gatewayPayment($second['reference'], 100, 'succeeded', 'MOCKCCCCCC', [], 'pay_someone_else')]);
        self::assertSame('waiting', $other['data']['state'], 'a payment that is not the one attached to this request is ignored');
        self::assertSame(900, $this->owed($id));

        $junk = $this->bridge->handle('collectReconcile', ['requestId' => $second['id'], 'payment' => ['id' => 'x']]);
        self::assertFalse($junk['ok']);
    }

    /** @return array<string, mixed> another sent prompt for the same customer, once the first no longer waits */
    private function anotherPrompt(int $id): array
    {
        return $this->sentPrompt($id, '100');
    }

    public function testARequestTheGatewayNeverAcceptedCanBeDiscardedButASentOneCannot(): void
    {
        $id = $this->debtorOwing(900);
        $unsent = $this->ok('collectOpen', ['customerId' => $id, 'amount' => '100', 'mode' => 'mock']);

        $blocked = $this->bridge->handle('collectOpen', ['customerId' => $id, 'amount' => '100', 'mode' => 'mock']);
        self::assertFalse($blocked['ok'], 'an unsent request still blocks a second one');

        $gone = $this->ok('collectDiscard', ['requestId' => $unsent['id'], 'reason' => 'The gateway could not be reached']);
        self::assertSame('failed', $gone['state']);
        $sent = $this->sentPrompt($id, '100');
        self::assertFalse($this->bridge->handle('collectDiscard', ['requestId' => $sent['id']])['ok']);
    }

    public function testPromptsAreListedOnTheCustomerWithTheirGatewayPayment(): void
    {
        $id = $this->debtorOwing(900);
        $prompt = $this->sentPrompt($id, '100');

        $listed = $this->ok('customer', ['customerId' => $id])['prompts'];

        self::assertSame($prompt['reference'], $listed[0]['reference']);
        self::assertSame('pay_demo' . $prompt['id'], $listed[0]['gatewayPaymentId']);
        self::assertTrue($listed[0]['checkable']);
        self::assertSame('mock', $listed[0]['mode']);
    }
}
