<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\BatchRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\SettingsService;

/**
 * Receiving stock that is NOT tied to a purchase order (Phase 4 adds PO receipts):
 *   - direct purchase, donation, or opening balance
 *
 * One goods receipt (GRN) → one batch per line (or top-up of the same lot) →
 * RECEIVED movements into the receiving location, all in one transaction.
 *
 * Safeguards: expired stock is refused, expiry required for expiry-tracked
 * products, cold-chain products only into cold-chain locations, and a one-time
 * submission token makes double submission impossible.
 */
final class ReceivingService
{
    public const TYPES = ['direct' => 'Direct purchase', 'donation' => 'Donation', 'opening_balance' => 'Opening balance'];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockService $stock,
        private QuantityConverter $converter,
        private ProductRepository $products,
        private BatchRepository $batches,
        private LocationRepository $locations,
        private GoodsReceiptRepository $receipts,
        private DocumentNumberService $numbers,
        private AuditService $audit,
        private PurchaseOrderService $purchaseOrders,
        private SettingsService $settings,
    ) {
    }

    /**
     * Goods receipt against an approved purchase order.
     * Lines: [['purchase_order_item_id', 'lot_number', 'expiry_date', 'manufacture_date',
     *          'quantity' (accepted, in the PO line's order unit), 'qty_rejected', 'rejection_reason'], ...]
     * Rejected quantities are recorded but not stocked; they stay outstanding on the PO.
     * @return array{0:int, 1:bool} [goods_receipt_id, created]
     */
    public function receivePurchaseOrder(int $poId, array $header, array $lines, int $userId): array
    {
        $po = $this->db->fetchOne('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $poId]) ?? throw new BusinessRuleException('Purchase order not found.');
        $location = $this->locations->find((int) ($header['receiving_location_id'] ?: $po['delivery_location_id'])) ?? throw new BusinessRuleException('Receiving location not found.');
        $this->gate->authorize('goods_receipts.create', (int) $location['department_id']);
        if (!in_array($po['status'], ['approved', 'sent', 'partially_received'], true)) {
            throw new BusinessRuleException("Goods can only be received against approved purchase orders (this one is {$po['status']}).");
        }
        if ((int) $location['is_active'] !== 1 || in_array($location['type'], ['transit', 'quarantine'], true)) {
            throw new BusinessRuleException('Stock cannot be received into this location.');
        }
        if ($existing = $this->receipts->tokenUsed($header['submission_token'])) {
            return [$existing, false];
        }
        $dn = trim((string) ($header['delivery_note_number'] ?? ''));
        if ($dn === '') {
            throw new BusinessRuleException('Enter the supplier delivery note number.');
        }
        if ($this->db->fetchValue('SELECT grn_number FROM goods_receipts WHERE supplier_id = :s AND delivery_note_number = :d', ['s' => $po['supplier_id'], 'd' => $dn])) {
            throw new BusinessRuleException("Delivery note $dn from this supplier has already been received. Duplicate receipts are not allowed.");
        }

        $poItems = [];
        foreach ($this->db->fetchAll('SELECT poi.*, p.name AS product_name FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id WHERE poi.purchase_order_id = :id', ['id' => $poId]) as $it) {
            $poItems[(int) $it['id']] = $it;
        }
        $tolerance = 1 + ((float) $this->settings->get('overreceipt_tolerance_pct', 0)) / 100;
        $accepted = [];
        $rejectOnly = [];
        $incoming = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $item = $poItems[(int) $line['purchase_order_item_id']] ?? throw new BusinessRuleException("Line $n: item is not on this purchase order.");
            $qty = (float) ($line['quantity'] ?? 0);
            $rejected = (float) ($line['qty_rejected'] ?? 0);
            if ($qty < 0 || $rejected < 0 || ($qty == 0.0 && $rejected == 0.0)) {
                continue;
            }
            if ($rejected > 0 && trim((string) ($line['rejection_reason'] ?? '')) === '') {
                throw new BusinessRuleException("Line $n: give a reason for the rejected quantity of {$item['product_name']}.");
            }
            $base = round($qty * (float) $item['unit_factor'], 3);
            $incoming[(int) $item['id']] = ($incoming[(int) $item['id']] ?? 0) + $base;
            if ($qty > 0) {
                $accepted[] = [
                    'product_id' => (int) $item['product_id'], 'lot_number' => $line['lot_number'] ?? null,
                    'manufacture_date' => $line['manufacture_date'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null,
                    'quantity' => $qty, 'unit_id' => (int) $item['order_unit_id'], 'unit_cost' => (float) $item['unit_price'],
                    'qty_rejected' => $rejected, 'rejection_reason' => $line['rejection_reason'] ?? null, '_po_item' => (int) $item['id'],
                ];
            } else {
                $rejectOnly[] = ['item' => $item, 'rejected' => round($rejected * (float) $item['unit_factor'], 3), 'reason' => $line['rejection_reason'], 'lot' => $line['lot_number'] ?? 'N/A'];
            }
        }
        if (!$accepted && !$rejectOnly) {
            throw new BusinessRuleException('Enter the quantity received for at least one line.');
        }
        foreach ($incoming as $itemId => $base) {
            $item = $poItems[$itemId];
            $allowed = (float) $item['ordered_qty_base'] * $tolerance - (float) $item['received_qty_base'];
            if ($base > $allowed + 1e-9) {
                throw new BusinessRuleException(sprintf('%s: receiving %s would exceed the ordered quantity (still outstanding: %s base units).',
                    $item['product_name'], qty($base), qty(max(0, (float) $item['ordered_qty_base'] - (float) $item['received_qty_base']))));
            }
        }

        $prepared = $accepted ? $this->prepareLines($accepted, $location) : [];
        foreach ($prepared as $k => &$pl) {
            $pl['_po_item'] = $accepted[$k]['_po_item'];
        }
        unset($pl);

        $grnId = $this->db->transaction(function (Database $db) use ($po, $header, $dn, $prepared, $rejectOnly, $location, $userId) {
            $grnId = $db->insert('goods_receipts', [
                'grn_number' => $this->numbers->next('GRN'), 'purchase_order_id' => (int) $po['id'], 'supplier_id' => (int) $po['supplier_id'],
                'receipt_type' => 'purchase_order', 'receiving_location_id' => (int) $location['id'], 'delivery_note_number' => $dn,
                'invoice_number' => $header['invoice_number'] ?? null, 'status' => 'posted', 'submission_token' => $header['submission_token'],
                'received_by' => $userId, 'posted_at' => date('Y-m-d H:i:s'), 'notes' => $header['notes'] ?? null,
            ]);
            $movements = [];
            foreach ($prepared as $line) {
                $batchId = $this->batchFor($line, (int) $po['supplier_id'], $userId);
                $db->insert('goods_receipt_items', [
                    'goods_receipt_id' => $grnId, 'purchase_order_item_id' => $line['_po_item'], 'product_id' => $line['product_id'],
                    'lot_number' => $line['lot_number'], 'manufacture_date' => $line['manufacture_date'], 'expiry_date' => $line['expiry_date'],
                    'qty_received' => $line['quantity'], 'qty_rejected' => $line['qty_rejected'], 'rejection_reason' => $line['rejection_reason'],
                    'unit_cost' => $line['unit_cost'], 'batch_id' => $batchId,
                ]);
                $movements[] = [
                    'product_id' => $line['product_id'], 'batch_id' => $batchId, 'movement_type' => 'RECEIVED',
                    'from_location_id' => null, 'to_location_id' => (int) $location['id'], 'quantity' => $line['quantity'],
                ];
            }
            foreach ($rejectOnly as $r) {
                $db->insert('goods_receipt_items', [
                    'goods_receipt_id' => $grnId, 'purchase_order_item_id' => (int) $r['item']['id'], 'product_id' => (int) $r['item']['product_id'],
                    'lot_number' => mb_substr((string) $r['lot'], 0, 60) ?: 'N/A', 'qty_received' => 0, 'qty_rejected' => $r['rejected'],
                    'rejection_reason' => mb_substr((string) $r['reason'], 0, 255), 'unit_cost' => round((float) $r['item']['unit_price'] / (float) $r['item']['unit_factor'], 4),
                ]);
            }
            if ($movements) {
                $this->stock->post([
                    'type' => 'receipt', 'department_id' => (int) $location['department_id'], 'performed_by' => $userId,
                    'reference_type' => 'goods_receipt', 'reference_id' => $grnId, 'approval_reference' => $po['po_number'],
                    'reason' => 'Purchase order ' . $po['po_number'], 'notes' => $header['notes'] ?? null,
                ], $movements);
            }
            $this->purchaseOrders->syncReceived((int) $po['id']);
            return $grnId;
        });
        $this->audit->log('create', 'goods_receipt', $grnId, "Received goods against {$po['po_number']} (DN $dn)", null, ['lines' => count($prepared) + count($rejectOnly)]);
        return [$grnId, true];
    }

    /** @return array{0:int, 1:bool} [goods_receipt_id, created(false = duplicate submission)] */
    public function receive(array $header, array $lines, int $userId): array
    {
        $type = $header['receipt_type'];
        if (!isset(self::TYPES[$type])) {
            throw new BusinessRuleException('Invalid receipt type.');
        }
        $location = $this->locations->find((int) $header['receiving_location_id']) ?? throw new BusinessRuleException('Receiving location not found.');
        $this->gate->authorize($type === 'opening_balance' ? 'stock.opening_balance' : 'goods_receipts.direct', (int) $location['department_id']);
        if ((int) $location['is_active'] !== 1 || in_array($location['type'], ['transit', 'quarantine'], true)) {
            throw new BusinessRuleException('Stock cannot be received into this location.');
        }
        if ($existing = $this->receipts->tokenUsed($header['submission_token'])) {
            return [$existing, false];
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product line.');
        }

        $prepared = $this->prepareLines($lines, $location);

        $grnId = $this->db->transaction(function (Database $db) use ($header, $prepared, $location, $type, $userId): int {
            $grnId = $db->insert('goods_receipts', [
                'grn_number'            => $this->numbers->next('GRN'),
                'purchase_order_id'     => null,
                'supplier_id'           => $header['supplier_id'] ?? null,
                'receipt_type'          => $type,
                'receiving_location_id' => (int) $location['id'],
                'delivery_note_number'  => $header['delivery_note_number'] ?? null,
                'invoice_number'        => $header['invoice_number'] ?? null,
                'status'                => 'posted',
                'submission_token'      => $header['submission_token'],
                'received_by'           => $userId,
                'posted_at'             => date('Y-m-d H:i:s'),
                'notes'                 => $header['notes'] ?? null,
            ]);

            $movements = [];
            foreach ($prepared as $line) {
                $batchId = $this->batchFor($line, $header['supplier_id'] ?? null, $userId);
                $db->insert('goods_receipt_items', [
                    'goods_receipt_id' => $grnId,
                    'product_id'       => $line['product_id'],
                    'lot_number'       => $line['lot_number'],
                    'manufacture_date' => $line['manufacture_date'],
                    'expiry_date'      => $line['expiry_date'],
                    'qty_received'     => $line['quantity'],
                    'qty_rejected'     => $line['qty_rejected'],
                    'rejection_reason' => $line['rejection_reason'],
                    'unit_cost'        => $line['unit_cost'],
                    'batch_id'         => $batchId,
                ]);
                $movements[] = [
                    'product_id' => $line['product_id'], 'batch_id' => $batchId, 'movement_type' => 'RECEIVED',
                    'from_location_id' => null, 'to_location_id' => (int) $location['id'], 'quantity' => $line['quantity'],
                ];
            }
            $this->stock->post([
                'type'           => $type === 'opening_balance' ? 'opening_balance' : 'receipt',
                'department_id'  => (int) $location['department_id'],
                'performed_by'   => $userId,
                'reference_type' => 'goods_receipt',
                'reference_id'   => $grnId,
                'reason'         => self::TYPES[$type],
                'notes'          => $header['notes'] ?? null,
            ], $movements);

            $this->audit->log('create', 'goods_receipt', $grnId, self::TYPES[$type] . ' received into ' . $location['name'], null, [
                'lines' => count($prepared), 'location' => $location['name'], 'type' => $type,
            ]);
            return $grnId;
        });
        return [$grnId, true];
    }

    /** Validate and normalise every line before anything is written. */
    private function prepareLines(array $lines, array $location): array
    {
        $today = date('Y-m-d');
        $out = [];
        $seen = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            if ((int) $product['is_active'] !== 1) {
                throw new BusinessRuleException("Line $n: {$product['name']} is inactive.");
            }
            if (in_array($product['storage_condition'], ['refrigerated', 'frozen'], true) && (int) $location['is_cold_chain'] !== 1) {
                throw new BusinessRuleException("Line $n: {$product['name']} requires cold storage. Choose a cold-chain location.");
            }
            [$qty, $factor] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $rejected = 0.0;
            if (!empty($line['qty_rejected'])) {
                [$rejected] = $this->converter->toBase((int) $product['id'], (float) $line['qty_rejected'], $line['unit_id'] ?? null, "Line $n rejected quantity");
            }

            $lot = trim((string) ($line['lot_number'] ?? ''));
            if ($lot === '') {
                if ((int) $product['is_batch_tracked'] === 1) {
                    throw new BusinessRuleException("Line $n: enter the manufacturer's lot/batch number for {$product['name']}.");
                }
                $lot = 'NOLOT-' . date('Ymd');
            }
            $expiry = $line['expiry_date'] ?: null;
            $mfg = $line['manufacture_date'] ?: null;
            if ((int) $product['is_expiry_tracked'] === 1 && $expiry === null) {
                throw new BusinessRuleException("Line $n: expiry date is required for {$product['name']}.");
            }
            if ($expiry !== null && $expiry <= $today) {
                throw new BusinessRuleException("Line $n: {$product['name']} lot $lot is expired or expires today. Expired stock cannot be received.");
            }
            if ($mfg !== null && $mfg > $today) {
                throw new BusinessRuleException("Line $n: manufacture date cannot be in the future.");
            }
            if ($mfg !== null && $expiry !== null && $mfg >= $expiry) {
                throw new BusinessRuleException("Line $n: manufacture date must be before the expiry date.");
            }
            $key = $product['id'] . '|' . mb_strtolower($lot);
            if (isset($seen[$key])) {
                throw new BusinessRuleException("Line $n: {$product['name']} lot $lot appears twice. Combine the quantities into one line.");
            }
            $seen[$key] = true;

            $out[] = [
                'product_id'       => (int) $product['id'],
                'product_name'     => $product['name'],
                'lot_number'       => mb_substr($lot, 0, 60),
                'manufacture_date' => $mfg,
                'expiry_date'      => $expiry,
                'quantity'         => $qty,
                'qty_rejected'     => $rejected,
                'rejection_reason' => $rejected > 0 ? mb_substr((string) ($line['rejection_reason'] ?? ''), 0, 255) : null,
                // cost is entered per selected unit; stored per base unit
                'unit_cost'        => round(((float) ($line['unit_cost'] ?? 0)) / $factor, 4),
            ];
        }
        return $out;
    }

    /** Re-use the batch for the same product + lot + supplier, or create a new one. */
    private function batchFor(array $line, ?int $supplierId, int $userId): int
    {
        $existing = $this->batches->findLot($line['product_id'], $line['lot_number'], $supplierId);
        if ($existing) {
            if ($existing['expiry_date'] !== $line['expiry_date']) {
                throw new BusinessRuleException("{$line['product_name']} lot {$line['lot_number']} was received before with a different expiry date ({$existing['expiry_date']}). Check the lot number.");
            }
            if (in_array($existing['status'], ['recalled', 'quarantined'], true)) {
                throw new BusinessRuleException("{$line['product_name']} lot {$line['lot_number']} is {$existing['status']} and cannot be received.");
            }
            // Moving average cost for the batch.
            $oldQty = (float) $existing['quantity_received'];
            $newQty = $oldQty + $line['quantity'];
            $cost = $newQty > 0 ? (($oldQty * (float) $existing['unit_cost']) + ($line['quantity'] * $line['unit_cost'])) / $newQty : $line['unit_cost'];
            $this->db->update('product_batches', [
                'quantity_received' => $newQty,
                'unit_cost'         => round($cost, 4),
            ], 'id = :id', ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
        return $this->batches->create([
            'product_id'        => $line['product_id'],
            'batch_code'        => $this->numbers->batchCode(),
            'lot_number'        => $line['lot_number'],
            'supplier_id'       => $supplierId,
            'manufacture_date'  => $line['manufacture_date'],
            'expiry_date'       => $line['expiry_date'],
            'unit_cost'         => $line['unit_cost'],
            'quantity_received' => $line['quantity'],
            'status'            => 'active',
            'created_by'        => $userId,
        ]);
    }
}
