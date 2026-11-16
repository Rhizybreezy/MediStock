<?php
declare(strict_types=1);

namespace App\Services\Procurement;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;
use App\Services\Support\SettingsService;

/**
 * Purchase orders:
 *   draft → pending_approval → approved → sent → partially_received → received
 *   (closed = short-closed; cancelled only before anything is received)
 * The creator cannot approve their own PO. Goods can only be received against
 * approved/sent POs.
 */
final class PurchaseOrderService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private PurchaseOrderRepository $orders,
        private ProductRepository $products,
        private LocationRepository $locations,
        private PurchaseRequestService $requests,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private SettingsService $settings,
        private AuditService $audit,
    ) {
    }

    /**
     * Create or replace a draft PO.
     * @param array $lines [['product_id','order_unit_id','quantity' (order units),'unit_price' (per order unit),'purchase_request_item_id'?]]
     */
    public function saveDraft(?int $id, array $header, array $lines, int $userId): int
    {
        $this->gate->authorize('purchase_orders.manage');
        $supplier = $this->db->fetchOne('SELECT * FROM suppliers WHERE id = :id', ['id' => (int) $header['supplier_id']]) ?? throw new BusinessRuleException('Supplier not found.');
        if ($supplier['status'] !== 'active') {
            throw new BusinessRuleException("{$supplier['name']} is {$supplier['status']} and cannot receive orders.");
        }
        $loc = $this->locations->find((int) $header['delivery_location_id']) ?? throw new BusinessRuleException('Delivery location not found.');
        if (in_array($loc['type'], ['transit', 'quarantine'], true) || (int) $loc['is_active'] !== 1) {
            throw new BusinessRuleException('Choose an active store location for delivery.');
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product.');
        }
        $prepared = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            $unit = null;
            foreach ($this->products->unitChoices((int) $product['id']) as $u) {
                if ($u['unit_id'] === (int) $line['order_unit_id']) {
                    $unit = $u;
                }
            }
            if (!$unit) {
                throw new BusinessRuleException("Line $n: the order unit is not configured for {$product['name']}.");
            }
            $qty = round((float) $line['quantity'], 3);
            $price = round((float) $line['unit_price'], 4);
            if ($qty <= 0 || $price < 0) {
                throw new BusinessRuleException("Line $n: quantity must be positive and price cannot be negative.");
            }
            $base = round($qty * $unit['factor'], 3);
            if ((int) $product['allows_decimal'] === 0 && abs($base - round($base)) > 1e-9) {
                throw new BusinessRuleException("Line $n: {$product['name']} must be a whole number of {$product['unit_code']}.");
            }
            $prepared[] = [
                'product_id' => (int) $product['id'], 'purchase_request_item_id' => $line['purchase_request_item_id'] ?? null,
                'order_unit_id' => $unit['unit_id'], 'unit_factor' => $unit['factor'], 'ordered_qty_base' => $base,
                'unit_price' => $price, 'line_total' => round($qty * $price, 2),
            ];
        }
        $tax = round((float) ($header['tax_amount'] ?? 0), 2);
        $subtotal = round(array_sum(array_column($prepared, 'line_total')), 2);
        $leadDays = (int) ($supplier['default_lead_time_days'] ?: $this->settings->int('default_lead_time_days', 14));
        $data = [
            'supplier_id' => (int) $supplier['id'], 'delivery_location_id' => (int) $loc['id'],
            'expected_delivery_date' => $header['expected_delivery_date'] ?: date('Y-m-d', strtotime("+$leadDays days")),
            'subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $subtotal + $tax, 'notes' => $header['notes'] ?? null,
        ];

        $prIds = [];
        $poId = $this->db->transaction(function (Database $db) use ($id, $data, $prepared, $userId, &$prIds) {
            if ($id === null) {
                $id = $db->insert('purchase_orders', $data + ['po_number' => $this->numbers->next('PO'), 'status' => 'draft', 'created_by' => $userId]);
            } else {
                $po = $db->fetchOne('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new BusinessRuleException('Purchase order not found.');
                if ($po['status'] !== 'draft') {
                    throw new BusinessRuleException('Only draft purchase orders can be edited.');
                }
                $prIds = array_map('intval', $db->fetchColumn(
                    'SELECT DISTINCT pri.purchase_request_id FROM purchase_order_items poi JOIN purchase_request_items pri ON pri.id = poi.purchase_request_item_id WHERE poi.purchase_order_id = :id',
                    ['id' => $id]
                ));
                $db->update('purchase_orders', $data, 'id = :id', ['id' => $id]);
                $db->execute('DELETE FROM purchase_order_items WHERE purchase_order_id = :id', ['id' => $id]);
            }
            foreach ($prepared as $p) {
                $db->insert('purchase_order_items', $p + ['purchase_order_id' => $id]);
                if ($p['purchase_request_item_id']) {
                    $prIds[] = (int) $db->fetchValue('SELECT purchase_request_id FROM purchase_request_items WHERE id = :i', ['i' => $p['purchase_request_item_id']]);
                }
            }
            return $id;
        });
        foreach (array_unique(array_filter($prIds)) as $prId) {
            $this->requests->syncOrdered($prId);
        }
        cache_bump();
        $this->audit->log($id === null ? 'create' : 'update', 'purchase_order', $poId, ($id === null ? 'Created' : 'Updated') . " draft PO for {$supplier['name']}", null, ['lines' => count($prepared), 'total' => $data['total_amount']]);
        return $poId;
    }

    /**
     * Convert approved purchase-request items into one draft PO per supplier.
     * @param array $selection [pr_item_id => ['supplier_id' => int, 'unit_price' => float (per base unit), 'quantity' => float (base)]]
     * @return int[] created PO ids
     */
    public function createFromRequests(array $selection, int $deliveryLocationId, int $userId): array
    {
        $this->gate->authorize('purchase_orders.manage');
        $bySupplier = [];
        foreach ($selection as $itemId => $sel) {
            $item = $this->db->fetchOne(
                "SELECT pri.*, pr.status AS pr_status, pr.pr_number, p.name AS product_name, p.base_unit_id
                   FROM purchase_request_items pri JOIN purchase_requests pr ON pr.id = pri.purchase_request_id
                   JOIN products p ON p.id = pri.product_id WHERE pri.id = :id",
                ['id' => (int) $itemId]
            ) ?? throw new BusinessRuleException('Purchase request item not found.');
            if (!in_array($item['pr_status'], ['approved', 'partially_ordered'], true)) {
                throw new BusinessRuleException("{$item['pr_number']} is not approved for ordering.");
            }
            $remaining = (float) $item['quantity'] - (float) $item['ordered_qty'];
            $qty = round((float) $sel['quantity'], 3);
            if ($qty <= 0 || $qty > $remaining + 1e-9) {
                throw new BusinessRuleException("{$item['product_name']}: order quantity must be between 0 and " . qty($remaining) . '.');
            }
            if (empty($sel['supplier_id'])) {
                throw new BusinessRuleException("{$item['product_name']}: choose a supplier.");
            }
            $bySupplier[(int) $sel['supplier_id']][] = [
                'product_id' => (int) $item['product_id'], 'order_unit_id' => (int) $item['base_unit_id'], 'quantity' => $qty,
                'unit_price' => (float) $sel['unit_price'], 'purchase_request_item_id' => (int) $item['id'],
            ];
        }
        if (!$bySupplier) {
            throw new BusinessRuleException('Select at least one item to order.');
        }
        $ids = [];
        $this->db->transaction(function () use ($bySupplier, $deliveryLocationId, $userId, &$ids) {
            foreach ($bySupplier as $supplierId => $lines) {
                $ids[] = $this->saveDraft(null, ['supplier_id' => $supplierId, 'delivery_location_id' => $deliveryLocationId, 'expected_delivery_date' => null, 'tax_amount' => 0, 'notes' => 'Created from approved purchase requests'], $lines, $userId);
            }
        });
        return $ids;
    }

    public function submit(int $id, int $userId): void
    {
        $po = $this->orders->find($id) ?? throw new BusinessRuleException('Purchase order not found.');
        $this->gate->authorize('purchase_orders.manage');
        if ($po['status'] !== 'draft') {
            throw new BusinessRuleException('Only drafts can be submitted for approval.');
        }
        $this->db->update('purchase_orders', ['status' => 'pending_approval'], 'id = :id', ['id' => $id]);
        $this->audit->log('submit', 'purchase_order', $id, "Submitted {$po['po_number']} for approval");
        $this->notifications->notifyPermission('purchase_orders.approve', [
            'type' => 'po_pending', 'severity' => 'info', 'title' => "PO {$po['po_number']} awaits approval",
            'message' => "{$po['supplier_name']}: " . money($po['total_amount']) . '.', 'link_url' => '/purchase-orders/' . $id,
            'entity_type' => 'purchase_order', 'entity_id' => $id, 'dedupe_key' => 'po_pending:' . $id . ':' . date('YmdHi'),
        ]);
    }

    public function decide(int $id, bool $approve, ?string $reason, int $userId): void
    {
        $po = $this->db->transaction(function (Database $db) use ($id, $approve, $reason, $userId) {
            $po = $db->fetchOne('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE', ['id' => $id]) ?? throw new BusinessRuleException('Purchase order not found.');
            $this->gate->authorize('purchase_orders.approve');
            ApprovalRules::assertDifferentUser((int) $po['created_by'], $userId, 'purchase order');
            if ($po['status'] !== 'pending_approval') {
                throw new BusinessRuleException('This purchase order is not awaiting approval.');
            }
            if ($approve) {
                $db->update('purchase_orders', ['status' => 'approved', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'), 'order_date' => date('Y-m-d')], 'id = :id', ['id' => $id]);
            } else {
                if (!$reason) {
                    throw new BusinessRuleException('Give a reason for returning the purchase order.');
                }
                $db->update('purchase_orders', ['status' => 'draft', 'notes' => mb_substr(trim(($po['notes'] ? $po['notes'] . "\n" : '') . 'Returned by approver: ' . $reason), 0, 2000)], 'id = :id', ['id' => $id]);
            }
            return $po;
        });
        cache_bump();
        $this->audit->log($approve ? 'approve' : 'reject', 'purchase_order', $id, ($approve ? 'Approved ' : 'Returned to draft ') . $po['po_number'], null, ['reason' => $reason]);
        $this->notifications->notifyUser((int) $po['created_by'], [
            'type' => 'po_decided', 'severity' => $approve ? 'info' : 'warning',
            'title' => "PO {$po['po_number']} " . ($approve ? 'approved' : 'returned for changes'),
            'message' => $approve ? 'Send it to the supplier.' : "Reason: $reason", 'link_url' => '/purchase-orders/' . $id,
            'entity_type' => 'purchase_order', 'entity_id' => $id, 'dedupe_key' => 'po_decided:' . $id . ':' . date('YmdHi'),
        ]);
    }

    public function markSent(int $id, int $userId): void
    {
        $po = $this->orders->find($id) ?? throw new BusinessRuleException('Purchase order not found.');
        $this->gate->authorize('purchase_orders.manage');
        if ($po['status'] !== 'approved') {
            throw new BusinessRuleException('Only approved purchase orders can be marked as sent.');
        }
        $this->db->update('purchase_orders', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        cache_bump();
        $this->audit->log('send', 'purchase_order', $id, "Marked {$po['po_number']} as sent to {$po['supplier_name']}");
    }

    public function cancel(int $id, string $reason, int $userId): void
    {
        $po = $this->orders->find($id) ?? throw new BusinessRuleException('Purchase order not found.');
        $this->gate->authorize('purchase_orders.manage');
        if (!in_array($po['status'], ['draft', 'pending_approval', 'approved', 'sent'], true) || (float) $po['received_total'] > 0) {
            throw new BusinessRuleException('Purchase orders with received goods cannot be cancelled; close them instead.');
        }
        $this->db->update('purchase_orders', ['status' => 'cancelled', 'notes' => mb_substr(trim(($po['notes'] ? $po['notes'] . "\n" : '') . 'Cancelled: ' . $reason), 0, 2000)], 'id = :id', ['id' => $id]);
        foreach ($this->linkedRequests($id) as $prId) {
            $this->requests->syncOrdered($prId);
        }
        cache_bump();
        $this->audit->log('cancel', 'purchase_order', $id, "Cancelled {$po['po_number']}", null, ['reason' => $reason]);
    }

    /** Short-close a partially received PO (no more deliveries expected). */
    public function close(int $id, string $reason, int $userId): void
    {
        $po = $this->orders->find($id) ?? throw new BusinessRuleException('Purchase order not found.');
        $this->gate->authorize('purchase_orders.manage');
        if (!in_array($po['status'], ['partially_received', 'sent', 'approved'], true)) {
            throw new BusinessRuleException('Only open purchase orders can be closed.');
        }
        $this->db->update('purchase_orders', ['status' => 'closed', 'notes' => mb_substr(trim(($po['notes'] ? $po['notes'] . "\n" : '') . 'Closed: ' . $reason), 0, 2000)], 'id = :id', ['id' => $id]);
        cache_bump();
        $this->audit->log('close', 'purchase_order', $id, "Closed {$po['po_number']}", null, ['reason' => $reason]);
    }

    /** Recompute received quantities and status from posted goods receipts. */
    public function syncReceived(int $id): void
    {
        $this->db->execute(
            "UPDATE purchase_order_items poi
                SET poi.received_qty_base = (SELECT COALESCE(SUM(gi.qty_received), 0) FROM goods_receipt_items gi
                                               JOIN goods_receipts g ON g.id = gi.goods_receipt_id
                                              WHERE gi.purchase_order_item_id = poi.id AND g.status = 'posted')
              WHERE poi.purchase_order_id = :id",
            ['id' => $id]
        );
        $row = $this->db->fetchOne('SELECT SUM(ordered_qty_base) AS o, SUM(LEAST(received_qty_base, ordered_qty_base)) AS r FROM purchase_order_items WHERE purchase_order_id = :id', ['id' => $id]);
        $status = $this->db->fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $id]);
        if (in_array($status, ['approved', 'sent', 'partially_received'], true)) {
            $new = (float) $row['r'] + 1e-9 >= (float) $row['o'] ? 'received' : ((float) $row['r'] > 0 ? 'partially_received' : $status);
            $this->db->update('purchase_orders', ['status' => $new], 'id = :id', ['id' => $id]);
        }
    }

    /** @return int[] */
    private function linkedRequests(int $poId): array
    {
        return array_map('intval', $this->db->fetchColumn(
            'SELECT DISTINCT pri.purchase_request_id FROM purchase_order_items poi JOIN purchase_request_items pri ON pri.id = poi.purchase_request_item_id WHERE poi.purchase_order_id = :id',
            ['id' => $poId]
        ));
    }
}
