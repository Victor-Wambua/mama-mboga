<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAddAndSubtract(): void
    {
        self::assertSame(150, Money::kes(100)->add(Money::kes(50))->amount);
        self::assertSame(-20, Money::kes(30)->subtract(Money::kes(50))->amount);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function lineTotals(): iterable
    {
        yield 'whole units' => [120, 2000, 240];
        yield 'half a kilo' => [120, 500, 60];
        yield 'quarter kilo rounds up at .5' => [110, 250, 28]; // 27.5
        yield 'quarter kilo rounds down below .5' => [90, 250, 23]; // 22.5 -> 23 (half up)
        yield 'one and a half' => [85, 1500, 128]; // 127.5
        yield 'three quarters' => [130, 750, 98]; // 97.5
        yield 'zero quantity' => [130, 0, 0];
        yield 'large' => [150, 999000, 149850];
    }

    #[DataProvider('lineTotals')]
    public function testTimesMilliRoundsHalfUp(int $price, int $milli, int $expected): void
    {
        self::assertSame($expected, Money::kes($price)->timesMilli($milli)->amount);
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::kes(10)->timesMilli(-1);
    }

    public function testFormatting(): void
    {
        self::assertSame('KSh 1,250', Money::kes(1250)->format());
        self::assertSame('KSh 0', Money::zero()->format());
        self::assertSame('-KSh 40', Money::kes(-40)->format());
        self::assertSame('KSh 12', (string) Money::kes(12));
    }

    public function testComparisons(): void
    {
        self::assertTrue(Money::kes(5)->lessThan(Money::kes(6)));
        self::assertTrue(Money::kes(6)->greaterThan(Money::kes(5)));
        self::assertTrue(Money::kes(5)->equals(Money::kes(5)));
        self::assertSame(3, Money::kes(3)->min(Money::kes(9))->amount);
        self::assertTrue(Money::kes(-1)->isNegative());
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(Money::zero()->isPositive());
    }
}
