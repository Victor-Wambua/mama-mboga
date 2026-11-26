<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Ledger;

enum EntryType: string
{
    /** Goods on credit: increases what the customer owes. */
    case Charge = 'charge';
    /** Cash or M-Pesa received: decreases it. */
    case Payment = 'payment';
    /** A written-off or corrected amount: decreases it, with a reason. */
    case CreditNote = 'credit_note';

    public function increasesBalance(): bool
    {
        return $this === self::Charge;
    }

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Charge',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
        };
    }
}
