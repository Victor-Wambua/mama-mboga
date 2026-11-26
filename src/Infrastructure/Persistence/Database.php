<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\Transaction;
use PDO;

/** A thin PDO wrapper: parameterised queries only, and write transactions that take the lock up front. */
final class Database implements Transaction
{
    private int $depth = 0;

    public function __construct(public readonly \PDO $pdo)
    {
    }

    public static function connect(string $path): self
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return new self($pdo);
    }

    /**
     * @param array<int|string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @param array<int|string, scalar|null> $params */
    public function scalar(string $sql, array $params = []): int
    {
        $row = $this->one($sql, $params);

        return $row === null ? 0 : (int) array_values($row)[0];
    }

    /** @param array<int|string, scalar|null> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Roll back a transaction opened with a raw BEGIN. PDO::inTransaction() is not reliable for those
     * on every PHP version (8.3 does not see them), so just try, and ignore "no transaction is active".
     */
    public function rollback(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (\PDOException) {
            // SQLite already rolled back after a severe error.
        }
    }

    public function run(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }

        // IMMEDIATE takes the write lock now, so the stock/slot checks inside cannot race another writer.
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }
}
