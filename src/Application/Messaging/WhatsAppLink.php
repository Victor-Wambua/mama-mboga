<?php

declare(strict_types=1);

namespace MamaMboga\Application\Messaging;

/**
 * Builds click-to-chat links. There is no WhatsApp API involved: the link
 * opens the customer's (or owner's) own WhatsApp with the text pre-filled
 * and they press send themselves.
 */
final class WhatsAppLink
{
    /** Longest text we pre-fill; very long URLs get cut off by some phones. */
    public const MAX_TEXT = 1500;

    /** @param string $e164 digits with country code, e.g. 254700000001 */
    public static function to(string $e164, string $text): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT - 1) . '…';
        }

        // rawurlencode percent-encodes the UTF-8 bytes (and newlines as %0A), as wa.me requires.
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
    }
}
