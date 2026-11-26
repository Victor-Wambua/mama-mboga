<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

/**
 * Reads a pasted WhatsApp order ("sukuma 2 bunch, nyanya kilo moja na nusu,
 * 2 ndizi, deliver 6pm") into draft order lines against today's price board.
 *
 * Pipeline, per message line:
 *   1. normalise (case, emoji, fractions, list markers, export prefixes);
 *   2. lift out chatter that is not an order: phone numbers and delivery times;
 *   3. rewrite number words ("kumi na mbili" -> 12, "na nusu" -> +0.5);
 *   4. split on commas, "na", "and", "+";
 *   5. group tokens into "quantity, unit, item" clusters, whichever order
 *      the customer wrote them in;
 *   6. match each item phrase to the board and sanity-check amount and unit.
 *
 * It never guesses silently: anything partial, ambiguous or odd is flagged
 * for the owner to fix before the order is created.
 */
final class OrderMessageParser
{
    private const MAX_UNITS = 100;

    /**
     * @param list<StockPrice> $catalogue today's price board
     * @param list<string>     $ignoreWords extra chatter words, e.g. the shop's own name ("Hi Njeri")
     */
    public function parse(string $message, array $catalogue, array $ignoreWords = []): ParsedOrder
    {
        $ignoreWords = array_map(mb_strtolower(...), $ignoreWords);
        $matcher = new ItemMatcher($catalogue);
        $lines = [];
        $ignored = [];
        $phone = null;
        $timeHint = null;
        $address = null;

        foreach (preg_split('/\R/u', $message) ?: [] as $rawLine) {
            $original = trim($rawLine);
            if ($original === '') {
                continue;
            }

            $text = TextNormaliser::line(TextNormaliser::stripExportPrefix($original));

            if (preg_match('/(?<![\d.])(?:\+?254|0)\s?[17](?:\s?\d){8}(?!\d)/', $text, $m) === 1) {
                $phone ??= PhoneNumber::tryParse($m[0]);
                $text = str_replace($m[0], ' ', $text);
            }

            $text = $this->numberWords($text);
            [$text, $hint] = $this->extractTime($text);
            $timeHint ??= $hint;
            [$text, $where] = $this->extractAddress($text);
            $address ??= $where;
            $text = $this->fractions($text);
            $text = trim(preg_replace(['/(?<!\d)\.|\.(?!\d)/', '/[:\/]/', '/\s+/'], ' ', $text) ?? $text);

            $found = 0;
            foreach ($this->segments($text) as $segment) {
                foreach ($this->groups($this->tokens($segment, $ignoreWords)) as $group) {
                    $line = $this->readGroup($group, $original, $matcher);
                    if ($line === null) {
                        continue;
                    }
                    $lines[] = $line;
                    $found++;
                }
            }
            if ($found === 0) {
                $ignored[] = $original;
            }
        }

        return new ParsedOrder($this->mergeDuplicates($lines), $ignored, $phone, $timeHint, $address);
    }

    private function numberWords(string $text): string
    {
        // Decimal commas: "1,5kg" (but not "sukuma 2, nyanya 3").
        $text = preg_replace('/(?<=\d),(?=\d{1,2}(?!\d))/', '.', $text) ?? $text;

        $tens = implode('|', array_keys(Lexicon::TENS));
        $ones = implode('|', array_keys(Lexicon::UNITS_1_9));
        $text = preg_replace_callback(
            "/\\b($tens)\\s+na\\s+($ones)\\b/u",
            static fn (array $m): string => (string) (Lexicon::TENS[$m[1]] + Lexicon::UNITS_1_9[$m[2]]),
            $text,
        ) ?? $text;

        $words = implode('|', array_keys(Lexicon::NUMBERS));

        return preg_replace_callback(
            "/\\b($words)\\b/u",
            static fn (array $m): string => (string) Lexicon::NUMBERS[$m[1]],
            $text,
        ) ?? $text;
    }

