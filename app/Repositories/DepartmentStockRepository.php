<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Department-level stock monitoring: holdings vs department thresholds. */
final class DepartmentStockRepository extends Repository
{
    /** Summary card per department (within scope). */
    public function summaries(?array $scope): array
    {
        $sql = '';
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $sql = " AND d.id IN $in";
        }
        return $this->db->fetchAll(
            "SELECT d.id, d.code, d.name, d.type, d.is_central_store,
                    (SELECT COUNT(DISTINCT ds.product_id) FROM v_department_stock ds WHERE ds.department_id = d.id AND ds.usable_qty > 0) AS products_held,
                    (SELECT COALESCE(SUM(ds.value), 0) FROM v_department_stock ds WHERE ds.department_id = d.id) AS value,
                    (SELECT COUNT(*) FROM department_product_settings s
                       LEFT JOIN v_department_stock ds ON ds.department_id = s.department_id AND ds.product_id = s.product_id
                      WHERE s.department_id = d.id AND s.is_stocked = 1 AND s.reorder_level > 0 AND COALESCE(ds.usable_qty, 0) <= s.reorder_level) AS below_reorder,
                    (SELECT COUNT(*) FROM stock_requests r WHERE r.requesting_department_id = d.id AND r.status IN ('submitted','approved','partially_approved','partially_issued','issued')) AS open_requests,
                    (SELECT COUNT(*) FROM stock_transfers t WHERE t.destination_department_id = d.id AND t.status IN ('dispatched','partially_received')) AS incoming,
                    (SELECT COALESCE(SUM(m.quantity * m.unit_cost), 0) FROM stock_movements m JOIN inventory_locations ml ON ml.id = m.from_location_id
                      WHERE ml.department_id = d.id AND m.movement_type = 'CONSUMED' AND m.created_at >= CURDATE() - INTERVAL 30 DAY) AS consumption_30d
               FROM departments d
              WHERE d.is_active = 1 $sql
              ORDER BY d.is_central_store DESC, d.name",
            $params
        );
    }

    /**
     * Products relevant to a department: stocked there, or with department levels set.
     * Includes 30-day consumption for context.
     */
    public function levels(int $departmentId, string $filter = 'all', ?string $q = null): array
    {
        $where = '(ds.product_id IS NOT NULL OR s.product_id IS NOT NULL)';
        $params = ['d1' => $departmentId, 'd2' => $departmentId, 'd3' => $departmentId];
        if ($filter === 'low') {
            $where .= ' AND s.is_stocked = 1 AND s.reorder_level > 0 AND COALESCE(ds.usable_qty, 0) <= s.reorder_level';
        } elseif ($filter === 'catalogue') {
            $where = '1=1';
        }
        if ($q) {
            $where .= ' AND (p.name LIKE :q1 OR p.product_code LIKE :q2)';
            $params['q1'] = Database::like($q);
            $params['q2'] = Database::like($q);
        }
        return $this->db->fetchAll(
            "SELECT p.id, p.product_code, p.name, p.strength, u.code AS unit_code, c.name AS category_name,
                    COALESCE(ds.usable_qty, 0) AS usable_qty, COALESCE(ds.on_hand, 0) AS on_hand, COALESCE(ds.value, 0) AS value,
                    s.min_level, s.reorder_level, s.max_level, COALESCE(s.is_stocked, 0) AS is_stocked, s.id AS setting_id,
                    (SELECT COALESCE(SUM(m.quantity), 0) FROM stock_movements m JOIN inventory_locations ml ON ml.id = m.from_location_id
                      WHERE ml.department_id = :d3 AND m.product_id = p.id AND m.movement_type = 'CONSUMED'
                        AND m.created_at >= CURDATE() - INTERVAL 30 DAY) AS consumed_30d
               FROM products p
               JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN inventory_categories c ON c.id = p.category_id
               LEFT JOIN v_department_stock ds ON ds.product_id = p.id AND ds.department_id = :d1
               LEFT JOIN department_product_settings s ON s.product_id = p.id AND s.department_id = :d2
              WHERE p.is_active = 1 AND $where
              ORDER BY p.name
              LIMIT 500",
            $params
        );
    }
}
