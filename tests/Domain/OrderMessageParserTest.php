<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Parsing\ItemMatcher;
use MamaMboga\Domain\Parsing\LineIssue;
use MamaMboga\Domain\Parsing\LineStatus;
use MamaMboga\Domain\Parsing\OrderMessageParser;
use MamaMboga\Domain\Parsing\TextNormaliser;
use MamaMboga\Tests\Support\DemoBoard;
use PHPUnit\Framework\TestCase;

final class OrderMessageParserTest extends TestCase
{
    private function parse(string $message): \MamaMboga\Domain\Parsing\ParsedOrder
    {
        return (new OrderMessageParser())->parse($message, DemoBoard::stock());
    }

    public function testMatchedLineCarriesTheBoardItemAndPrice(): void
    {
        $line = $this->parse('nyanya 2kg')->lines[0];

        self::assertSame(LineStatus::Matched, $line->status);
        self::assertNotNull($line->item);
        self::assertSame('Tomatoes', $line->item->nameEn);
        self::assertSame(120, $line->item->price->amount);
        self::assertFalse($line->needsReview());
    }

    public function testAmbiguousLineListsTheAlternatives(): void
    {
        $line = $this->parse('viazi 2kg')->lines[0];

        self::assertSame(LineStatus::Ambiguous, $line->status);
        self::assertNull($line->item);
        self::assertEqualsCanonicalizing(['Irish potatoes', 'Sweet potatoes'], array_map(static fn ($a): string => $a->nameEn, $line->alternatives));
    }

    public function testUnmatchedLineIsFlagged(): void
    {
        $parsed = $this->parse('mchele 2kg');

        self::assertSame(LineStatus::Unmatched, $parsed->lines[0]->status);
        self::assertTrue($parsed->needsReview());
        self::assertCount(1, $parsed->flagged());
    }

    public function testUnitMismatchIsAnIssueNotASilentGuess(): void
    {
        $line = $this->parse('sukuma 2 kg')->lines[0];

        self::assertTrue($line->has(LineIssue::UnitMismatch));
        self::assertTrue($line->needsReview());
    }

    public function testFuzzyMatchIsInformationalOnly(): void
    {
        $line = $this->parse('sukumma 3')->lines[0];

        self::assertTrue($line->has(LineIssue::FuzzyMatch));
        self::assertFalse($line->needsReview());
    }

    public function testMergedDuplicatesAreInformationalAndSummed(): void
    {
        $parsed = $this->parse("sukuma 2\nsukuma 1");

        self::assertCount(1, $parsed->lines);
        self::assertSame('3', $parsed->lines[0]->quantity?->decimal());
        self::assertTrue($parsed->lines[0]->has(LineIssue::MergedDuplicates));
        self::assertFalse($parsed->needsReview());
    }

    public function testExtractsPhoneTimeAndAddress(): void
    {
        $parsed = $this->parse("sukuma 2, deliver to Roysambu\nsaa kumi na mbili jioni\n0700 000 009");

        self::assertSame('254700000009', $parsed->phone?->e164);
        self::assertSame('saa 12 jioni', $parsed->timeHint);
        self::assertSame('Roysambu', $parsed->addressHint);
        self::assertCount(1, $parsed->lines);
    }

    public function testAddressLine(): void
    {
        $parsed = $this->parse("nyanya 1kg\nAddress: Githurai 45 stage");

        self::assertSame('Githurai 45 Stage', $parsed->addressHint);
        self::assertCount(1, $parsed->lines);
    }

    public function testChatterOnlyMessageHasNoLines(): void
    {
        $parsed = $this->parse("Habari mama\nasante");

        self::assertSame([], $parsed->lines);
        self::assertTrue($parsed->needsReview());
        self::assertSame(['Habari mama', 'asante'], $parsed->ignored);
    }

    public function testShopNameWordsCanBeIgnored(): void
    {
        $with = (new OrderMessageParser())->parse('Hi Njeri nyanya 2kg', DemoBoard::stock(), ['Njeri']);

        self::assertCount(1, $with->lines);
        self::assertFalse($with->needsReview());
    }

    public function testEmptyCatalogueFlagsEverything(): void
    {
        $parsed = (new OrderMessageParser())->parse('nyanya 2kg', []);

        self::assertSame(LineStatus::Unmatched, $parsed->lines[0]->status);
    }

    public function testRawTextOfTheOriginalLineIsKept(): void
    {
        self::assertSame('Nyanya KILO MBILI!!', $this->parse('Nyanya KILO MBILI!!')->lines[0]->raw);
    }

    public function testWindowsLineEndingsAndBlankLines(): void
    {
        $parsed = $this->parse("sukuma 2\r\n\r\nnyanya 1kg\r\n");

        self::assertCount(2, $parsed->lines);
    }

    public function testVeryLongGarbageDoesNotBlowUp(): void
    {
        $parsed = $this->parse(str_repeat('blah 1 ', 500));

        self::assertNotEmpty($parsed->lines);
    }

    public function testNormaliserHandlesFractionsAndMarkers(): void
    {
        self::assertSame('nyanya 1.5kg', TextNormaliser::line('1. Nyanya 1½kg'));
        self::assertSame('0.5 kilo', TextNormaliser::line('• ½ kilo'));
        self::assertSame('sukuma wiki 2', TextNormaliser::line('Sukuma-Wiki 🥬 2'));
    }

    public function testExportPrefixIsStripped(): void
    {
        self::assertSame('sukuma 2', TextNormaliser::stripExportPrefix('[12/03/2026, 07:41:10] Njeri: sukuma 2'));
        self::assertSame('sukuma 2', TextNormaliser::stripExportPrefix('12/03/26, 7:41 pm - Wanjiru Kamau: sukuma 2'));
    }

    public function testEditDistance(): void
    {
        self::assertSame(0, ItemMatcher::distance('sukuma', 'sukuma'));
        self::assertSame(1, ItemMatcher::distance('sukuma', 'sukumma'));
        self::assertSame(1, ItemMatcher::distance('karoti', 'karoit')); // a swap counts once
        self::assertSame(3, ItemMatcher::distance('abc', 'xyz'));
    }

    public function testMatcherIgnoresPluralsAndSwahiliClassPrefix(): void
    {
        $matcher = new ItemMatcher(DemoBoard::stock());

        self::assertSame('Onions', $matcher->match('Onions')->item()?->nameEn);
        self::assertSame('Onions', $matcher->match('vitunguu')->item()?->nameEn);
        self::assertSame('Spring onions', $matcher->match('vitunguu maji')->item()?->nameEn);
        self::assertSame('Garlic', $matcher->match('kitunguu saumu')->item()?->nameEn);
    }

    public function testMatcherDoesNotGuessOnShortWords(): void
    {
        $matcher = new ItemMatcher(DemoBoard::stock());

        self::assertSame(LineStatus::Unmatched, $matcher->match('kal')->status);
        self::assertSame(LineStatus::Unmatched, $matcher->match('xyz')->status);
        self::assertSame(LineStatus::Unmatched, $matcher->match('')->status);
    }
}