    /** @return array{0: string, 1: ?string} */
    private function extractTime(string $text): array
    {
        $patterns = [
            '/\bsaa\s+\d{1,2}(?:\s+na\s+nusu)?(?:\s+(?:asubuhi|mchana|jioni|usiku))?/u',
            '/\b(?:saa\s+)?\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b/u',
            '/\b\d{1,2}:\d{2}\b/u',
        ];
        $hint = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $hint ??= trim($m[0]);
                $text = str_replace($m[0], ' ', $text);
            }
        }

        return [$text, $hint];
    }

    /**
     * "send to Kasarani", "address: Roysambu": a place, not produce.
     *
     * @return array{0: string, 1: ?string}
     */
    private function extractAddress(string $text): array
    {
        if (preg_match('/^(?:address|location|anwani|mahali)\s*:?\s*(.+)$/u', $text, $m) === 1) {
            return ['', ucwords(trim($m[1]))];
        }
        if (preg_match('/\b(?:send|deliver|bring|peleka|nipelekee|leta)\s+(?:it\s+)?(?:to|at|kwa|hadi)\s+(.+)$/u', $text, $m) === 1) {
            return [trim(substr($text, 0, -strlen($m[0]))), ucwords(trim($m[1]))];
        }

        return [$text, null];
    }

    private function fractions(string $text): string
    {
        $text = preg_replace_callback(
            '/(\d+)\s+(\d)\/(\d)\b/',
            fn (array $m): string => self::decimalOf((int) $m[1], (int) $m[2], (int) $m[3]),
            $text,
        ) ?? $text;
        $text = preg_replace_callback(
            '/\b(\d)\/(\d)\b/',
            fn (array $m): string => self::decimalOf(0, (int) $m[1], (int) $m[2]),
            $text,
        ) ?? $text;

        foreach ([['(?:na\s+nusu|and\s+(?:a\s+)?half)', 500], ['(?:na\s+robo|and\s+(?:a\s+)?quarter)', 250]] as [$pattern, $extra]) {
            $text = preg_replace_callback(
                '/(\d+(?:\.\d+)?)\s+' . $pattern . '\b/u',
                static function (array $m) use ($extra): string {
                    try {
                        return Quantity::fromDecimal($m[1])->add(Quantity::milli($extra))->decimal();
                    } catch (\InvalidArgumentException) {
                        return $m[0];
                    }
                },
                $text,
            ) ?? $text;
        }

        return preg_replace(['/\b(?:nusu|half)\b/u', '/\b(?:robo|quarter)\b/u'], ['0.5', '0.25'], $text) ?? $text;
    }

    private static function decimalOf(int $whole, int $num, int $den): string
    {
        if ($den === 0) {
            return (string) $whole;
        }
        $milli = $whole * 1000 + intdiv($num * 1000, $den);

        return Quantity::milli($milli)->decimal();
    }

    /** @return list<string> */
    private function segments(string $text): array
    {
        $parts = preg_split('/\s*(?:[;,+&]|\b(?:na|and|plus|pia|then|halafu)\b)\s*/u', $text) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param list<string> $ignoreWords
     * @return list<array{type: string, value: string}>
     */
    private function tokens(string $segment, array $ignoreWords): array
    {
        preg_match_all('/\d+(?:\.\d+)?|[a-z]+/u', $segment, $m);
        $tokens = [];
        foreach ($m[0] as $word) {
            if (ctype_digit($word[0])) {
                $tokens[] = ['type' => 'num', 'value' => $word];
            } elseif (isset(Lexicon::UNITS[$word])) {
                $tokens[] = ['type' => 'unit', 'value' => Lexicon::UNITS[$word]];
            } elseif (!in_array($word, Lexicon::STOPWORDS, true) && !in_array($word, $ignoreWords, true)) {
                $tokens[] = ['type' => 'word', 'value' => $word];
            }
        }

        return $tokens;
    }

    /**
     * Split a token stream into one cluster per requested item. "sukuma 2
     * nyanya 3" and "2 sukuma 3 nyanya" both become two clusters.
     *
     * @param list<array{type: string, value: string}> $tokens
     * @return list<list<array{type: string, value: string}>>
     */
    private function groups(array $tokens): array
    {
        $groups = [];
        $current = [];
        $style = null;
        $hasNum = false;
        $hasWord = false;

        foreach ($tokens as $token) {
            $startsNew = match ($token['type']) {
                'word' => $style === 'suffix' && $hasNum,
                'num' => $style === 'prefix' && $hasWord,
                default => false,
            };
            if ($startsNew) {
                $groups[] = $current;
                $current = [];
                $style = null;
                $hasNum = false;
                $hasWord = false;
            }
            if ($token['type'] === 'word') {
                $style ??= 'suffix';
                $hasWord = true;
            } elseif ($token['type'] === 'num') {
                $style ??= 'prefix';
                $hasNum = true;
            }
            $current[] = $token;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** @param list<array{type: string, value: string}> $group */
    private function readGroup(array $group, string $original, ItemMatcher $matcher): ?ParsedLine
    {
        $words = [];
        $numIndex = null;
        $unitIndex = null;
        foreach ($group as $token) {
            if ($token['type'] === 'word') {
                $words[] = $token['value'];
            }
        }
        foreach ($group as $i => $token) {
            if ($token['type'] !== 'num') {
                continue;
            }
            $neighbour = [$i - 1, $i + 1];
            foreach ($neighbour as $n) {
                if (($group[$n]['type'] ?? '') === 'unit') {
                    $numIndex = $i;
                    $unitIndex = $n;
                    break 2;
                }
            }
            $numIndex ??= $i;
        }
        if ($unitIndex === null) {
            foreach ($group as $i => $token) {
                if ($token['type'] === 'unit') {
                    $unitIndex = $i;
                    break;
                }
            }
        }

        $fragment = implode(' ', array_map(static fn (array $t): string => $t['value'], $group));
        $number = $numIndex === null ? null : $group[$numIndex]['value'];
        $unitKey = $unitIndex === null ? null : $group[$unitIndex]['value'];

        if ($words === []) {
            if ($unitKey === null) {
                return null; // a stray number: house number, time without am/pm...
            }

            return $this->line($original, $fragment, new MatchResult(LineStatus::Unmatched, [], 0.0), $number, $unitKey);
        }

        $result = $matcher->match(implode(' ', $words));

        return $this->line($original, $fragment, $result, $number, $unitKey);
    }

    private function line(string $original, string $fragment, MatchResult $result, ?string $number, ?string $unitKey): ParsedLine
    {
        $issues = [];
        $quantity = null;
        $written = null;
        $unsupportedUnit = false;

        if ($unitKey === 'g') {
            $written = Unit::Kg;
            if ($number !== null && ctype_digit($number) && strlen($number) <= 6) {
                $quantity = Quantity::milli((int) $number);
            } else {
                $issues[] = LineIssue::UnreadableQuantity;
            }
        } else {
            $written = $unitKey === null || $unitKey === 'other' ? null : Unit::from($unitKey);
            if ($unitKey === 'other') {
                $unsupportedUnit = true;
            }
            if ($number !== null) {
                try {
                    $quantity = Quantity::fromDecimal($number);
                } catch (\InvalidArgumentException) {
                    $issues[] = LineIssue::UnreadableQuantity;
                }
            } elseif ($written !== null) {
                $quantity = Quantity::units(1); // "kilo ya nyanya" means one kilo
            }
        }

        $item = $result->item();
        $unit = $item->unit ?? $written;
        if ($quantity === null && !in_array(LineIssue::UnreadableQuantity, $issues, true)) {
            $quantity = Quantity::units(1);
            $issues[] = LineIssue::QuantityAssumed;
        }

        if ($quantity !== null) {
            if ($quantity->isZero()) {
                $issues[] = LineIssue::ZeroQuantity;
            } elseif ($item !== null && ($unsupportedUnit || ($written !== null && $written !== $item->unit))) {
                $issues[] = LineIssue::UnitMismatch;
            } elseif ($unit !== null && !$quantity->isMultipleOf($unit->increment())) {
                $issues[] = LineIssue::FractionalNotAllowed;
            }
            if ($quantity->milli > self::MAX_UNITS * 1000) {
                $issues[] = LineIssue::LargeQuantity;
            }
        }
        if ($result->fuzzy && $result->status === LineStatus::Matched) {
            $issues[] = LineIssue::FuzzyMatch;
        }

        return new ParsedLine(
            $original,
            $fragment,
            $result->status,
            $item,
            $result->status === LineStatus::Ambiguous || $result->status === LineStatus::Suggested ? $result->candidates : [],
            $quantity,
            $written,
            $issues,
            $result->score,
        );
    }

    /**
     * @param list<ParsedLine> $lines
     * @return list<ParsedLine>
     */
    private function mergeDuplicates(array $lines): array
    {
        $merged = [];
        $positions = [];
        foreach ($lines as $line) {
            $mergeable = $line->status === LineStatus::Matched && !$line->needsReview() && $line->item !== null && $line->quantity !== null;
            if (!$mergeable) {
                $merged[] = $line;
                continue;
            }
            /** @var StockPrice $item */
            $item = $line->item;
            $key = $item->itemId . ':' . $item->unit->value;
            if (!isset($positions[$key])) {
                $positions[$key] = count($merged);
                $merged[] = $line;
                continue;
            }
            $first = $merged[$positions[$key]];
            $merged[$positions[$key]] = new ParsedLine(
                $first->raw . ' + ' . $line->raw,
                $first->fragment . ' + ' . $line->fragment,
                LineStatus::Matched,
                $item,
                [],
                ($first->quantity ?? Quantity::zero())->add($line->quantity ?? Quantity::zero()),
                $first->writtenUnit,
                array_values(array_unique([...$first->issues, LineIssue::MergedDuplicates], SORT_REGULAR)),
                min($first->confidence, $line->confidence),
            );
        }

        return array_values($merged);
    }
}
