<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Money\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sameNumber(): iterable
    {
        yield 'local' => ['0700000001'];
        yield 'spaced' => ['0700 000 001'];
        yield 'dashed' => ['0700-000-001'];
        yield 'plus' => ['+254 700 000 001'];
        yield 'no plus' => ['254700000001'];
        yield 'bare' => ['700000001'];
    }

    #[DataProvider('sameNumber')]
    public function testNormalisesToE164(string $input): void
    {
        self::assertSame('254700000001', PhoneNumber::parse($input)->e164);
    }

    /** @return iterable<string, array{string}> */
    public static function bad(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['0700 00'];
        yield 'landline style' => ['020 123 4567'];
        yield 'letters' => ['abc'];
        yield 'too long' => ['07000000011'];
    }

    #[DataProvider('bad')]
    public function testRejectsInvalid(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNumber::parse($input);
    }

    public function testLocalFormat(): void
    {
        self::assertSame('0700 000 001', PhoneNumber::parse('+254700000001')->local());
        self::assertSame('0110 123 456', PhoneNumber::parse('0110123456')->local());
    }

    public function testTryParseAndEquals(): void
    {
        self::assertNull(PhoneNumber::tryParse('nope'));
        self::assertTrue(PhoneNumber::parse('0700000001')->equals(PhoneNumber::parse('+254 700 000 001')));
    }
}
