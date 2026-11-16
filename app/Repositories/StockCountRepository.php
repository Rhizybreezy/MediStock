<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class StockCountRepository extends Repository
{
    private const BASE = "SELECT c.*, l.name AS location_name, l.department_id, d.name AS department_name,
                   su.full_name AS started_by_name, au.full_name AS approved_by_name,
                   (SELECT COUNT(*) FROM stock_count_items i WHERE i.stock_count_id = c.id) AS item_count,
                   (SELECT COUNT(*) FROM stock_count_items i WHERE i.stock_count_id = c.id AND i.counted_qty IS NOT NULL) AS counted_count,
                   (SELECT COUNT(*) FROM stock_count_items i WHERE i.stock_count_id = c.id AND i.variance <> 0) AS variance_count
              FROM stock_counts c
              JOIN inventory_locations l ON l.id = c.location_id
              JOIN departments d ON d.id = l.department_id
              JOIN users su ON su.id = c.started_by
              LEFT JOIN users au ON au.id = c.approved_by";

    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "l.department_id IN $in";
        }
        if (!empty($f['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = $f['status'];
        }
        $w = implode(' AND ', $where);
        return $this->paginate(self::BASE . " WHERE $w ORDER BY c.id DESC", "SELECT COUNT(*) FROM stock_counts c JOIN inventory_locations l ON l.id = c.location_id WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE c.id = :id', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, b.batch_code, b.lot_number, b.expiry_date, b.unit_cost, p.name AS product_name, p.product_code, u.code AS unit_code
               FROM stock_count_items i JOIN product_batches b ON b.id = i.batch_id
               JOIN products p ON p.id = b.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE i.stock_count_id = :id ORDER BY p.name, b.expiry_date',
            ['id' => $id]
        );
    }
}
