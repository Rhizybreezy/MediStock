<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class PurchaseRequestRepository extends Repository
{
    private const BASE = "SELECT pr.*, d.name AS department_name, ru.full_name AS requested_by_name, au.full_name AS approved_by_name,
                   (SELECT COUNT(*) FROM purchase_request_items i WHERE i.purchase_request_id = pr.id) AS item_count,
                   (SELECT COALESCE(SUM(i.quantity * COALESCE(i.estimated_unit_cost, 0)), 0) FROM purchase_request_items i WHERE i.purchase_request_id = pr.id) AS estimated_total,
                   (SELECT COALESCE(SUM(i.ordered_qty), 0) FROM purchase_request_items i WHERE i.purchase_request_id = pr.id) AS ordered_total
              FROM purchase_requests pr
              JOIN departments d ON d.id = pr.department_id
              JOIN users ru ON ru.id = pr.requested_by
              LEFT JOIN users au ON au.id = pr.approved_by";

    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "pr.department_id IN $in";
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'to_order') {
                $where[] = "pr.status IN ('approved','partially_ordered')";
            } else {
                $where[] = 'pr.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['q'])) {
            $where[] = 'pr.pr_number LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        $w = implode(' AND ', $where);
        return $this->paginate(self::BASE . " WHERE $w ORDER BY pr.status = 'submitted' DESC, pr.id DESC", "SELECT COUNT(*) FROM purchase_requests pr WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE pr.id = :id', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name, p.product_code, u.code AS unit_code, s.name AS supplier_name
               FROM purchase_request_items i JOIN products p ON p.id = i.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN suppliers s ON s.id = i.preferred_supplier_id
              WHERE i.purchase_request_id = :id ORDER BY p.name',
            ['id' => $id]
        );
    }

    /** Approved request lines that still need ordering. */
    public function itemsToOrder(): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, pr.pr_number, pr.priority, pr.id AS pr_id, d.name AS department_name,
                    p.name AS product_name, p.product_code, u.code AS unit_code, i.quantity - i.ordered_qty AS remaining
               FROM purchase_request_items i
               JOIN purchase_requests pr ON pr.id = i.purchase_request_id
               JOIN departments d ON d.id = pr.department_id
               JOIN products p ON p.id = i.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE pr.status IN ('approved','partially_ordered') AND i.quantity > i.ordered_qty
              ORDER BY FIELD(pr.priority, 'emergency','urgent','routine'), pr.id, p.name"
        );
    }

    public function orders(int $prId): array
    {
        return $this->db->fetchAll(
            'SELECT DISTINCT po.id, po.po_number, po.status, s.name AS supplier_name
               FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
               JOIN suppliers s ON s.id = po.supplier_id
               JOIN purchase_request_items pri ON pri.id = poi.purchase_request_item_id
              WHERE pri.purchase_request_id = :id ORDER BY po.id',
            ['id' => $prId]
        );
    }
}
