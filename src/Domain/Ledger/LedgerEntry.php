<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Ledger;

use MamaMboga\Domain\Money\Money;

/** One immutable row of a customer's deni book. The amount is always positive. */
final readonly class LedgerEntry
{
    public function __construct(
        public ?int $id,
        public int $customerId,
        public EntryType $type,
        public Money $amount,
        public \DateTimeImmutable $occurredAt,
        public ?string $method = null,
        public ?string $receiptCode = null,
        public ?int $orderId = null,
        public string $note = '',
    ) {
        if (!$amount->isPositive()) {
            throw new \InvalidArgumentException('A ledger amount must be more than zero.');
        }
        if ($type === EntryType::Payment && ($receiptCode === null || $receiptCode === '')) {
            throw new \InvalidArgumentException('A payment needs a receipt code.');
        }
        if ($type === EntryType::Payment && !in_array($method, ['cash', 'mpesa'], true)) {
            throw new \InvalidArgumentException('A payment is either cash or M-Pesa.');
        }
        if ($type === EntryType::CreditNote && trim($note) === '') {
            throw new \InvalidArgumentException('A credit note needs a reason.');
        }
    }

    /** The effect on what the customer owes. */
    public function signed(): Money
    {
        return $this->type->increasesBalance() ? $this->amount : Money::zero()->subtract($this->amount);
    }
}
