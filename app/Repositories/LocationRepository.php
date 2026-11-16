<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class LocationRepository extends Repository
{
    public function search(array $f, int $page, int $perPage = 25): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(l.name LIKE :q1 OR l.code LIKE :q2)';
            $params += ['q1' => Database::like($f['q']), 'q2' => Database::like($f['q'])];
        }
        if (!empty($f['department_id'])) {
            $where[] = 'l.department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        if (!empty($f['type'])) {
            $where[] = 'l.type = :type';
            $params['type'] = $f['type'];
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'code' => 'l.code', 'name' => 'l.name', 'department' => 'd.name', 'type' => 'l.type',
        ], 'department');
        $sql = "SELECT l.*, d.name AS department_name,
                       (SELECT COALESCE(SUM(sb.quantity),0) FROM stock_balances sb WHERE sb.location_id = l.id) AS stock_qty
                  FROM inventory_locations l JOIN departments d ON d.id = l.department_id
                 WHERE $w $order, l.name";
        return $this->paginate($sql, "SELECT COUNT(*) FROM inventory_locations l WHERE $w", $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT l.*, d.name AS department_name FROM inventory_locations l JOIN departments d ON d.id = l.department_id WHERE l.id = :id',
            ['id' => $id]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert('inventory_locations', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('inventory_locations', $data, 'id = :id', ['id' => $id]);
    }

    public function stockOnHand(int $id): float
    {
        return (float) $this->db->fetchValue('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE location_id = :id', ['id' => $id]);
    }

    public function nextCodeFor(string $deptCode, string $suffix): string
    {
        $base = strtoupper($deptCode . '-' . $suffix);
        $code = $base;
        $i = 2;
        while ($this->db->fetchValue('SELECT 1 FROM inventory_locations WHERE code = :c', ['c' => $code])) {
            $code = $base . $i++;
        }
        return $code;
    }

    /**
     * Active locations for dropdowns, grouped-friendly (with department name).
     * @param int[]|null $departmentScope null = all departments
     * @param string[] $excludeTypes
     */
    public function options(?array $departmentScope = null, array $excludeTypes = ['transit'], ?int $departmentId = null): array
    {
        $sql = 'SELECT l.id, l.code, l.name, l.type, l.is_cold_chain, l.department_id, d.name AS department_name, d.is_central_store
                  FROM inventory_locations l JOIN departments d ON d.id = l.department_id
                 WHERE l.is_active = 1 AND d.is_active = 1';
        $params = [];
        if ($departmentScope !== null) {
            [$in, $p] = Database::inClause($departmentScope, 'sc');
            $sql .= " AND l.department_id IN $in";
            $params += $p;
        }
        if ($excludeTypes) {
            [$in, $p] = Database::inClause($excludeTypes, 'ex');
            $sql .= " AND l.type NOT IN $in";
            $params += $p;
        }
        if ($departmentId !== null) {
            $sql .= ' AND l.department_id = :dept';
            $params['dept'] = $departmentId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY d.is_central_store DESC, d.name, l.name', $params);
    }

    /** The single system-wide In Transit location. */
    public function transitId(): int
    {
        $id = $this->db->fetchValue("SELECT id FROM inventory_locations WHERE type = 'transit' ORDER BY id LIMIT 1");
        if ($id === null) {
            throw new \App\Exceptions\BusinessRuleException('The In Transit location is missing. Run php bin/console db:seed.');
        }
        return (int) $id;
    }

    /** Quarantine location for a department (falls back to the central store quarantine). */
    public function quarantineFor(int $departmentId): ?int
    {
        $id = $this->db->fetchValue(
            "SELECT l.id FROM inventory_locations l JOIN departments d ON d.id = l.department_id
              WHERE l.type = 'quarantine' AND l.is_active = 1 AND (l.department_id = :d OR d.is_central_store = 1)
              ORDER BY (l.department_id = :d2) DESC LIMIT 1",
            ['d' => $departmentId, 'd2' => $departmentId]
        );
        return $id === null ? null : (int) $id;
    }
}
