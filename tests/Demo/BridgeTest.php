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
}
