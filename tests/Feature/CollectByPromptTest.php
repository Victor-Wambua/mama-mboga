<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Feature;

use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Application\Port\GatewayClient;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\PaymentCollectionService;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Tests\Support\FakeGatewayClient;
use MamaMboga\Tests\Support\HttpClient;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** The owner console's "Collect deni by prompt", driven through HTTP against a fake gateway. */
final class CollectByPromptTest extends TestCase
{
    private TestApp $app;
    private HttpClient $owner;
    private FakeGatewayClient $gateway;
    private int $customer;

    protected function setUp(): void
    {
        $this->app = new TestApp();
        $this->gateway = new FakeGatewayClient();
        $this->app->container->instance(GatewayClient::class, $this->gateway);
        $this->owner = $this->app->ownerClient();
        $this->customer = (int) $this->app->customer('Wanjiru Kamau', '0700 000 010', 5000)->id;
        $this->app->container->get(LedgerService::class)->charge($this->customer, Money::kes(2400), null, 'Notebook balance');
    }

    private function page(): string
    {
        return $this->owner->get("/owner/deni/{$this->customer}")->body;
    }

    private function balance(): int
    {
        return $this->app->container->get(LedgerService::class)->ledgerFor($this->customer)->balance()->amount;
    }

    public function testPromptRoutesNeedALogin(): void
    {
        $guest = $this->app->client();
        $guest->get('/owner/login');

        foreach (["/owner/deni/{$this->customer}/collect", '/owner/deni/prompts/1/check', '/owner/deni/prompts/1/review'] as $path) {
            $r = $guest->post($path, ['amount' => '5']);
            self::assertSame(303, $r->status, $path);
            self::assertSame('/owner/login', $r->header('Location'), $path);
        }
        self::assertSame([], $this->gateway->creates);
    }

    public function testTheFormDefaultsToTheBalanceAndShowsTheLimit(): void
    {
        $html = $this->page();

        self::assertStringContainsString('Collect deni by prompt', $html);
        self::assertStringContainsString('placeholder="2400"', $html);
        self::assertStringContainsString('max="2400"', $html);
        self::assertStringContainsString('Send M-Pesa prompt', $html);
    }

    public function testWithoutAGatewayTheSectionSaysHowToSetItUp(): void
    {
        $app = new TestApp();
        $app->container->instance(GatewayClient::class, new FakeGatewayClient(false));
        $owner = $app->ownerClient();
        $id = (int) $app->customer('Hassan Mohamed', '0700 000 020', 3000)->id;
        $app->container->get(LedgerService::class)->charge($id, Money::kes(500));

        $html = $owner->get("/owner/deni/{$id}")->body;

        self::assertStringContainsString('GATEWAY_SECRET_KEY', $html);
        self::assertStringNotContainsString('Send M-Pesa prompt', $html);
    }

    public function testSendingAPromptWritesNothingInTheBookAndShowsItAsWaiting(): void
    {
        $this->owner->get("/owner/deni/{$this->customer}");
        $r = $this->owner->post("/owner/deni/{$this->customer}/collect", ['amount' => '']);

        self::assertSame(303, $r->status);
        self::assertSame(2400, $this->gateway->creates[0]['amount'], 'blank means the whole balance');
        self::assertSame(2400, $this->balance());
        $html = $this->page();
        self::assertStringContainsString('Waiting.', $html);
        self::assertStringContainsString('not paid until the gateway says succeeded', $html);
        self::assertStringContainsString('Check status', $html);
    }

    public function testAnAmountAboveTheBalanceIsRefusedBeforeAnythingIsSent(): void
    {
        $this->owner->get("/owner/deni/{$this->customer}");
        $this->owner->post("/owner/deni/{$this->customer}/collect", ['amount' => '2401']);

        self::assertSame([], $this->gateway->creates);
        self::assertStringContainsString('up to the KSh 2400 owed', $this->page());
    }

    public function testNonNumbersAreRefused(): void
    {
        $this->owner->get("/owner/deni/{$this->customer}");
        $this->owner->post("/owner/deni/{$this->customer}/collect", ['amount' => '12abc']);

        self::assertSame([], $this->gateway->creates);
        self::assertStringContainsString('whole number of shillings', $this->page());
    }

    public function testCheckStatusRecordsASucceededPaymentOnceAndOnlyOnce(): void
    {
        $this->owner->post("/owner/deni/{$this->customer}/collect", ['amount' => '1000']);
        $prompt = $this->app->container->get(PaymentCollectionService::class)->forCustomer($this->customer)[0];
        $this->owner->get("/owner/deni/{$this->customer}");

        $this->owner->post("/owner/deni/prompts/{$prompt->id}/check");
        self::assertSame(2400, $this->balance(), 'still pending: nothing recorded');

        $this->gateway->report($this->gateway->lastId(), GatewayStatus::Succeeded, 'UKSI33RRGY');
        $this->owner->post("/owner/deni/prompts/{$prompt->id}/check");
        $this->owner->post("/owner/deni/prompts/{$prompt->id}/check");

        self::assertSame(1400, $this->balance());
        $html = $this->page();
        self::assertStringContainsString('Paid and recorded', $html);
        $entries = $this->app->container->get(LedgerService::class)->ledgerFor($this->customer)->entries;
        self::assertSame(['charge', 'payment'], array_map(static fn ($e): string => $e->type->value, $entries), 'one payment row, however often it was checked');
    }

    public function testAHeldPaymentShowsOnTheCustomerAndTheDeniBookAndIsNotRecorded(): void
    {
        $this->owner->post("/owner/deni/{$this->customer}/collect", ['amount' => '700']);
        $prompt = $this->app->container->get(PaymentCollectionService::class)->forCustomer($this->customer)[0];
        $this->gateway->report($this->gateway->lastId(), GatewayStatus::NeedsReview, 'UKSI33RRGY', ['late_success']);
        $this->owner->post("/owner/deni/prompts/{$prompt->id}/check");

        self::assertSame(2400, $this->balance());
        self::assertStringContainsString('Needs you.', $this->page());
        self::assertStringContainsString('arrived after the deadline', $this->page());
        $book = $this->owner->get('/owner/deni')->body;
        self::assertStringContainsString('Prompt payments that need you', $book);
        self::assertStringContainsString($prompt->reference, $book);

        $this->owner->get("/owner/deni/{$this->customer}");
        $this->owner->post("/owner/deni/prompts/{$prompt->id}/review", ['note' => 'No money in M-Pesa']);

        self::assertStringNotContainsString('Prompt payments that need you', $this->owner->get('/owner/deni')->body);
        self::assertStringContainsString('No money in M-Pesa', $this->page());
        self::assertSame(2400, $this->balance(), 'marking it seen never writes to the book');
    }

    public function testAnUnknownPromptIs404(): void
    {
        $this->owner->get('/owner/deni');

        self::assertSame(404, $this->owner->post('/owner/deni/prompts/999/check')->status);
        self::assertSame(404, $this->owner->post('/owner/deni/prompts/999/review')->status);
    }
}
