<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

interface SettingsRepository
{
    public function get(string $key, string $default = ''): string;

    public function set(string $key, string $value): void;
}
