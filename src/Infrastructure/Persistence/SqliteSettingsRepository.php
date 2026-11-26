<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\SettingsRepository;

final class SqliteSettingsRepository implements SettingsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE key = ?', [$key]);

        return $row === null ? $default : (string) $row['value'];
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }
}
