<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class DepartmentRepository extends Repository
{
    public function search(array $f, int $page, int $perPage = 25): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(d.name LIKE :q1 OR d.code LIKE :q2)';
            $params += ['q1' => Database::like($f['q']), 'q2' => Database::like($f['q'])];
        }
        if (isset($f['active']) && $f['active'] !== '') {
            $where[] = 'd.is_active = :active';
            $params['active'] = (int) $f['active'];
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'code' => 'd.code', 'name' => 'd.name', 'type' => 'd.type', 'users' => 'user_count',
        ], 'name');
        $sql = "SELECT d.*, h.full_name AS head_name,
                       (SELECT COUNT(*) FROM user_departments ud WHERE ud.department_id = d.id) AS user_count,
                       (SELECT COUNT(*) FROM inventory_locations l WHERE l.department_id = d.id AND l.is_active = 1) AS location_count
                  FROM departments d LEFT JOIN users h ON h.id = d.head_user_id
                 WHERE $w $order";
        return $this->paginate($sql, "SELECT COUNT(*) FROM departments d WHERE $w", $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
    }

    /** Active departments for dropdowns, optionally limited to a scope (null = all). */
    public function options(?array $scope = null, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, code, name, type, is_central_store FROM departments WHERE 1=1';
        $params = [];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope);
            $sql .= " AND id IN $in";
        }
        return $this->db->fetchAll($sql . ' ORDER BY is_central_store DESC, name', $params);
    }

    public function centralStoreId(?int $exceptId = null): ?int
    {
        $id = $this->db->fetchValue(
            'SELECT id FROM departments WHERE is_central_store = 1 AND (:ex IS NULL OR id <> :ex2) LIMIT 1',
            ['ex' => $exceptId, 'ex2' => $exceptId]
        );
        return $id === null ? null : (int) $id;
    }

    public function create(array $data): int
    {
        return $this->db->insert('departments', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('departments', $data, 'id = :id', ['id' => $id]);
    }

    /** Stock currently held by the department (blocks deactivation). */
    public function stockOnHand(int $id): float
    {
        return (float) $this->db->fetchValue(
            'SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb
               JOIN inventory_locations l ON l.id = sb.location_id WHERE l.department_id = :id',
            ['id' => $id]
        );
    }
}
