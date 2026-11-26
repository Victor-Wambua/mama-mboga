<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use PHPUnit\Framework\Assert;

/** Small helpers for pulling values out of rendered pages and links. */
final class Scrape
{
    public static function match(string $pattern, string $subject, int $group = 0): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[$group])) {
            Assert::fail("Pattern {$pattern} not found in page");
        }

        return $m[$group];
    }

    /** The decoded ?text= of a wa.me link. */
    public static function whatsappText(string $url): string
    {
        parse_str((string) parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);
        $text = $query['text'] ?? null;
        if (!is_string($text)) {
            Assert::fail('No text parameter in ' . $url);
        }

        return $text;
    }
}
