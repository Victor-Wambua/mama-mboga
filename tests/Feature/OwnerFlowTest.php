<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Feature;

use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Tests\Support\HttpClient;
use MamaMboga\Tests\Support\Scrape;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class OwnerFlowTest extends TestCase
{
    private TestApp $app;
    private HttpClient $owner;

    protected function setUp(): void
    {
        $this->app = (new TestApp())->withBoard();
        $this->owner = $this->app->ownerClient();
    }

    private function placeOrder(string $phone = '0700 000 010', string $kale = '2', string $name = 'Wanjiru Kamau', bool $deni = false): int
    {
        $slots = $this->app->container->get(SlotPlanner::class)->ensure('2026-03-12');
        $order = $this->app->container->get(OrderService::class)->place(new PlaceOrder($name, $phone, 'delivery', $slots[3]->id, 'Kasarani', '', [$this->app->entries['kale'] => $kale, $this->app->entries['tomato'] => '1'], $deni));

        return (int) $order->id;
    }

    /** @return list<string> */
    private function ownerPages(): array
    {
        return ['/owner', '/owner/board', '/owner/items', '/owner/paste', '/owner/shopping', '/owner/run-sheet', '/owner/takings', '/owner/deni', '/owner/settings'];
    }

    public function testOwnerPagesRequireLogin(): void
    {
        $guest = $this->app->client();

        foreach ($this->ownerPages() as $page) {
            $r = $guest->get($page);
            self::assertSame(303, $r->status, $page);
            self::assertStringStartsWith('/owner/login', (string) $r->header('Location'), $page);
        }
    }

    public function testOwnerPostsRequireLoginToo(): void
    {
        $guest = $this->app->client();
        $guest->get('/owner/login');

        foreach (['/owner/board', '/owner/board/copy', '/owner/paste', '/owner/orders/1/status', '/owner/deni/customers', '/owner/settings'] as $path) {
            $r = $guest->post($path, ['x' => 'y']);
            self::assertSame(303, $r->status, $path);
            self::assertSame('/owner/login', $r->header('Location'), $path);
        }
    }

    public function testOwnerPagesRenderForTheOwner(): void
    {
        $this->placeOrder();
        foreach ($this->ownerPages() as $page) {
            self::assertSame(200, $this->owner->get($page)->status, $page);
        }
        self::assertSame(200, $this->owner->get('/owner/orders/1')->status);
    }

    public function testWrongPinIsRefusedAndRightPinOpensTheConsole(): void
    {
        $client = $this->app->client();
        $client->get('/owner/login');

        $wrong = $client->post('/owner/login', ['pin' => 'nope']);
        self::assertSame(422, $wrong->status);
        self::assertStringContainsString('not right', $wrong->body);
        self::assertSame(303, $client->get('/owner')->status);

        $right = $client->post('/owner/login', ['pin' => TestApp::PIN, 'next' => '/owner/board']);
        self::assertSame('/owner/board', $right->header('Location'));
        self::assertSame(200, $client->get('/owner')->status);
    }

    public function testLoginRedirectCannotLeaveTheSite(): void
    {
        $client = $this->app->client();
        $client->get('/owner/login');

        foreach (['https://evil.test/', '//evil.test', 'javascript:alert(1)', '/owner/../..'] as $next) {
            $client = $this->app->client();
            $client->get('/owner/login');
            $r = $client->post('/owner/login', ['pin' => TestApp::PIN, 'next' => $next]);
            self::assertSame('/owner', $r->header('Location'), $next);
        }
    }

    public function testLoginIsRateLimited(): void
    {
        $client = $this->app->client();
        $client->ip = '5.5.5.5';
        $client->get('/owner/login');
        $statuses = [];
        for ($i = 0; $i < 10; $i++) {
            $statuses[] = $client->post('/owner/login', ['pin' => 'wrong' . $i])->status;
        }

        self::assertSame(429, $statuses[9]);
        self::assertSame(422, $statuses[0]);
        $client->post('/owner/login', ['pin' => TestApp::PIN]);
        self::assertSame(303, $client->get('/owner')->status, 'even the right PIN is refused while rate limited');
    }

    public function testLoginChangesTheSessionIdAgainstFixation(): void
    {
        $client = $this->app->client();
        $client->get('/owner/login');
        $before = $client->cookie('mm_session');
        $client->post('/owner/login', ['pin' => TestApp::PIN]);

        self::assertNotNull($before);
        self::assertNotSame($before, $client->cookie('mm_session'));
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM sessions WHERE id = ?', [$before]), 'the pre-login id must be dead');
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->owner->post('/owner/logout');

        self::assertSame(303, $this->owner->get('/owner')->status);
    }

    public function testSessionCookieFlags(): void
    {
        $client = $this->app->client();
        $cookie = $client->get('/owner/login')->headerValues('Set-Cookie')[0] ?? '';

        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
    }

    public function testStatusFlowFromTheBoard(): void
    {
        $id = $this->placeOrder();

        foreach (['packing', 'out', 'delivered'] as $status) {
            $r = $this->owner->post("/owner/orders/{$id}/status", ['status' => $status, 'paid_with' => 'mpesa', 'back' => '/owner']);
            self::assertSame(303, $r->status);
        }

        self::assertSame(['delivered', 'mpesa'], array_values($this->app->db->one('SELECT status, settled_via FROM orders WHERE id = ?', [$id]) ?? []));
        self::assertStringContainsString('Delivered', $this->owner->get('/owner')->body);
    }

    public function testIllegalStatusJumpShowsAnErrorAndChangesNothing(): void
    {
        $id = $this->placeOrder();
        $this->owner->post("/owner/orders/{$id}/status", ['status' => 'delivered']);

        self::assertSame('received', $this->app->db->one('SELECT status FROM orders WHERE id = ?', [$id])['status'] ?? null);
        self::assertStringContainsString('cannot become', $this->owner->get('/owner')->body);
    }

    public function testStatusUpdatesNeedCsrf(): void
    {
        $id = $this->placeOrder();
        $r = $this->owner->post("/owner/orders/{$id}/status", ['status' => 'packing'], csrf: false);

        self::assertSame(419, $r->status);
        self::assertSame('received', $this->app->db->one('SELECT status FROM orders WHERE id = ?', [$id])['status'] ?? null);
    }

    public function testMarketListAggregatesOpenOrdersAndCheckOffsPersist(): void
    {
        $this->placeOrder('0700 000 010', '2');
        $this->placeOrder('0700 000 011', '3', 'Otieno');

        $page = $this->owner->get('/owner/shopping')->body;
        self::assertStringContainsString('5 bunch', $page);
        self::assertStringContainsString('2 kg', $page);

        $r = $this->owner->post('/owner/shopping/check', ['item_id' => '1', 'unit' => 'bunch', 'bought' => '1'], csrf: true);
        self::assertSame(303, $r->status);
        $ajax = $this->owner->send('POST', '/owner/shopping/check', ['item_id' => '2', 'unit' => 'kg', 'bought' => '1', '_csrf' => $this->owner->token()], ['X-Requested-With' => 'fetch']);
        self::assertSame('{"ok":true}', $ajax->body);
        self::assertSame(2, $this->app->db->scalar('SELECT COUNT(*) FROM shopping_checks'));
        self::assertStringContainsString('Bought', $this->owner->get('/owner/shopping')->body);
    }

    public function testMarketListDropsOrdersOnceTheyAreOutForDelivery(): void
    {
        $id = $this->placeOrder();
        $this->owner->post("/owner/orders/{$id}/status", ['status' => 'packing']);
        $this->owner->post("/owner/orders/{$id}/status", ['status' => 'out']);

        self::assertStringContainsString('Nothing to buy', $this->owner->get('/owner/shopping')->body);
    }

    public function testRunSheetListsDeliveriesBySlot(): void
    {
        $this->placeOrder();
        $body = $this->owner->get('/owner/run-sheet')->body;

        self::assertStringContainsString('6pm–8pm', $body);
        self::assertStringContainsString('Kasarani', $body);
        self::assertStringContainsString('tel:+254700000010', $body);
        self::assertStringContainsString('Collect payment', $body);
    }

    public function testPriceBoardSaveAndCopy(): void
    {
        $repo = $this->app->container->get(PriceBoardRepository::class);
        $entry = $this->app->entries['tomato'];

        $this->owner->get('/owner/board');
        $this->owner->post('/owner/board', ['rows' => [(string) $entry => ['unit' => 'kg', 'price' => '135', 'available' => '12', 'special' => '1', 'note' => 'Ripe']]]);
        $row = $repo->entry($entry);
        self::assertNotNull($row);
        self::assertSame(135, $row->price->amount);
        self::assertSame(12000, $row->available->milli);
        self::assertTrue($row->special);

        $this->app->clock->set('2026-03-13 07:00:00');
        $this->owner->login(TestApp::PIN); // the 8-hour session has lapsed overnight
        $this->owner->post('/owner/board/copy');
        self::assertCount(4, $repo->board('2026-03-13'));
    }

    public function testBadPriceIsReportedAndNotSaved(): void
    {
        $entry = $this->app->entries['tomato'];
        $this->owner->post('/owner/board', ['rows' => [(string) $entry => ['unit' => 'kg', 'price' => 'free', 'available' => '12']]]);

        self::assertSame(120, $this->app->container->get(PriceBoardRepository::class)->entry($entry)?->price->amount);
        self::assertStringContainsString('whole number of shillings', $this->owner->get('/owner/board')->body);
    }

    public function testAddingANewItemToTodaysBoard(): void
    {
        $this->owner->post('/owner/board/add', ['item_id' => '0', 'name_en' => 'Pumpkin', 'name_sw' => 'Malenge', 'aliases' => 'boga', 'unit' => 'piece', 'price' => '80', 'available' => '6']);

        $names = array_map(static fn ($s): string => $s->nameEn, $this->app->container->get(PriceBoardRepository::class)->board('2026-03-12'));
        self::assertContains('Pumpkin', $names);
    }

    public function testPasteFlowParsesFlagsAndSavesAnOrder(): void
    {
        $page = $this->owner->post('/owner/paste', ['message' => "sukuma 2 bunch, nyanya kilo moja na nusu\nmchele 1kg\ndeliver 6pm\n0700 000 014"]);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('3 lines read, 1 to check', $page->body);
        self::assertStringContainsString("Not on today's board", $page->body);
        self::assertStringContainsString('value="0700 000 014"', $page->body);
        self::assertStringContainsString('6pm', $page->body);

        $slots = $this->app->container->get(SlotPlanner::class)->ensure('2026-03-12');
        $confirm = $this->owner->post('/owner/paste/confirm', [
            'message' => 'x',
            'line' => [
                ['entry' => (string) $this->app->entries['kale'], 'qty' => '2', 'raw' => 'sukuma 2 bunch'],
                ['entry' => (string) $this->app->entries['tomato'], 'qty' => '1.5', 'raw' => 'nyanya'],
                ['entry' => '', 'qty' => '1', 'raw' => 'mchele 1kg', 'drop' => '1'],
            ],
            'name' => 'Pasted Customer', 'phone' => '0700 000 014', 'fulfilment' => 'delivery', 'slot' => (string) $slots[3]->id, 'address' => 'Roysambu',
        ]);

        self::assertSame(303, $confirm->status);
        $order = $this->app->db->one("SELECT source, total_kes, customer_name FROM orders WHERE source = 'whatsapp'");
        self::assertSame(['whatsapp', 220, 'Pasted Customer'], array_values($order ?? []));
    }

    public function testPasteConfirmWithAProblemShowsTheDraftAgainWithTheError(): void
    {
        $r = $this->owner->post('/owner/paste/confirm', [
            'message' => 'x',
            'line' => [['entry' => (string) $this->app->entries['tomato'], 'qty' => '99', 'raw' => 'nyanya 99']],
            'name' => 'Pasted Customer', 'phone' => '0700 000 014', 'fulfilment' => 'pickup',
        ]);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Only 5 kg of Tomatoes left', $r->body);
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM orders'));
    }

    public function testEmptyPasteIsRefusedPolitely(): void
    {
        $r = $this->owner->post('/owner/paste', ['message' => '   ']);

        self::assertSame(303, $r->status);
    }

    public function testDeniBookFlow(): void
    {
        $customer = $this->app->customer('Otieno Odhiambo', '0700 000 020', 2000);
        $id = $this->placeOrder('0700 000 020', '2', 'Otieno Odhiambo', deni: true);
        foreach (['packing', 'out', 'delivered'] as $s) {
            $this->owner->post("/owner/orders/{$id}/status", ['status' => $s]);
        }

        $page = $this->owner->get('/owner/deni/' . $customer->id)->body;
        self::assertStringContainsString('KSh 160', $page);
        self::assertStringContainsString('Reminder in Swahili', $page);

        $this->owner->post("/owner/deni/{$customer->id}/payment", ['amount' => '100', 'method' => 'mpesa', 'receipt' => 'SGH1A2B3C4']);
        self::assertStringContainsString('SGH1A2B3C4', $this->owner->get("/owner/deni/{$customer->id}")->body);

        $dup = $this->owner->post("/owner/deni/{$customer->id}/payment", ['amount' => '10', 'method' => 'mpesa', 'receipt' => 'SGH1A2B3C4']);
        self::assertSame(303, $dup->status);
        self::assertStringContainsString('already been recorded', $this->owner->get("/owner/deni/{$customer->id}")->body);
        self::assertSame(2, $this->app->db->scalar('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testReminderLinkIsAPrefilledWhatsAppMessageToTheCustomer(): void
    {
        $customer = $this->app->customer('Otieno Odhiambo', '0700 000 020', 2000);
        $this->owner->post("/owner/deni/{$customer->id}/charge", ['amount' => '500', 'note' => 'Notebook balance']);

        $html = $this->owner->get("/owner/deni/{$customer->id}")->body;
        $text = Scrape::whatsappText(Scrape::match('#href="(https://wa\.me/254700000020\?text=[^"]+)"#', $html, 1));
        self::assertStringContainsString('KSh 500', $text);
        self::assertMatchesRegularExpression('#http://localhost:8080/s/\d+/\d+/[A-Za-z0-9_-]+#', $text);
    }

    public function testSignedStatementLink(): void
    {
        $customer = $this->app->customer('Otieno Odhiambo', '0700 000 020', 2000);
        $this->owner->post("/owner/deni/{$customer->id}/charge", ['amount' => '500', 'note' => 'Notebook balance']);
        $link = Scrape::match('#/s/\d+/\d+/[A-Za-z0-9_-]+#', $this->owner->get("/owner/deni/{$customer->id}")->body);

        $guest = $this->app->client();
        $page = $guest->get($link);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('KSh 500', $page->body);
        self::assertStringContainsString('Notebook balance', $page->body);
        self::assertStringContainsString('noindex', (string) $page->header('X-Robots-Tag'));
        self::assertSame('no-referrer', $page->header('Referrer-Policy'));

        $parts = explode('/', $link);
        self::assertSame(404, $guest->get("/s/{$parts[2]}/{$parts[3]}/" . str_repeat('A', 43))->status, 'bad signature');
        self::assertSame(404, $guest->get('/s/' . ($customer->id + 1) . "/{$parts[3]}/{$parts[4]}")->status, 'signature is bound to the customer');
        self::assertSame(404, $guest->get("/s/{$parts[2]}/" . ((int) $parts[3] + 86400 * 365) . "/{$parts[4]}")->status, 'signature is bound to the expiry');

        $this->app->clock->set('2026-05-01 07:00:00');
        self::assertSame(404, $guest->get($link)->status, 'expired');
    }

    public function testStatementsNeverOfferEditing(): void
    {
        $customer = $this->app->customer('Otieno Odhiambo', '0700 000 020', 2000);
        $this->owner->post("/owner/deni/{$customer->id}/charge", ['amount' => '100', 'note' => 'Notebook']);
        $link = Scrape::match('#/s/\d+/\d+/[A-Za-z0-9_-]+#', $this->owner->get("/owner/deni/{$customer->id}")->body);

        self::assertStringNotContainsString('<form', $this->app->client()->get($link)->body);
    }

    public function testTakingsPage(): void
    {
        $id = $this->placeOrder();
        foreach (['packing', 'out'] as $s) {
            $this->owner->post("/owner/orders/{$id}/status", ['status' => $s]);
        }
        $this->owner->post("/owner/orders/{$id}/status", ['status' => 'delivered', 'paid_with' => 'cash']);

        $body = $this->owner->get('/owner/takings')->body;
        self::assertStringContainsString('KSh 160', $body);
        self::assertStringContainsString('Cash in hand', $body);
        self::assertStringContainsString('Thursday 12 March 2026', $body);
    }

    public function testSettingsAndPinChange(): void
    {
        $this->owner->post('/owner/settings', ['name' => 'Fresh Corner', 'tagline' => 'Hi', 'whatsapp' => '0711 000 000', 'capacity' => '4']);
        self::assertStringContainsString('Fresh Corner', $this->app->client()->get('/')->body);

        $bad = $this->owner->post('/owner/settings/pin', ['current_pin' => 'wrong', 'new_pin' => 'abcdef1', 'confirm_pin' => 'abcdef1']);
        self::assertSame(303, $bad->status);
        self::assertSame(0, $this->app->client()->login('abcdef1')->status === 303 ? 1 : 0);

        $this->owner->post('/owner/settings/pin', ['current_pin' => TestApp::PIN, 'new_pin' => 'abcdef1', 'confirm_pin' => 'abcdef1']);
        $fresh = $this->app->client();
        $fresh->login('abcdef1');
        self::assertSame(200, $fresh->get('/owner')->status);
        $old = $this->app->client();
        $old->ip = '7.7.7.7';
        $old->login(TestApp::PIN);
        self::assertSame(303, $old->get('/owner')->status, 'the old PIN stops working');
    }

    public function testInvalidSettingsAreRejected(): void
    {
        $this->owner->post('/owner/settings', ['name' => 'X', 'whatsapp' => 'nope', 'capacity' => 'many']);

        self::assertStringNotContainsString('Fresh Corner', $this->app->client()->get('/')->body);
        self::assertStringContainsString('Test Greens', $this->app->client()->get('/')->body);
    }

    public function testOwnerPagesAreNotCacheable(): void
    {
        self::assertSame('no-store', $this->owner->get('/owner')->header('Cache-Control'));
    }
}
