<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
