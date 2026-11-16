<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

/**
 * Base repository: shared pagination and safe ORDER BY handling.
 * All SQL in the application lives in repository classes.
 */
abstract class Repository
{
    public function __construct(protected Database $db)
    {
    }

    /**
     * @param string $sql      SELECT without LIMIT
     * @param string $countSql SELECT COUNT(*) with the same FROM/WHERE
     */
    protected function paginate(string $sql, string $countSql, array $params, int $page, int $perPage = 25): Paginator
    {
        $perPage = max(5, min(100, $perPage));
        $total = (int) $this->db->fetchValue($countSql, $params);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $items = $this->db->fetchAll($sql . " LIMIT $perPage OFFSET $offset", $params);
        return new Paginator($items, $total, $page, $perPage);
    }

    /**
     * Whitelisted ORDER BY clause. $allowed maps request keys to SQL expressions,
     * so user input never reaches the SQL text directly.
     */
    protected static function orderBy(?string $sort, ?string $dir, array $allowed, string $defaultKey, string $defaultDir = 'asc'): string
    {
        $key = ($sort !== null && isset($allowed[$sort])) ? $sort : $defaultKey;
        $direction = in_array(strtolower((string) $dir), ['asc', 'desc'], true) ? strtoupper((string) $dir) : strtoupper($defaultDir);
        if ($sort === null || !isset($allowed[$sort])) {
            $direction = strtoupper($defaultDir);
        }
        return ' ORDER BY ' . $allowed[$key] . ' ' . $direction;
    }
}
