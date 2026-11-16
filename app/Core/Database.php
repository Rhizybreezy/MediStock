<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO wrapper. Every query uses prepared statements with bound parameters.
 *
 * Table/column names cannot be bound as parameters, so the insert()/update()
 * helpers only accept identifiers that match a strict pattern, and they are
 * always supplied by application code, never by user input.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    /** @var callable[] run once the outermost transaction commits */
    private array $afterCommit = [];

    public function __construct(private array $config = [])
    {
        $this->config = $config ?: (array) Config::get('database', []);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset'] ?? 'utf8mb4');
            $this->pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Strict mode: reject invalid data instead of silently truncating it.
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @return array<int|string> one column as a flat list */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Execute a write statement and return affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $this->assertIdentifier($table);
        $columns = array_keys($data);
        array_walk($columns, fn ($c) => $this->assertIdentifier($c));
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns))
        );
        $this->query($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /** UPDATE table SET ... WHERE <whereSql>. $where params must use distinct names. */
    public function update(string $table, array $data, string $whereSql, array $whereParams = []): int
    {
        $this->assertIdentifier($table);
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $this->assertIdentifier($column);
            $sets[] = "`$column` = :set_$column";
            $params["set_$column"] = $value;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $whereSql);
        return $this->execute($sql, array_merge($params, $whereParams));
    }

    /**
     * Run $callback inside a transaction. Nested calls join the outer transaction.
     * Any exception rolls back everything and is re-thrown.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        // A transaction opened outside this class (e.g. by a test harness) is joined, not managed.
        $owner = $this->transactionLevel === 0 && !$pdo->inTransaction();
        if ($owner) {
            $pdo->beginTransaction();
        }
        $this->transactionLevel++;
        try {
            $result = $callback($this);
            $this->transactionLevel--;
            if ($owner) {
                $pdo->commit();
                $this->runAfterCommit();
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;
            if ($owner && $pdo->inTransaction()) {
                $pdo->rollBack();
                $this->afterCommit = [];
            }
            throw $e;
        }
    }

    /**
     * Run $callback after the current transaction commits (immediately if none is open).
     * Used for side effects such as cache invalidation that must not see uncommitted data.
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->transactionLevel > 0 || ($this->pdo !== null && $this->pdo->inTransaction())) {
            $this->afterCommit[] = $callback;
            return;
        }
        $callback();
    }

    private function runAfterCommit(): void
    {
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($callbacks as $cb) {
            $cb();
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /** Build "IN (:p0, :p1 ...)" placeholders + params for a list of values. */
    public static function inClause(array $values, string $prefix = 'in'): array
    {
        if ($values === []) {
            return ['(NULL)', []];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $placeholders[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $value;
        }
        return ['(' . implode(', ', $placeholders) . ')', $params];
    }

    /** Escape LIKE wildcards in user search text. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    private function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier [$name].");
        }
    }
}
