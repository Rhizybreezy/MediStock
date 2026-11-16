<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class ProductRepository extends Repository
{
    /**
     * Product catalogue with stock figures. With a department scope the stock
     * figures only count the user's departments.
     */
    public function search(array $f, int $page, ?array $scope, int $perPage = 25): Paginator
    {
        [$stockJoin, $params] = $this->stockJoin($scope);
        $where = ['1=1'];
        if (!empty($f['q'])) {
            $where[] = '(p.name LIKE :q1 OR p.generic_name LIKE :q2 OR p.product_code LIKE :q3)';
            $params += ['q1' => Database::like($f['q']), 'q2' => Database::like($f['q']), 'q3' => Database::like($f['q'])];
        }
        if (!empty($f['category_id'])) {
            $where[] = 'p.category_id = :cat';
            $params['cat'] = (int) $f['category_id'];
        }
        if (($f['active'] ?? '') !== '' && $f['active'] !== null) {
            $where[] = 'p.is_active = :active';
            $params['active'] = (int) $f['active'];
        }
        if (($f['flag'] ?? '') === 'critical') {
            $where[] = 'p.is_critical = 1';
        } elseif (($f['flag'] ?? '') === 'controlled') {
            $where[] = 'p.is_controlled = 1';
        }
        $having = '';
        $stockStatus = $f['stock'] ?? '';
        if ($stockStatus === 'out') {
            $having = 'HAVING usable_qty <= 0';
        } elseif ($stockStatus === 'low') {
            $having = 'HAVING usable_qty > 0 AND p.reorder_level > 0 AND usable_qty <= p.reorder_level';
        } elseif ($stockStatus === 'in') {
            $having = 'HAVING usable_qty > 0';
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'code' => 'p.product_code', 'name' => 'p.name', 'category' => 'c.name',
            'stock' => 'usable_qty', 'value' => 'stock_value',
        ], 'name');

        $base = "SELECT p.id, p.product_code, p.name, p.generic_name, p.strength, p.dosage_form, p.is_active,
                        p.is_critical, p.is_controlled, p.storage_condition, p.reorder_level, p.min_level, p.max_level,
                        c.name AS category_name, u.code AS unit_code, u.name AS unit_name,
                        COALESCE(SUM(s.quantity), 0) AS on_hand,
                        COALESCE(SUM(CASE WHEN s.usable = 1 THEN s.quantity ELSE 0 END), 0) AS usable_qty,
                        COALESCE(SUM(s.quantity * s.unit_cost), 0) AS stock_value
                   FROM products p
                   JOIN inventory_categories c ON c.id = p.category_id
                   JOIN units_of_measure u     ON u.id = p.base_unit_id
                   $stockJoin
                  WHERE $w
                  GROUP BY p.id $having";
        return $this->paginate("$base $order", "SELECT COUNT(*) FROM ($base) t", $params, $page, $perPage);
    }

    /** LEFT JOIN of per-balance stock rows, optionally limited to departments. */
    private function stockJoin(?array $scope): array
    {
        $scopeSql = '';
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope, 'sc');
            $scopeSql = " AND l.department_id IN $in";
        }
        $sql = "LEFT JOIN (
                    SELECT sb.product_id, sb.quantity, b.unit_cost,
                           (b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                            AND l.type NOT IN ('quarantine','transit')) AS usable
                      FROM stock_balances sb
                      JOIN product_batches b     ON b.id = sb.batch_id
                      JOIN inventory_locations l ON l.id = sb.location_id
                     WHERE sb.quantity > 0 AND l.type <> 'quarantine' $scopeSql
                ) s ON s.product_id = p.id";
        return [$sql, $params];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT p.*, c.name AS category_name, c.code AS category_code, u.code AS unit_code, u.name AS unit_name,
                    u.allows_decimal, cu.full_name AS created_by_name
               FROM products p
               JOIN inventory_categories c ON c.id = p.category_id
               JOIN units_of_measure u     ON u.id = p.base_unit_id
               LEFT JOIN users cu          ON cu.id = p.created_by
              WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** Pack conversions (excluding the base unit, which is always factor 1). */
    public function packUnits(int $productId): array
    {
        return $this->db->fetchAll(
            'SELECT pu.*, u.code AS unit_code, u.name AS unit_name
               FROM product_units pu JOIN units_of_measure u ON u.id = pu.unit_id
              WHERE pu.product_id = :p ORDER BY pu.factor_to_base',
            ['p' => $productId]
        );
    }

    /** All units a quantity may be entered in: base unit (factor 1) + pack units. */
    public function unitChoices(int $productId): array
    {
        $p = $this->db->fetchOne(
            'SELECT u.id, u.code, u.name, u.allows_decimal FROM products p JOIN units_of_measure u ON u.id = p.base_unit_id WHERE p.id = :id',
            ['id' => $productId]
        );
        if (!$p) {
            return [];
        }
        $choices = [['unit_id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'factor' => 1.0, 'is_base' => true]];
        foreach ($this->packUnits($productId) as $pu) {
            $choices[] = ['unit_id' => (int) $pu['unit_id'], 'code' => $pu['unit_code'], 'name' => $pu['unit_name'], 'factor' => (float) $pu['factor_to_base'], 'is_base' => false];
        }
        return $choices;
    }

    public function create(array $data): int
    {
        return $this->db->insert('products', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('products', $data, 'id = :id', ['id' => $id]);
    }

    public function syncPackUnits(int $productId, array $units): void
    {
        $this->db->execute('DELETE FROM product_units WHERE product_id = :p', ['p' => $productId]);
        foreach ($units as $u) {
            $this->db->insert('product_units', [
                'product_id'       => $productId,
                'unit_id'          => (int) $u['unit_id'],
                'factor_to_base'   => $u['factor_to_base'],
                'gtin_barcode'     => $u['gtin_barcode'] ?: null,
                'is_purchase_unit' => (int) ($u['is_purchase_unit'] ?? 0),
                'is_issue_unit'    => (int) ($u['is_issue_unit'] ?? 0),
            ]);
        }
    }

    /** Lightweight search for pickers (active products only). */
    public function lookup(string $q, int $limit = 15): array
    {
        $limit = max(1, min(30, $limit));
        $like = Database::like($q);
        return $this->db->fetchAll(
            "SELECT p.id, p.product_code, p.name, p.generic_name, p.strength, p.dosage_form,
                    p.is_expiry_tracked, p.is_batch_tracked, p.storage_condition, u.code AS unit_code, u.allows_decimal
               FROM products p JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN product_units pu ON pu.product_id = p.id AND pu.gtin_barcode = :exact
              WHERE p.is_active = 1
                AND (p.name LIKE :q1 OR p.generic_name LIKE :q2 OR p.product_code LIKE :q3 OR pu.id IS NOT NULL)
              GROUP BY p.id
              ORDER BY (p.product_code = :exact2) DESC, p.name
              LIMIT $limit",
            ['q1' => $like, 'q2' => $like, 'q3' => $like, 'exact' => $q, 'exact2' => $q]
        );
    }

    /** Stock of one product per location (respecting scope). */
    public function stockByLocation(int $productId, ?array $scope): array
    {
        $sql = '';
        $params = ['p' => $productId];
        if ($scope !== null) {
            [$in, $sp] = Database::inClause($scope, 'sc');
            $sql = " AND l.department_id IN $in";
            $params += $sp;
        }
        return $this->db->fetchAll(
            "SELECT l.id AS location_id, l.name AS location_name, l.type AS location_type, d.name AS department_name,
                    SUM(sb.quantity) AS on_hand,
                    SUM(CASE WHEN b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                              AND l.type NOT IN ('quarantine','transit') THEN sb.quantity ELSE 0 END) AS usable,
                    SUM(sb.quantity * b.unit_cost) AS value,
                    MIN(CASE WHEN b.expiry_date >= CURDATE() THEN b.expiry_date END) AS next_expiry
               FROM stock_balances sb
               JOIN product_batches b     ON b.id = sb.batch_id
               JOIN inventory_locations l ON l.id = sb.location_id
               JOIN departments d         ON d.id = l.department_id
              WHERE sb.product_id = :p AND sb.quantity > 0 $sql
              GROUP BY l.id ORDER BY d.is_central_store DESC, d.name, l.name",
            $params
        );
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM products WHERE product_code = :c AND id <> :id',
            ['c' => $code, 'id' => $exceptId ?? 0]
        );
    }
}
