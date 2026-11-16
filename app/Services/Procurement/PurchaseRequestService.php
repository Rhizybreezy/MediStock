<?php
declare(strict_types=1);

namespace App\Services\Procurement;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRequestRepository;
use App\Services\Stock\QuantityConverter;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Purchase requests: draft → submitted → approved | rejected → (partially_)ordered.
 * Approval is by a different person (segregation of duties). Only approved
 * requests can be turned into purchase orders.
 */
final class PurchaseRequestService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private PurchaseRequestRepository $requests,
        private ProductRepository $products,
        private QuantityConverter $converter,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    /** @param array $lines [['product_id','quantity','unit_id'?,'estimated_unit_cost'? (per selected unit),'preferred_supplier_id'?,'notes'?]] */
    public function create(array $header, array $lines, bool $submit, int $userId, string $source = 'manual'): int
    {
        $deptId = (int) $header['department_id'];
        $this->gate->authorize('purchase_requests.create', $deptId);
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product.');
        }
        $items = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            if ((int) $product['is_active'] !== 1) {
                throw new BusinessRuleException("Line $n: {$product['name']} is inactive.");
            }
            [$qty, $factor] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $pid = (int) $product['id'];
            if (isset($items[$pid])) {
                throw new BusinessRuleException("Line $n: {$product['name']} appears twice.");
            }
            $items[$pid] = [
                'product_id' => $pid, 'quantity' => $qty,
                'estimated_unit_cost' => isset($line['estimated_unit_cost']) && $line['estimated_unit_cost'] !== null ? round((float) $line['estimated_unit_cost'] / $factor, 4) : null,
                'preferred_supplier_id' => !empty($line['preferred_supplier_id']) ? (int) $line['preferred_supplier_id'] : null,
                'notes' => $line['notes'] ?? null,
            ];
        }
        $id = $this->db->transaction(function (Database $db) use ($header, $items, $submit, $userId, $deptId, $source) {
            $id = $db->insert('purchase_requests', [
                'pr_number' => $this->numbers->next('PR'), 'department_id' => $deptId, 'source' => $source,
                'priority' => $header['priority'] ?? 'routine', 'status' => $submit ? 'submitted' : 'draft',
                'justification' => $header['justification'] ?? null, 'requested_by' => $userId,
                'submitted_at' => $submit ? date('Y-m-d H:i:s') : null,
            ]);
            foreach ($items as $it) {
                $db->insert('purchase_request_items', $it + ['purchase_request_id' => $id]);
            }
            return $id;
        });
        $this->audit->log('create', 'purchase_request', $id, 'Created purchase request' . ($source !== 'manual' ? " ($source)" : ''), null, ['items' => count($items), 'submitted' => $submit]);
        if ($submit) {
            $this->notifySubmitted($id);
        }
        return $id;
    }

    public function submit(int $id, int $userId): void
    {
        $pr = $this->requests->find($id) ?? throw new BusinessRuleException('Purchase request not found.');
        $this->gate->authorize('purchase_requests.create', (int) $pr['department_id']);
        if ($pr['status'] !== 'draft') {
            throw new BusinessRuleException('Only drafts can be submitted.');
        }
        $this->db->update('purchase_requests', ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $this->audit->log('submit', 'purchase_request', $id, "Submitted {$pr['pr_number']}");
        $this->notifySubmitted($id);
    }

    public function decide(int $id, bool $approve, ?string $reason, int $userId): void
    {
        $pr = $this->db->transaction(function (Database $db) use ($id, $approve, $reason, $userId) {
            $pr = $db->fetchOne('SELECT * FROM purchase_requests WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new BusinessRuleException('Purchase request not found.');
            $this->gate->authorize('purchase_requests.approve', (int) $pr['department_id']);
            ApprovalRules::assertDifferentUser((int) $pr['requested_by'], $userId, 'purchase request');
            if ($pr['status'] !== 'submitted') {
                throw new BusinessRuleException('Only submitted purchase requests can be approved or rejected.');
            }
            if (!$approve && !$reason) {
                throw new BusinessRuleException('Give a reason for rejecting.');
            }
            $db->update('purchase_requests', [
                'status' => $approve ? 'approved' : 'rejected', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $approve ? null : $reason,
            ], 'id = :id', ['id' => $id]);
            return $pr;
        });
        $this->audit->log($approve ? 'approve' : 'reject', 'purchase_request', $id, ($approve ? 'Approved ' : 'Rejected ') . $pr['pr_number'], null, ['reason' => $reason]);
        $this->notifications->notifyUser((int) $pr['requested_by'], [
            'type' => 'pr_decided', 'severity' => $approve ? 'info' : 'warning',
            'title' => "Purchase request {$pr['pr_number']} " . ($approve ? 'approved' : 'rejected'),
            'message' => $approve ? 'It can now be converted into purchase orders.' : "Reason: $reason",
            'link_url' => '/purchase-requests/' . $id, 'entity_type' => 'purchase_request', 'entity_id' => $id,
            'dedupe_key' => 'pr_decided:' . $id,
        ]);
        if ($approve) {
            $this->notifications->notifyPermission('purchase_orders.manage', [
                'type' => 'pr_approved', 'severity' => 'info', 'title' => "Approved PR {$pr['pr_number']} ready for ordering",
                'message' => 'Create purchase orders for the approved items.', 'link_url' => '/purchase-orders/from-requests',
                'entity_type' => 'purchase_request', 'entity_id' => $id, 'dedupe_key' => 'pr_ready:' . $id,
            ]);
        }
    }

    public function cancel(int $id, int $userId): void
    {
        $pr = $this->requests->find($id) ?? throw new BusinessRuleException('Purchase request not found.');
        $this->gate->authorize('purchase_requests.create', (int) $pr['department_id']);
        if (!in_array($pr['status'], ['draft', 'submitted', 'approved'], true) || (float) $pr['ordered_total'] > 0) {
            throw new BusinessRuleException('This purchase request can no longer be cancelled.');
        }
        $this->db->update('purchase_requests', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        $this->audit->log('cancel', 'purchase_request', $id, "Cancelled {$pr['pr_number']}");
    }

    /** Recompute ordered quantities/status after POs are created or cancelled. */
    public function syncOrdered(int $prId): void
    {
        $this->db->execute(
            "UPDATE purchase_request_items pri
                SET pri.ordered_qty = (SELECT COALESCE(SUM(poi.ordered_qty_base), 0) FROM purchase_order_items poi
                                         JOIN purchase_orders po ON po.id = poi.purchase_order_id
                                        WHERE poi.purchase_request_item_id = pri.id AND po.status <> 'cancelled')
              WHERE pri.purchase_request_id = :id",
            ['id' => $prId]
        );
        $row = $this->db->fetchOne('SELECT SUM(quantity) AS q, SUM(LEAST(ordered_qty, quantity)) AS o FROM purchase_request_items WHERE purchase_request_id = :id', ['id' => $prId]);
        $status = $this->db->fetchValue('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $prId]);
        if (!in_array($status, ['approved', 'partially_ordered', 'ordered'], true)) {
            return;
        }
        $new = (float) $row['o'] <= 0 ? 'approved' : ((float) $row['o'] + 1e-9 >= (float) $row['q'] ? 'ordered' : 'partially_ordered');
        $this->db->update('purchase_requests', ['status' => $new], 'id = :id', ['id' => $prId]);
    }

    /**
     * Products at or below their reorder level (hospital-wide usable stock), net of stock
     * already on order and quantities in open purchase requests.
     * Suggested quantity = target (max level, or 2 × reorder level) − usable − on order − requested.
     */
    public function lowStockSuggestions(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT v.product_id, v.product_code, v.name, v.usable_qty, v.reorder_level, v.max_level, u.code AS unit_code,
                    (SELECT COALESCE(SUM(poi.ordered_qty_base - poi.received_qty_base), 0) FROM purchase_order_items poi
                       JOIN purchase_orders po ON po.id = poi.purchase_order_id
                      WHERE poi.product_id = v.product_id AND po.status IN ('draft','pending_approval','approved','sent','partially_received')) AS on_order,
                    (SELECT COALESCE(SUM(pri.quantity - pri.ordered_qty), 0) FROM purchase_request_items pri
                       JOIN purchase_requests pr ON pr.id = pri.purchase_request_id
                      WHERE pri.product_id = v.product_id AND pr.status IN ('draft','submitted','approved','partially_ordered')) AS requested,
                    sp.supplier_id AS preferred_supplier_id, s.name AS preferred_supplier, sp.quoted_unit_price,
                    (SELECT poi2.unit_price / poi2.unit_factor FROM purchase_order_items poi2 JOIN purchase_orders po2 ON po2.id = poi2.purchase_order_id
                      WHERE poi2.product_id = v.product_id AND po2.status <> 'cancelled' ORDER BY po2.id DESC LIMIT 1) AS last_price
               FROM v_product_stock v
               JOIN products p ON p.id = v.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN supplier_products sp ON sp.product_id = v.product_id AND sp.is_preferred = 1
               LEFT JOIN suppliers s ON s.id = sp.supplier_id
              WHERE v.is_active = 1 AND v.reorder_level > 0 AND v.usable_qty <= v.reorder_level
              ORDER BY (v.usable_qty / v.reorder_level), v.name"
        );
        foreach ($rows as &$r) {
            $target = (float) $r['max_level'] > 0 ? (float) $r['max_level'] : 2 * (float) $r['reorder_level'];
            $r['suggested_qty'] = max(0, ceil($target - (float) $r['usable_qty'] - (float) $r['on_order'] - (float) $r['requested']));
            $r['estimated_cost'] = $r['quoted_unit_price'] ?? $r['last_price'];
        }
        return $rows;
    }

    private function notifySubmitted(int $id): void
    {
        $pr = $this->requests->find($id);
        $this->notifications->notifyPermission('purchase_requests.approve', [
            'type' => 'pr_pending', 'severity' => $pr['priority'] === 'routine' ? 'info' : 'warning',
            'title' => "Purchase request {$pr['pr_number']} awaits approval",
            'message' => "{$pr['department_name']}: {$pr['item_count']} item(s), estimated " . money($pr['estimated_total']) . '.',
            'link_url' => '/purchase-requests/' . $id, 'entity_type' => 'purchase_request', 'entity_id' => $id,
            'dedupe_key' => 'pr_submitted:' . $id,
        ], (int) $pr['department_id']);
    }
}
