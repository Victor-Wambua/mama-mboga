<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function valid(): iterable
    {
        yield 'integer' => ['2', 2000];
        yield 'decimal' => ['1.5', 1500];
        yield 'decimal comma' => ['0,25', 250];
        yield 'three places' => ['0.125', 125];
        yield 'padded' => [' 3 ', 3000];
        yield 'one place' => ['2.5', 2500];
    }

    #[DataProvider('valid')]
    public function testParsesDecimals(string $text, int $milli): void
    {
        self::assertSame($milli, Quantity::fromDecimal($text)->milli);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['two'];
        yield 'negative' => ['-1'];
        yield 'four decimals' => ['1.2345'];
        yield 'two dots' => ['1.2.3'];
        yield 'huge' => ['12345678'];
    }

    #[DataProvider('invalid')]
    public function testRejectsGarbage(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quantity::fromDecimal($text);
    }

    public function testDecimalTrimsZeros(): void
    {
        self::assertSame('2', Quantity::units(2)->decimal());
        self::assertSame('1.5', Quantity::milli(1500)->decimal());
        self::assertSame('0.25', Quantity::milli(250)->decimal());
        self::assertSame('0.05', Quantity::milli(50)->decimal());
        self::assertSame('0', Quantity::zero()->decimal());
    }

    public function testArithmeticAndComparison(): void
    {
        $a = Quantity::milli(1500);
        self::assertSame(2000, $a->add(Quantity::milli(500))->milli);
        self::assertSame(1000, $a->subtract(Quantity::milli(500))->milli);
        self::assertTrue($a->atLeast(Quantity::milli(1500)));
        self::assertFalse($a->atLeast(Quantity::milli(1501)));
        self::assertTrue($a->isMultipleOf(250));
        self::assertFalse($a->isMultipleOf(1000));
    }

    public function testCannotGoNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quantity::milli(100)->subtract(Quantity::milli(200));
    }

    public function testFormatsWithUnit(): void
    {
        self::assertSame('1.5 kg', Quantity::milli(1500)->format(Unit::Kg));
        self::assertSame('3 bunch', Quantity::units(3)->format(Unit::Bunch));
    }

    public function testUnitIncrements(): void
    {
        self::assertSame(250, Unit::Kg->increment());
        self::assertSame(1000, Unit::Heap->increment());
        self::assertSame(500, Unit::Kg->step());
        self::assertSame(Unit::Heap, Unit::tryFromLabel(' Heap '));
        self::assertNull(Unit::tryFromLabel('litre'));
        self::assertSame('rundo', Unit::Heap->labelSw());
    }
}
