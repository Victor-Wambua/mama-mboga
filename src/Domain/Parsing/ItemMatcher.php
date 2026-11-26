<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

use MamaMboga\Domain\Catalogue\StockPrice;

/**
 * Finds the board item a phrase refers to. Four passes, strictest first:
 *
 *  1. the phrase equals a name or alias (ignoring spaces and plurals);
 *  2. the phrase contains a whole alias ("fresh sukuma wiki");
 *  3. the phrase is part of an alias ("pilipili" of "pilipili hoho"), which
 *     is only ever a suggestion, never a confident match;
 *  4. a small edit distance ("sukumma", "kitungu") on longer words.
 *
 * Equal-quality candidates for different items are reported as ambiguous
 * rather than picked arbitrarily.
 */
final class ItemMatcher
{
    /** @var list<array{item: StockPrice, tokens: list<string>, joined: string}> */
    private array $index = [];

    /** @param list<StockPrice> $catalogue */
    public function __construct(array $catalogue)
    {
        foreach ($catalogue as $item) {
            $forms = array_unique([$item->nameEn, $item->nameSw, ...$item->aliases]);
            foreach ($forms as $form) {
                $tokens = self::tokens($form);
                if ($tokens !== []) {
                    $this->index[] = ['item' => $item, 'tokens' => $tokens, 'joined' => implode('', $tokens)];
                }
            }
        }
    }

    public function match(string $phrase): MatchResult
    {
        $tokens = self::tokens($phrase);
        if ($tokens === []) {
            return new MatchResult(LineStatus::Unmatched, [], 0.0);
        }
        $joined = implode('', $tokens);

        $exact = [];
        foreach ($this->index as $entry) {
            if ($entry['joined'] === $joined) {
                $exact[] = $entry['item'];
            }
        }
        if ($exact !== []) {
            return self::decide($exact, 1.0);
        }

        $contained = [];
        $longest = 0;
        foreach ($this->index as $entry) {
            if (self::contains($tokens, $entry['tokens'])) {
                $size = strlen($entry['joined']);
                if ($size > $longest) {
                    $longest = $size;
                    $contained = [];
                }
                if ($size === $longest) {
                    $contained[] = $entry['item'];
                }
            }
        }
        if ($contained !== []) {
            return self::decide($contained, 0.9);
        }

        $partial = [];
        foreach ($this->index as $entry) {
            if (self::contains($entry['tokens'], $tokens)) {
                $partial[] = $entry['item'];
            }
        }
        if ($partial !== []) {
            $unique = self::unique($partial);
            // Never confident: the customer may have meant something that is not on the board.
            return new MatchResult(count($unique) === 1 ? LineStatus::Suggested : LineStatus::Ambiguous, $unique, 0.6);
        }

        return $this->fuzzy($joined);
    }

    private function fuzzy(string $joined): MatchResult
    {
        $best = PHP_INT_MAX;
        $winners = [];
        foreach ($this->index as $entry) {
            $len = strlen($entry['joined']);
            $limit = $len >= 8 ? 2 : ($len >= 5 ? 1 : 0);
            if ($limit === 0 || $joined[0] !== $entry['joined'][0] || abs(strlen($joined) - $len) > $limit) {
                continue;
            }
            $distance = self::distance($joined, $entry['joined']);
            if ($distance > $limit) {
                continue;
            }
            if ($distance < $best) {
                $best = $distance;
                $winners = [];
            }
            if ($distance === $best) {
                $winners[] = $entry['item'];
            }
        }
        if ($winners === []) {
            return new MatchResult(LineStatus::Unmatched, [], 0.0);
        }

        $score = max(0.5, 1 - $best / max(1, strlen($joined)));

        return self::decide($winners, $score, fuzzy: true);
    }

    /** @param list<StockPrice> $items */
    private static function decide(array $items, float $score, bool $fuzzy = false): MatchResult
    {
        $unique = self::unique($items);

        return new MatchResult(
            count($unique) === 1 ? LineStatus::Matched : LineStatus::Ambiguous,
            $unique,
            $score,
            $fuzzy,
        );
    }

    /**
     * @param list<StockPrice> $items
     * @return list<StockPrice>
     */
    private static function unique(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (!isset($seen[$item->itemId])) {
                $seen[$item->itemId] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needle
     */
    private static function contains(array $haystack, array $needle): bool
    {
        $n = count($needle);
        for ($i = 0; $i + $n <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $text = TextNormaliser::line($text);
        preg_match_all('/[a-z]+/u', $text, $m);

        return array_map(self::stem(...), $m[0]);
    }

    private static function stem(string $word): string
    {
        $word = preg_replace('/(.)\1{2,}/u', '$1$1', $word) ?? $word;
        // Swahili plural of ki- nouns: vitunguu is kitunguu, viazi is kiazi.
        if (str_starts_with($word, 'vi') && strlen($word) > 4) {
            $word = 'ki' . substr($word, 2);
        }
        $n = strlen($word);

        return match (true) {
            $n > 4 && str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            $n > 4 && str_ends_with($word, 'oes') => substr($word, 0, -2),
            $n > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /** Damerau-Levenshtein (optimal string alignment): swaps count as one edit. */
    public static function distance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb];
    }
}
