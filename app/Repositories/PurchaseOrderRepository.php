<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class PurchaseOrderRepository extends Repository
{
    private const BASE = "SELECT po.*, s.name AS supplier_name, s.supplier_code, s.contact_person, s.phone AS supplier_phone, s.email AS supplier_email,
                   s.address AS supplier_address, s.payment_terms, l.name AS delivery_location, d.name AS delivery_department,
                   cu.full_name AS created_by_name, au.full_name AS approved_by_name,
                   (SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS line_count,
                   (SELECT COALESCE(SUM(i.received_qty_base), 0) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS received_total,
                   (po.expected_delivery_date < CURDATE() AND po.status IN ('approved','sent','partially_received')) AS is_late
              FROM purchase_orders po
              JOIN suppliers s ON s.id = po.supplier_id
              JOIN inventory_locations l ON l.id = po.delivery_location_id
              JOIN departments d ON d.id = l.department_id
              JOIN users cu ON cu.id = po.created_by
              LEFT JOIN users au ON au.id = po.approved_by";

    public function search(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        $statusMap = [
            'open'      => "po.status IN ('draft','pending_approval','approved','sent','partially_received')",
            'to_receive'=> "po.status IN ('approved','sent','partially_received')",
            'late'      => "po.status IN ('approved','sent','partially_received') AND po.expected_delivery_date < CURDATE()",
        ];
        if (!empty($f['status'])) {
            if (isset($statusMap[$f['status']])) {
                $where[] = $statusMap[$f['status']];
            } else {
                $where[] = 'po.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['supplier_id'])) {
            $where[] = 'po.supplier_id = :sid';
            $params['sid'] = (int) $f['supplier_id'];
        }
        if (!empty($f['q'])) {
            $where[] = 'po.po_number LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, ['date' => 'po.id', 'expected' => 'po.expected_delivery_date', 'total' => 'po.total_amount'], 'date', 'desc');
        return $this->paginate(self::BASE . " WHERE $w $order", "SELECT COUNT(*) FROM purchase_orders po WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE po.id = :id', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name, p.product_code, p.is_expiry_tracked, p.is_batch_tracked, p.storage_condition,
                    u.code AS unit_code, ou.code AS order_unit, ou.name AS order_unit_name, pr.pr_number,
                    i.ordered_qty_base / i.unit_factor AS ordered_qty, i.received_qty_base / i.unit_factor AS received_qty,
                    GREATEST(i.ordered_qty_base - i.received_qty_base, 0) / i.unit_factor AS outstanding_qty
               FROM purchase_order_items i
               JOIN products p ON p.id = i.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN units_of_measure ou ON ou.id = i.order_unit_id
               LEFT JOIN purchase_request_items pri ON pri.id = i.purchase_request_item_id
               LEFT JOIN purchase_requests pr ON pr.id = pri.purchase_request_id
              WHERE i.purchase_order_id = :id ORDER BY i.id',
            ['id' => $id]
        );
    }

    public function receipts(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT g.id, g.grn_number, g.delivery_note_number, g.posted_at, u.full_name AS received_by_name,
                    (SELECT SUM(qty_rejected) FROM goods_receipt_items gi WHERE gi.goods_receipt_id = g.id) AS rejected
               FROM goods_receipts g JOIN users u ON u.id = g.received_by
              WHERE g.purchase_order_id = :id ORDER BY g.id',
            ['id' => $id]
        );
    }

    /** POs that goods can be received against. */
    public function receivable(): array
    {
        return $this->db->fetchAll(
            "SELECT po.id, po.po_number, po.status, po.expected_delivery_date, s.name AS supplier_name,
                    (po.expected_delivery_date < CURDATE()) AS is_late
               FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
              WHERE po.status IN ('approved','sent','partially_received')
              ORDER BY po.expected_delivery_date"
        );
    }
}
