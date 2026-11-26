<?php

declare(strict_types=1);

namespace MamaMboga\Application\Messaging;

use MamaMboga\Domain\Ledger\Aging;
use MamaMboga\Domain\Money\Money;

/** A polite deni reminder. Warmer for fresh balances, clearer when it is old. */
final class ReminderMessage
{
    public static function compose(string $customerName, Money $balance, Aging $aging, string $shopName, string $statementUrl, string $lang = 'sw'): string
    {
        $first = explode(' ', trim($customerName))[0] ?: 'customer';
        $old = $aging->over30->isPositive() || $aging->days15to30->isPositive();
        $amount = $balance->format();

        if ($lang === 'en') {
            $body = $old
                ? "your deni balance with {$shopName} is {$amount}, and some of it is more than two weeks old. Could you settle it, or tell us when you can? M-Pesa or cash both work."
                : "a friendly reminder that your deni balance with {$shopName} is {$amount}. Pay whenever suits you, M-Pesa or cash.";

            return "Hello {$first}, {$body}\nYour statement: {$statementUrl}\nThank you!";
        }

        $body = $old
            ? "deni yako ya {$shopName} ni {$amount}, na sehemu yake ni ya zaidi ya wiki mbili. Tafadhali lipa, au tujulishe utakapoweza. M-Pesa au pesa taslimu zote ni sawa."
            : "kumbusho la heshima: deni lako la {$shopName} ni {$amount}. Lipa wakati wowote unapoweza, M-Pesa au pesa taslimu.";

        return "Habari {$first}, {$body}\nStatement yako: {$statementUrl}\nAsante sana!";
    }
}
