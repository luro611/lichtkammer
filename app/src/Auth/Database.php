<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use PDO;

/**
 * SQLite im Datenordner. Foreign Keys an, busy_timeout 5 s, journal_mode DELETE
 * (WAL nur auf ausdrücklichen Wunsch). Prüfen-und-Verbrauchen läuft immer in tx() (BEGIN IMMEDIATE).
 */
final class Database
{
    private int $depth = 0;

    private function __construct(public readonly PDO $pdo)
    {
    }

    public static function open(string $file, bool $wal = false): self
    {
        $isNew = !is_file($file);
        $old = umask(0077);
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } finally {
            umask($old);
        }
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA recursive_triggers = ON'); // sonst umgeht INSERT OR REPLACE den DELETE-Trigger von audit_log
        $pdo->exec('PRAGMA journal_mode = ' . ($wal ? 'WAL' : 'DELETE'));
        if ($isNew && $file !== ':memory:') {
            @chmod($file, 0600);
        }
        $db = new self($pdo);
        Schema::migrate($db);
        return $db;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function tx(callable $fn): mixed
    {
        if ($this->depth > 0) { // bereits in einer Transaktion: einfach mitlaufen
            return $fn();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $t;
        } finally {
            $this->depth = 0;
        }
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @param array<string|int,mixed> $params @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
