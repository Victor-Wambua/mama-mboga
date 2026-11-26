<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Application;

use MamaMboga\Application\Messaging\OrderMessage;
use MamaMboga\Application\Messaging\ReminderMessage;
use MamaMboga\Application\Messaging\WhatsAppLink;
use MamaMboga\Domain\Ledger\Aging;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderLine;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;
use MamaMboga\Tests\Support\Scrape;
use PHPUnit\Framework\TestCase;

final class MessagingTest extends TestCase
{
    private function order(Fulfilment $f = Fulfilment::Delivery, PaymentMode $p = PaymentMode::PayOnDelivery, OrderStatus $s = OrderStatus::Received): Order
    {
        return new Order(5, 'MM-7K3Q', '2026-03-12', null, 'Wanjiru Kamau', PhoneNumber::parse('0700000010'), $f, $f === Fulfilment::Delivery ? 1 : null, $f === Fulfilment::Delivery ? 'Kasarani, Mwiki Rd' : '', '', $s, $p, null, [
            new OrderLine(1, 'Kale', 'Sukuma wiki', Unit::Bunch, Quantity::units(2), Money::kes(20)),
            new OrderLine(2, 'Tomatoes', 'Nyanya', Unit::Kg, Quantity::milli(1500), Money::kes(120)),
        ], 'web', new \DateTimeImmutable('2026-03-12 07:00'));
    }

    public function testLinkHasShopNumberAndEncodedText(): void
    {
        $url = WhatsAppLink::to('254700000001', "Habari!\nOda yangu");

        self::assertSame('https://wa.me/254700000001?text=Habari%21%0AOda%20yangu', $url);
    }

    public function testLinkStripsNonDigitsFromTheNumber(): void
    {
        self::assertStringStartsWith('https://wa.me/254700000001?', WhatsAppLink::to('+254 700-000-001', 'x'));
    }

    public function testTextRoundTripsThroughTheLink(): void
    {
        $text = "Jumla / Total: KSh 240 • Nyanya × 1.5 kg — asante & karibu? 100% #1\nmstari wa pili";
        $url = WhatsAppLink::to('254700000001', $text);

        self::assertSame($text, Scrape::whatsappText($url));
        self::assertStringNotContainsString(' ', $url);
        self::assertStringNotContainsString("\n", $url);
        self::assertStringContainsString('%E2%80%A2', $url); // the bullet, as UTF-8 bytes
    }

    public function testOverlongTextIsTruncatedNotBroken(): void
    {
        $url = WhatsAppLink::to('254700000001', str_repeat('mboga ', 600));

        $text = Scrape::whatsappText($url);
        self::assertLessThanOrEqual(WhatsAppLink::MAX_TEXT, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function testOrderMessageListsEverythingAndTheReference(): void
    {
        $text = OrderMessage::forShop($this->order(), 'Mama Njeri Greens', '4pm–6pm');

        self::assertStringContainsString('MM-7K3Q', $text);
        self::assertStringContainsString('• Sukuma wiki (Kale) × 2 bunch = KSh 40', $text);
        self::assertStringContainsString('• Nyanya (Tomatoes) × 1.5 kg = KSh 180', $text);
        self::assertStringContainsString('Total: KSh 220', $text);
        self::assertStringContainsString('Delivery: 4pm–6pm, Kasarani, Mwiki Rd', $text);
        self::assertStringContainsString('0700 000 010', $text);
    }

    public function testOrderMessageForPickupAndDeni(): void
    {
        $text = OrderMessage::forShop($this->order(Fulfilment::Pickup, PaymentMode::Deni), 'Shop', null);

        self::assertStringContainsString('Pickup at the shop', $text);
        self::assertStringContainsString('Malipo: deni', $text);
    }

    public function testStatusUpdateMessage(): void
    {
        self::assertStringContainsString('iko njiani', OrderMessage::statusUpdate($this->order(s: OrderStatus::Out), 'Shop'));
        self::assertStringContainsString('ready for pickup', OrderMessage::statusUpdate($this->order(Fulfilment::Pickup, s: OrderStatus::Out), 'Shop'));
    }

    public function testReminderIsGentleWhenFreshAndFirmerWhenOld(): void
    {
        $fresh = new Aging(Money::kes(500), Money::zero(), Money::zero(), Money::zero());
        $old = new Aging(Money::zero(), Money::zero(), Money::kes(300), Money::kes(200));

        $gentle = ReminderMessage::compose('Wanjiru Kamau', Money::kes(500), $fresh, 'Mama Njeri Greens', 'https://x.test/s/1', 'en');
        $firm = ReminderMessage::compose('Wanjiru Kamau', Money::kes(500), $old, 'Mama Njeri Greens', 'https://x.test/s/1', 'en');

        self::assertStringContainsString('friendly reminder', $gentle);
        self::assertStringContainsString('more than two weeks old', $firm);
        self::assertStringContainsString('Hello Wanjiru,', $gentle);
        self::assertStringContainsString('https://x.test/s/1', $gentle);
    }

    public function testReminderInSwahili(): void
    {
        $text = ReminderMessage::compose('Otieno Odhiambo', Money::kes(1250), new Aging(Money::kes(1250), Money::zero(), Money::zero(), Money::zero()), 'Mama Njeri Greens', 'https://x.test/s/2');

        self::assertStringStartsWith('Habari Otieno,', $text);
        self::assertStringContainsString('KSh 1,250', $text);
        self::assertStringContainsString('Asante sana', $text);
    }
}
