<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class GoodsReceiptRepository extends Repository
{
    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope, 'sc');
            $where[] = "l.department_id IN $in";
        }
        if (!empty($f['q'])) {
            $where[] = '(g.grn_number LIKE :q1 OR g.delivery_note_number LIKE :q2 OR g.invoice_number LIKE :q3)';
            $like = Database::like($f['q']);
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (!empty($f['type'])) {
            $where[] = 'g.receipt_type = :type';
            $params['type'] = $f['type'];
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, ['date' => 'g.id', 'number' => 'g.grn_number'], 'date', 'desc');
        $from = " FROM goods_receipts g
                  JOIN inventory_locations l ON l.id = g.receiving_location_id
                  LEFT JOIN suppliers s ON s.id = g.supplier_id
                  JOIN users u ON u.id = g.received_by
                 WHERE $w";
        $sql = "SELECT g.*, l.name AS location_name, s.name AS supplier_name, u.full_name AS received_by_name,
                       (SELECT COUNT(*) FROM goods_receipt_items i WHERE i.goods_receipt_id = g.id) AS line_count,
                       (SELECT COALESCE(SUM(i.qty_received * i.unit_cost), 0) FROM goods_receipt_items i WHERE i.goods_receipt_id = g.id) AS total_value
                $from $order";
        return $this->paginate($sql, "SELECT COUNT(*) $from", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT g.*, l.name AS location_name, l.department_id, d.name AS department_name,
                    s.name AS supplier_name, u.full_name AS received_by_name
               FROM goods_receipts g
               JOIN inventory_locations l ON l.id = g.receiving_location_id
               JOIN departments d ON d.id = l.department_id
               LEFT JOIN suppliers s ON s.id = g.supplier_id
               JOIN users u ON u.id = g.received_by
              WHERE g.id = :id',
            ['id' => $id]
        );
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name, p.product_code, u.code AS unit_code, b.batch_code
               FROM goods_receipt_items i
               JOIN products p ON p.id = i.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN product_batches b ON b.id = i.batch_id
              WHERE i.goods_receipt_id = :id ORDER BY i.id',
            ['id' => $id]
        );
    }

    public function tokenUsed(string $token): ?int
    {
        $id = $this->db->fetchValue('SELECT id FROM goods_receipts WHERE submission_token = :t', ['t' => $token]);
        return $id === null ? null : (int) $id;
    }
}
