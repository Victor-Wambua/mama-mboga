<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Feature;

use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Tests\Support\HttpClient;
use MamaMboga\Tests\Support\Scrape;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class PublicFlowTest extends TestCase
{
    private TestApp $app;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->app = (new TestApp())->withBoard();
        $this->http = $this->app->client();
    }

    /** @return array<string, mixed> */
    private function orderForm(string $name = 'Wanjiru Kamau', string $phone = '0700 000 010', string $qtyKale = '2', string $qtyTomato = '1.5'): array
    {
        $slots = $this->app->container->get(SlotPlanner::class)->ensure('2026-03-12');

        return [
            'name' => $name,
            'phone' => $phone,
            'fulfilment' => 'delivery',
            'slot' => (string) $slots[3]->id,
            'address' => 'Kasarani, Mwiki Rd',
            'payment' => 'pay',
            'note' => '',
            'qty' => [(string) $this->app->entries['kale'] => $qtyKale, (string) $this->app->entries['tomato'] => $qtyTomato],
        ];
    }

    public function testMenuShowsTodaysBoardWithoutLogin(): void
    {
        $response = $this->http->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Today&apos;s prices', $response->body);
        self::assertStringContainsString('Sukuma wiki', $response->body);
        self::assertStringContainsString('KSh 120', $response->body);
        self::assertStringContainsString('qty[' . $this->app->entries['kale'] . ']', $response->body);
    }

    public function testEmptyBoardShowsAFriendlyMessage(): void
    {
        $app = new TestApp();
        $response = $app->client()->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('not up yet', $response->body);
    }

    public function testPlacingAnOrderRedirectsToASignedConfirmationWithAWhatsAppLink(): void
    {
        $this->http->get('/');
        $placed = $this->http->post('/order', $this->orderForm());

        self::assertSame(303, $placed->status);
        $location = (string) $placed->header('Location');
        self::assertMatchesRegularExpression('#^/order/MM-[A-Z0-9]{4}/[A-Za-z0-9_-]{43}$#', $location);

        $page = $this->http->get($location);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Asante, Wanjiru Kamau', $page->body);
        self::assertStringContainsString('KSh 220', $page->body);
        self::assertSame('no-store', $page->header('Cache-Control'));

        $href = html_entity_decode(Scrape::match('#href="(https://wa\.me/[^"]+)"#', $page->body, 1));
        self::assertStringStartsWith('https://wa.me/254700000001?text=', $href);
        $text = Scrape::whatsappText($href);
        self::assertStringContainsString('Sukuma wiki (Kale) × 2 bunch', $text);
        self::assertStringContainsString('Total: KSh 220', $text);
    }

    public function testTheOrderIsSavedEvenIfWhatsAppIsNeverOpened(): void
    {
        $this->http->get('/');
        $this->http->post('/order', $this->orderForm());

        self::assertSame(1, $this->app->db->scalar("SELECT COUNT(*) FROM orders WHERE status = 'received'"));
        self::assertSame(2, $this->app->db->scalar('SELECT COUNT(*) FROM order_lines'));
    }

    public function testTamperedConfirmationLinkIs404(): void
    {
        $this->http->get('/');
        $location = (string) $this->http->post('/order', $this->orderForm())->header('Location');
        $parts = explode('/', $location);

        self::assertSame(404, $this->http->get('/order/' . $parts[2] . '/' . str_repeat('A', 43))->status);
        self::assertSame(404, $this->http->get('/order/MM-AAAA/' . $parts[3])->status);
    }

    public function testValidationErrorsReRenderTheFormWithTheInputKept(): void
    {
        $this->http->get('/');
        $response = $this->http->post('/order', $this->orderForm(phone: '12345'));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Enter a Kenyan mobile number', $response->body);
        self::assertStringContainsString('value="Wanjiru Kamau"', $response->body);
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM orders'));
    }

    public function testOverOrderingShowsHowMuchIsLeft(): void
    {
        $this->http->get('/');
        $response = $this->http->post('/order', $this->orderForm(qtyTomato: '9'));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Only 5 kg of Tomatoes left', $response->body);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->http->get('/');
        $response = $this->http->post('/order', $this->orderForm(), csrf: false);

        self::assertSame(419, $response->status);
        self::assertStringContainsString('Page expired', $response->body);
        self::assertSame(0, $this->app->db->scalar('SELECT COUNT(*) FROM orders'));
    }

    public function testPostWithAnotherSessionsTokenIsRejected(): void
    {
        $other = $this->app->client();
        $other->get('/');
        $this->http->get('/');

        $response = $this->http->post('/order', $this->orderForm(), csrf: $other->token());

        self::assertSame(419, $response->status);
    }

    public function testFullSlotShowsAsFullAndRejectsOrders(): void
    {
        $slots = $this->app->container->get(SlotPlanner::class)->ensure('2026-03-12');
        $this->app->container->get(SlotRepository::class)->setCapacity((int) $slots[3]->id, 1);
        $this->http->get('/');
        $this->http->post('/order', $this->orderForm());

        $menu = $this->http->get('/');
        self::assertStringContainsString('Full', $menu->body);

        $second = $this->http->post('/order', $this->orderForm(phone: '0700 000 011'));
        self::assertSame(422, $second->status);
        self::assertStringContainsString('full', $second->body);
    }

    public function testOrderingIsRateLimitedPerClient(): void
    {
        $this->http->get('/');
        $last = null;
        for ($i = 0; $i < 13; $i++) {
            $last = $this->http->post('/order', $this->orderForm(phone: '12'));
        }

        self::assertSame(429, $last->status);
        self::assertNotNull($last->header('Retry-After'));
    }

    public function testUnknownPageIs404AndWrongMethodIs405(): void
    {
        self::assertSame(404, $this->http->get('/nope')->status);
        self::assertSame(405, $this->http->get('/order')->status);
    }

    public function testHealthCheck(): void
    {
        self::assertSame('{"status":"ok"}', $this->http->get('/healthz')->body);
    }

    public function testEveryResponseCarriesSecurityHeaders(): void
    {
        foreach (['/', '/nope', '/owner/login'] as $path) {
            $r = $this->http->get($path);
            self::assertNotNull($r->header('Content-Security-Policy'), $path);
            self::assertSame('nosniff', $r->header('X-Content-Type-Options'), $path);
        }
    }

    public function testMenuPageStaysUnder100KbIncludingCss(): void
    {
        $html = strlen($this->http->get('/')->body);
        $css = (int) filesize(dirname(__DIR__, 2) . '/public/assets/app.css');
        $js = (int) filesize(dirname(__DIR__, 2) . '/public/assets/js/basket.js') + (int) filesize(dirname(__DIR__, 2) . '/public/assets/js/pwa.js');

        self::assertLessThan(100 * 1024, $html + $css + $js, "html {$html} + css {$css} + js {$js}");
    }

    public function testInstallableAppFilesExist(): void
    {
        $public = dirname(__DIR__, 2) . '/public';

        self::assertFileExists($public . '/sw.js');
        self::assertFileExists($public . '/assets/icon-192.png');
        self::assertFileExists($public . '/assets/icon-512.png');
        $manifest = json_decode((string) file_get_contents($public . '/manifest.webmanifest'), true);
        self::assertIsArray($manifest);
        self::assertSame('standalone', $manifest['display']);
        self::assertStringContainsString('/offline', (string) file_get_contents($public . '/sw.js'));
    }

    public function testOfflinePageRenders(): void
    {
        self::assertStringContainsString('No connection', $this->http->get('/offline')->body);
    }

    public function testCustomerNameWithMarkupIsEscapedEverywhere(): void
    {
        $evil = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $this->http->get('/');
        $location = (string) $this->http->post('/order', $this->orderForm(name: $evil))->header('Location');
        self::assertNotSame('', $location, 'a hostile name is a valid name: it is stored, and must be escaped on output');

        $confirmation = $this->http->get($location)->body;
        self::assertStringNotContainsString('<script>alert(1)', $confirmation);
        self::assertStringNotContainsString('<img src=x', $confirmation);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $confirmation);

        $owner = $this->app->ownerClient();
        foreach (['/owner', '/owner/run-sheet', '/owner/orders/1'] as $page) {
            $html = $owner->get($page)->body;
            self::assertStringNotContainsString('<script>alert(1)', $html, $page);
            self::assertStringNotContainsString('<img src=x', $html, $page);
        }
    }

    public function testHostileInputInErrorRedisplayIsEscaped(): void
    {
        $this->http->get('/');
        $form = $this->orderForm(phone: '"><script>x</script>');
        $form['address'] = '"><script>y</script>';
        $body = $this->http->post('/order', $form)->body;

        self::assertStringNotContainsString('<script>x', $body);
        self::assertStringNotContainsString('<script>y', $body);
    }
}
