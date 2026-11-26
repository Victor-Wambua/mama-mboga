<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Domain\Slot\DeliverySlot;

/** Makes sure every day has its delivery windows, and lets the owner change their size. */
final class SlotPlanner
{
    private const DEFAULTS = [['10:00', '12:00', 6], ['12:00', '14:00', 6], ['16:00', '18:00', 6], ['18:00', '20:00', 6]];

    public function __construct(
        private readonly SlotRepository $slots,
        private readonly SettingsRepository $settings,
    ) {
    }

    /** @return list<DeliverySlot> */
    public function ensure(string $date): array
    {
        $existing = $this->slots->forDate($date);
        if ($existing === []) {
            $capacity = max(0, (int) $this->settings->get('default_slot_capacity', '6'));
            $this->slots->ensureDefaults($date, array_map(
                static fn (array $d): array => [$d[0], $d[1], $capacity],
                self::DEFAULTS,
            ));
            $existing = $this->slots->forDate($date);
        }

        return $existing;
    }

    public function setCapacity(int $slotId, int $capacity): void
    {
        $this->slots->setCapacity($slotId, max(0, min(99, $capacity)));
    }
}
