<?php
declare(strict_types=1);

namespace App\Services\Procurement;

use App\Core\Database;

/**
 * Supplier performance from actual procurement records:
 *  - on-time rate: share of POs whose first delivery arrived on or before the expected date
 *  - average lead time: days from approval/sending to first delivery
 *  - fill rate: accepted quantity ÷ ordered quantity (completed/partially received POs)
 *  - rejection rate: rejected ÷ (accepted + rejected) at goods receipt
 *  - rating (0–5) = 5 × (0.4 on-time + 0.3 fill + 0.3 (1 − rejection)), only when deliveries exist
 * Every figure comes with the number of POs it is based on, so small samples are visible.
 */
final class SupplierPerformanceService
{
    public function __construct(private Database $db)
    {
    }

    public function metrics(?int $supplierId = null, int $periodDays = 365): array
    {
        $params = ['days' => $periodDays];
        $filter = '';
        if ($supplierId !== null) {
            $filter = ' AND s.id = :sid';
            $params['sid'] = $supplierId;
        }
        $rows = $this->db->fetchAll(
            "SELECT s.id, s.supplier_code, s.name, s.status, s.default_lead_time_days,
                    COUNT(DISTINCT po.id) AS po_count,
                    COALESCE(SUM(CASE WHEN po.id IS NOT NULL THEN po.total_amount END), 0) AS spend
               FROM suppliers s
               LEFT JOIN purchase_orders po ON po.supplier_id = s.id
                    AND po.status NOT IN ('draft','pending_approval','cancelled')
                    AND po.created_at >= CURDATE() - INTERVAL :days DAY
              WHERE 1=1 $filter
              GROUP BY s.id ORDER BY s.name",
            $params
        );
        foreach ($rows as &$r) {
            $r += $this->deliveryStats((int) $r['id'], $periodDays);
        }
        return $rows;
    }

    private function deliveryStats(int $supplierId, int $periodDays): array
    {
        $deliveries = $this->db->fetchAll(
            "SELECT po.id, po.expected_delivery_date, COALESCE(po.sent_at, po.approved_at) AS started,
                    MIN(g.posted_at) AS first_receipt
               FROM purchase_orders po
               JOIN goods_receipts g ON g.purchase_order_id = po.id AND g.status = 'posted'
              WHERE po.supplier_id = :s AND po.created_at >= CURDATE() - INTERVAL :days DAY
              GROUP BY po.id",
            ['s' => $supplierId, 'days' => $periodDays]
        );
        $onTime = 0;
        $leadDays = [];
        foreach ($deliveries as $d) {
            if ($d['expected_delivery_date'] && substr((string) $d['first_receipt'], 0, 10) <= $d['expected_delivery_date']) {
                $onTime++;
            }
            if ($d['started']) {
                $leadDays[] = max(0, (strtotime((string) $d['first_receipt']) - strtotime((string) $d['started'])) / 86400);
            }
        }
        $fill = $this->db->fetchOne(
            "SELECT SUM(poi.ordered_qty_base) AS ordered, SUM(LEAST(poi.received_qty_base, poi.ordered_qty_base)) AS received
               FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
              WHERE po.supplier_id = :s AND po.status IN ('partially_received','received','closed')
                AND po.created_at >= CURDATE() - INTERVAL :days DAY",
            ['s' => $supplierId, 'days' => $periodDays]
        );
        $rej = $this->db->fetchOne(
            "SELECT SUM(gi.qty_received) AS accepted, SUM(gi.qty_rejected) AS rejected
               FROM goods_receipt_items gi JOIN goods_receipts g ON g.id = gi.goods_receipt_id
              WHERE g.supplier_id = :s AND g.status = 'posted' AND g.posted_at >= CURDATE() - INTERVAL :days DAY",
            ['s' => $supplierId, 'days' => $periodDays]
        );
        $late = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = :s AND status IN ('approved','sent','partially_received')
                AND expected_delivery_date < CURDATE()",
            ['s' => $supplierId]
        );
        $n = count($deliveries);
        $onTimeRate = $n ? $onTime / $n : null;
        $fillRate = (float) ($fill['ordered'] ?? 0) > 0 ? (float) $fill['received'] / (float) $fill['ordered'] : null;
        $totalRecv = (float) ($rej['accepted'] ?? 0) + (float) ($rej['rejected'] ?? 0);
        $rejectionRate = $totalRecv > 0 ? (float) $rej['rejected'] / $totalRecv : null;
        $rating = $n ? round(5 * (0.4 * $onTimeRate + 0.3 * ($fillRate ?? 1) + 0.3 * (1 - ($rejectionRate ?? 0))), 2) : null;
        if ($rating !== null) {
            $this->db->update('suppliers', ['rating_cache' => $rating], 'id = :id', ['id' => $supplierId]);
        }
        return [
            'deliveries' => $n, 'on_time_rate' => $onTimeRate, 'avg_lead_days' => $leadDays ? array_sum($leadDays) / count($leadDays) : null,
            'fill_rate' => $fillRate, 'rejection_rate' => $rejectionRate, 'late_open_pos' => $late, 'rating' => $rating,
        ];
    }

    /** Prices actually paid per base unit, newest first. */
    public function priceHistory(int $supplierId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT po.po_number, po.id AS po_id, COALESCE(po.order_date, DATE(po.created_at)) AS order_date, p.name AS product_name,
                    p.id AS product_id, u.code AS unit_code, poi.unit_price, poi.unit_factor, ou.code AS order_unit,
                    poi.unit_price / poi.unit_factor AS base_price
               FROM purchase_order_items poi
               JOIN purchase_orders po ON po.id = poi.purchase_order_id
               JOIN products p ON p.id = poi.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN units_of_measure ou ON ou.id = poi.order_unit_id
              WHERE po.supplier_id = :s AND po.status NOT IN ('draft','cancelled')
              ORDER BY po.id DESC LIMIT " . (int) $limit,
            ['s' => $supplierId]
        );
    }
}
