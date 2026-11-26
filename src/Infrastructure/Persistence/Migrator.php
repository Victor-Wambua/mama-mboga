<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

/** Runs numbered .sql files once each, in order, each in its own transaction. */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> the migrations applied by this call */
    public function migrate(): array
    {
        $pdo = $this->db->pdo;
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');

        $done = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration {$file}");
            }

            $pdo->exec('BEGIN');
            try {
                $pdo->exec($sql);
                $this->db->execute('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, date('c')]);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw new \RuntimeException("Migration {$version} failed: " . $e->getMessage(), 0, $e);
            }
            $applied[] = $version;
        }

        return $applied;
    }
}
