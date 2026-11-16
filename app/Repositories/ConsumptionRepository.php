<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

/** Consumption records = stock_transactions of type 'consumption' with CONSUMED movements. */
final class ConsumptionRepository extends Repository
{
    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ["t.type = 'consumption'"];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "t.department_id IN $in";
        }
        if (!empty($f['department_id'])) {
            $where[] = 't.department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        if (!empty($f['purpose'])) {
            $where[] = 't.purpose = :purpose';
            $params['purpose'] = $f['purpose'];
        }
        if (!empty($f['from'])) {
            $where[] = 't.created_at >= :dfrom';
            $params['dfrom'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 't.created_at <= :dto';
            $params['dto'] = $f['to'] . ' 23:59:59';
        }
        $w = implode(' AND ', $where);
        $sql = "SELECT t.id, t.txn_number, t.created_at, t.purpose, t.reason, t.notes, d.name AS department_name, u.full_name AS user_name,
                       GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS products,
                       MIN(l.name) AS location_name,
                       SUM(m.quantity * m.unit_cost) AS value, COUNT(m.id) AS line_count
                  FROM stock_transactions t
                  JOIN departments d ON d.id = t.department_id
                  JOIN users u ON u.id = t.performed_by
                  JOIN stock_movements m ON m.transaction_id = t.id
                  JOIN products p ON p.id = m.product_id
                  LEFT JOIN inventory_locations l ON l.id = m.from_location_id
                 WHERE $w GROUP BY t.id ORDER BY t.id DESC";
        return $this->paginate($sql, "SELECT COUNT(*) FROM stock_transactions t WHERE $w", $params, $page, 25);
    }
}
