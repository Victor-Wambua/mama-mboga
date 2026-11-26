<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;

final readonly class Order
{
    /** @param list<OrderLine> $lines */
    public function __construct(
        public ?int $id,
        public string $reference,
        public string $boardDate,
        public ?int $customerId,
        public string $customerName,
        public PhoneNumber $customerPhone,
        public Fulfilment $fulfilment,
        public ?int $slotId,
        public string $address,
        public string $note,
        public OrderStatus $status,
        public PaymentMode $paymentMode,
        public ?string $settledVia,
        public array $lines,
        public string $source,
        public \DateTimeImmutable $createdAt,
    ) {
        if ($lines === []) {
            throw new \InvalidArgumentException('An order needs at least one item.');
        }
        if ($fulfilment === Fulfilment::Delivery && $slotId === null) {
            throw new \InvalidArgumentException('A delivery order needs a delivery slot.');
        }
        if ($fulfilment === Fulfilment::Delivery && trim($address) === '') {
            throw new \InvalidArgumentException('A delivery order needs an address or landmark.');
        }
    }

    public function total(): Money
    {
        $total = Money::zero();
        foreach ($this->lines as $line) {
            $total = $total->add($line->total());
        }

        return $total;
    }

    public function isDeni(): bool
    {
        return $this->paymentMode === PaymentMode::Deni;
    }

    public function transitionTo(OrderStatus $to): self
    {
        if (!$this->status->canMoveTo($to)) {
            throw new \DomainException("An order that is {$this->status->value} cannot become {$to->value}.");
        }

        return $this->with(status: $to);
    }

    public function withId(int $id): self
    {
        return $this->with(id: $id);
    }

    public function settledVia(string $via): self
    {
        return $this->with(settledVia: $via);
    }

    private function with(
        ?int $id = null,
        ?OrderStatus $status = null,
        ?string $settledVia = null,
    ): self {
        return new self(
            $id ?? $this->id,
            $this->reference,
            $this->boardDate,
            $this->customerId,
            $this->customerName,
            $this->customerPhone,
            $this->fulfilment,
            $this->slotId,
            $this->address,
            $this->note,
            $status ?? $this->status,
            $this->paymentMode,
            $settledVia ?? $this->settledVia,
            $this->lines,
            $this->source,
            $this->createdAt,
        );
    }
}
