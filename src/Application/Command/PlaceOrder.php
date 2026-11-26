<?php

declare(strict_types=1);

namespace MamaMboga\Application\Command;

/** Raw, untrusted input for a new order: validated by OrderService. */
final readonly class PlaceOrder
{
    /** @param array<int|string, string> $quantities board entry id => quantity as typed */
    public function __construct(
        public string $name,
        public string $phone,
        public string $fulfilment,
        public ?int $slotId,
        public string $address,
        public string $note,
        public array $quantities,
        public bool $onDeni = false,
        public string $source = 'web',
    ) {
    }
}
