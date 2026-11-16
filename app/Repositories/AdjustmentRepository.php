<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class AdjustmentRepository extends Repository
{
    private const BASE = "SELECT a.*, l.name AS location_name, l.department_id, d.name AS department_name,
                   ru.full_name AS requested_by_name, au.full_name AS approved_by_name,
                   (SELECT COUNT(*) FROM stock_adjustment_items i WHERE i.stock_adjustment_id = a.id) AS line_count,
                   (SELECT COALESCE(SUM(i.quantity_change * b.unit_cost), 0) FROM stock_adjustment_items i
                      JOIN product_batches b ON b.id = i.batch_id WHERE i.stock_adjustment_id = a.id) AS value_change
              FROM stock_adjustments a
              JOIN inventory_locations l ON l.id = a.location_id
              JOIN departments d ON d.id = l.department_id
              JOIN users ru ON ru.id = a.requested_by
              LEFT JOIN users au ON au.id = a.approved_by";

    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "l.department_id IN $in";
        }
        if (!empty($f['status'])) {
            $where[] = 'a.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['reason'])) {
            $where[] = 'a.reason_code = :reason';
            $params['reason'] = $f['reason'];
        }
        if (!empty($f['q'])) {
            $where[] = 'a.adjustment_number LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        $w = implode(' AND ', $where);
        $from = 'FROM stock_adjustments a JOIN inventory_locations l ON l.id = a.location_id';
        return $this->paginate(self::BASE . " WHERE $w ORDER BY a.status = 'pending' DESC, a.id DESC", "SELECT COUNT(*) $from WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE a.id = :id', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, b.batch_code, b.lot_number, b.expiry_date, b.unit_cost, p.name AS product_name, p.product_code, u.code AS unit_code
               FROM stock_adjustment_items i JOIN product_batches b ON b.id = i.batch_id
               JOIN products p ON p.id = b.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE i.stock_adjustment_id = :id ORDER BY p.name',
            ['id' => $id]
        );
    }
}
