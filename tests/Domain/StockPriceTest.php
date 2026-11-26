<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use PHPUnit\Framework\TestCase;

final class StockPriceTest extends TestCase
{
    private function stock(Unit $unit = Unit::Kg, int $availableMilli = 3000, int $price = 120): StockPrice
    {
        return new StockPrice(1, 1, 'Tomatoes', 'Nyanya', [], $unit, Money::kes($price), Quantity::milli($availableMilli));
    }

    public function testPriceMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->stock(price: 0);
    }

    public function testKgAcceptsQuarterKiloSteps(): void
    {
        self::assertTrue($this->stock()->validAmount(Quantity::milli(750)));
        self::assertFalse($this->stock()->validAmount(Quantity::milli(300)));
        self::assertFalse($this->stock()->validAmount(Quantity::zero()));
    }

    public function testBunchesAreWholeOnly(): void
    {
        self::assertFalse($this->stock(Unit::Bunch)->validAmount(Quantity::milli(1500)));
        self::assertTrue($this->stock(Unit::Bunch)->validAmount(Quantity::units(2)));
    }

    public function testCanFulfilChecksStock(): void
    {
        self::assertTrue($this->stock()->canFulfil(Quantity::units(3)));
        self::assertFalse($this->stock()->canFulfil(Quantity::milli(3250)));
    }

    public function testReserveReducesAvailability(): void
    {
        $after = $this->stock()->reserve(Quantity::units(1));
        self::assertSame(2000, $after->available->milli);
    }

    public function testReserveMoreThanAvailableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->stock()->reserve(Quantity::units(4));
    }

    public function testLineSnapshotsPrice(): void
    {
        $line = $this->stock()->lineFor(Quantity::milli(500));
        self::assertSame(60, $line->total()->amount);
        self::assertSame('Nyanya', $line->nameSw);
    }

    public function testSoldOut(): void
    {
        self::assertTrue($this->stock(availableMilli: 0)->isSoldOut());
    }
}
