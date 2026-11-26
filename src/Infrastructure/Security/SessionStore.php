<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Security;

use MamaMboga\Infrastructure\Persistence\Database;

final class SessionStore
{
    public function __construct(private readonly Database $db)
    {
    }

    public function load(string $id, int $now): ?Session
    {
        $row = $this->db->one('SELECT data, expires_at FROM sessions WHERE id = ?', [$id]);
        if ($row === null || (int) $row['expires_at'] < $now) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);

        /** @var array<string, mixed> $data */
        $data = is_array($data) ? $data : [];

        return new Session($id, $data, false);
    }

    public function save(Session $session, int $expiresAt): void
    {
        $this->db->execute(
            'INSERT INTO sessions (id, data, expires_at) VALUES (?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET data = excluded.data, expires_at = excluded.expires_at',
            [$session->id(), json_encode($session->all(), JSON_THROW_ON_ERROR), $expiresAt],
        );
    }

    public function destroy(string $id): void
    {
        $this->db->execute('DELETE FROM sessions WHERE id = ?', [$id]);
    }

    public function collectGarbage(int $now): void
    {
        $this->db->execute('DELETE FROM sessions WHERE expires_at < ?', [$now]);
    }
}
