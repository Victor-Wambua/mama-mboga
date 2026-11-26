<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

/** Turns messy chat text into something the tokeniser can rely on. */
final class TextNormaliser
{
    public static function line(string $line): string
    {
        $s = mb_strtolower($line);

        // List markers: "1. sukuma", "- nyanya", "• ndizi".
        $s = preg_replace('/^\s*(?:[-*•]|\d{1,2}[.)])\s+/u', '', $s) ?? $s;

        $s = strtr($s, [
            "\u{00A0}" => ' ', "\u{200B}" => '', "\u{200E}" => '', "\u{200F}" => '', "\u{FEFF}" => '',
            "\u{2019}" => '', "'" => '', "\u{00D7}" => ' x ', "\u{2013}" => ' ', "\u{2014}" => ' ', '-' => ' ', '_' => ' ',
            'á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        // Vulgar fractions, glued ("1½") or standalone.
        $s = preg_replace(['/(\d)\s*½/u', '/(\d)\s*¼/u', '/(\d)\s*¾/u'], ['$1.5', '$1.25', '$1.75'], $s) ?? $s;
        $s = strtr($s, ['½' => ' 0.5 ', '¼' => ' 0.25 ', '¾' => ' 0.75 ']);

        // Emoji and other symbols become spaces.
        $s = preg_replace('/[^\p{L}\p{N}\s.,;:+&\/]/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Strip a WhatsApp export prefix such as "[12/03/2026, 07:41] Njeri:". */
    public static function stripExportPrefix(string $line): string
    {
        $pattern = '/^\s*\[?\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4},?\s*\d{1,2}:\d{2}(?::\d{2})?(?:\s?[ap]m)?\]?\s*-?\s*[^:\n]{1,30}:\s*/iu';

        return preg_replace($pattern, '', $line) ?? $line;
    }
}
