<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;

/**
 * Daily consumption straight from the ledger with the date filter applied up front
 * (uses index ix_mov_type_from_date). Replaces v_daily_consumption in hot paths,
 * because a grouped view is materialised over ALL history before filtering.
 */
final class ConsumptionQuery
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param int[]|null $departments null = all departments
     * @return array<int, array{product_id:int, department_id:int, day:string, qty:float, value:float}>
     */
    public function daily(int $days, ?array $departments = null, ?int $productId = null, bool $excludeToday = false): array
    {
        $where = "m.movement_type = 'CONSUMED' AND m.created_at >= CURDATE() - INTERVAL :days DAY";
        $params = ['days' => $days];
        if ($excludeToday) {
            $where .= ' AND m.created_at < CURDATE()';
        }
        if ($departments !== null) {
            [$in, $p] = Database::inClause($departments ?: [0], 'cd');
            $where .= " AND l.department_id IN $in";
            $params += $p;
        }
        if ($productId !== null) {
            $where .= ' AND m.product_id = :pid';
            $params['pid'] = $productId;
        }
        return $this->db->fetchAll(
            "SELECT m.product_id, l.department_id, DATE(m.created_at) AS day, SUM(m.quantity) AS qty, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE $where
              GROUP BY m.product_id, l.department_id, DATE(m.created_at)",
            $params
        );
    }
}
