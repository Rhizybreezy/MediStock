<?php
declare(strict_types=1);

namespace App\Services\Stock;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TransferRepository;
use App\Services\Department\RequestProgressService;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Two-step, accountable stock issue:
 *
 *   dispatch()       source store → In Transit      (issuer; FEFO batch allocation)
 *   confirmReceipt() In Transit  → destination      (receiving department; full or partial)
 *   returnOutstanding() In Transit → source         (issuer; resolves a short receipt)
 *
 * Stock that was dispatched but not confirmed never "disappears": it stays
 * visible in the Transit location until received or returned.
 * Phase 3 reuses this flow for stock requests and department transfers.
 */
final class IssueService
{
    private const MOVEMENT = ['issue' => 'ISSUED', 'transfer' => 'TRANSFERRED', 'redistribution' => 'TRANSFERRED', 'return_to_store' => 'RETURNED'];
    private const PREFIX = ['issue' => 'ISS', 'transfer' => 'TRF', 'redistribution' => 'TRF', 'return_to_store' => 'RTN'];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockService $stock,
        private FefoAllocator $fefo,
        private QuantityConverter $converter,
        private ProductRepository $products,
        private LocationRepository $locations,
        private TransferRepository $transfers,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
        private RequestProgressService $requestProgress,
    ) {
    }

    /**
     * Create and dispatch an issue in one step.
     * @param array $lines [['product_id', 'quantity', 'unit_id'?, 'batch_id'?], ...]
     * @return int stock_transfers.id
     */
    public function dispatch(array $header, array $lines, int $userId, string $type = 'issue', ?int $stockRequestId = null): int
    {
        $source = $this->locations->find((int) $header['source_location_id']) ?? throw new BusinessRuleException('Source location not found.');
        $dest = $this->locations->find((int) $header['destination_location_id']) ?? throw new BusinessRuleException('Destination location not found.');
        $this->gate->authorize('stock.issue', (int) $source['department_id']);

        foreach ([$source, $dest] as $loc) {
            if ((int) $loc['is_active'] !== 1 || in_array($loc['type'], ['transit', 'quarantine'], true)) {
                throw new BusinessRuleException("{$loc['name']} cannot be used for issuing stock.");
            }
        }
        if ((int) $source['id'] === (int) $dest['id']) {
            throw new BusinessRuleException('Source and destination must be different locations.');
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product line.');
        }

        // Normalise lines: base quantities, cold-chain rule, merge duplicates (same product + batch choice)
        $wanted = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            if (in_array($product['storage_condition'], ['refrigerated', 'frozen'], true) && (int) $dest['is_cold_chain'] !== 1) {
                throw new BusinessRuleException("Line $n: {$product['name']} requires cold storage, but {$dest['name']} is not a cold-chain location.");
            }
            [$qty] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $batch = !empty($line['batch_id']) ? (int) $line['batch_id'] : null;
            $key = $product['id'] . ':' . ($batch ?? 'fefo');
            $wanted[$key] = [
                'product_id' => (int) $product['id'],
                'batch_id'   => $batch,
                'quantity'   => round(($wanted[$key]['quantity'] ?? 0) + $qty, 3),
            ];
        }

        $transitId = $this->locations->transitId();
        $movementType = self::MOVEMENT[$type];

        $transferId = $this->db->transaction(function (Database $db) use ($header, $wanted, $source, $dest, $userId, $type, $stockRequestId, $transitId, $movementType): int {
            $now = date('Y-m-d H:i:s');
            $transferId = $db->insert('stock_transfers', [
                'transfer_number'           => $this->numbers->next(self::PREFIX[$type]),
                'type'                      => $type,
                'source'                    => $stockRequestId ? 'stock_request' : 'manual',
                'stock_request_id'          => $stockRequestId,
                'source_department_id'      => (int) $source['department_id'],
                'source_location_id'        => (int) $source['id'],
                'destination_department_id' => (int) $dest['department_id'],
                'destination_location_id'   => (int) $dest['id'],
                'status'                    => 'dispatched',
                'reason'                    => $header['reason'] ?? null,
                'requested_by'              => $userId,
                'approved_by'               => $userId,
                'approved_at'               => $now,
                'dispatched_by'             => $userId,
                'dispatched_at'             => $now,
            ]);

            $movements = [];
            foreach ($wanted as $w) {
                // FEFO allocation with row locks (or the manually chosen batch)
                foreach ($this->fefo->allocate($w['product_id'], (int) $source['id'], $w['quantity'], $w['batch_id']) as $alloc) {
                    $db->insert('stock_transfer_items', [
                        'stock_transfer_id' => $transferId,
                        'product_id'        => $w['product_id'],
                        'batch_id'          => $alloc['batch_id'],
                        'requested_qty'     => $alloc['quantity'],
                        'approved_qty'      => $alloc['quantity'],
                        'dispatched_qty'    => $alloc['quantity'],
                    ]);
                    $movements[] = [
                        'product_id' => $w['product_id'], 'batch_id' => $alloc['batch_id'], 'movement_type' => $movementType,
                        'from_location_id' => (int) $source['id'], 'to_location_id' => $transitId, 'quantity' => $alloc['quantity'],
                    ];
                }
            }
            $this->stock->post([
                'type'           => $type === 'issue' ? 'issue_dispatch' : ($type === 'return_to_store' ? 'return' : 'transfer_dispatch'),
                'department_id'  => (int) $source['department_id'],
                'performed_by'   => $userId,
                'approved_by'    => $userId,
                'reference_type' => 'stock_transfer',
                'reference_id'   => $transferId,
                'reason'         => $header['reason'] ?? null,
            ], $movements);
            return $transferId;
        });

        $transfer = $this->transfers->find($transferId);
        $this->audit->log('dispatch', 'stock_transfer', $transferId, "Dispatched {$transfer['transfer_number']} to {$dest['name']}", null, [
            'from' => $source['name'], 'to' => $dest['name'], 'lines' => count($wanted),
        ]);
        if ($stockRequestId) {
            $this->requestProgress->sync($stockRequestId);
        }
        $this->notifyIncoming($transferId);
        return $transferId;
    }

    /**
     * Dispatch a transfer/return that was created and approved earlier:
     * each approved request line is allocated FEFO into batch-level child rows.
     */
    public function dispatchApproved(int $transferId, int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($transferId, $userId) {
            $t = $this->transfers->lockForUpdate($transferId) ?? throw new BusinessRuleException('Transfer not found.');
            if (!$this->gate->can('transfers.create', (int) $t['source_department_id']) && !$this->gate->can('stock.issue', (int) $t['source_department_id'])) {
                $this->gate->authorize('transfers.create', (int) $t['source_department_id']);
            }
            if ($t['status'] !== 'approved') {
                throw new BusinessRuleException('Only approved transfers can be dispatched.');
            }
            $dest = $this->locations->find((int) $t['destination_location_id']);
            $transitId = $this->locations->transitId();
            $movementType = self::MOVEMENT[$t['type']];
            $movements = [];
            $lines = $db->fetchAll(
                'SELECT i.*, p.name AS product_name, p.storage_condition FROM stock_transfer_items i JOIN products p ON p.id = i.product_id
                  WHERE i.stock_transfer_id = :t AND i.parent_item_id IS NULL AND i.dispatched_qty = 0 AND i.approved_qty > 0',
                ['t' => $transferId]
            );
            if (!$lines) {
                throw new BusinessRuleException('Nothing approved to dispatch.');
            }
            foreach ($lines as $line) {
                if (in_array($line['storage_condition'], ['refrigerated', 'frozen'], true) && (int) $dest['is_cold_chain'] !== 1) {
                    throw new BusinessRuleException("{$line['product_name']} requires cold storage, but {$dest['name']} is not a cold-chain location.");
                }
                $alloc = $this->fefo->allocate((int) $line['product_id'], (int) $t['source_location_id'], (float) $line['approved_qty'], $line['batch_id'] ? (int) $line['batch_id'] : null);
                foreach ($alloc as $a) {
                    $db->insert('stock_transfer_items', [
                        'stock_transfer_id' => $transferId,
                        'parent_item_id'    => (int) $line['id'],
                        'product_id'        => (int) $line['product_id'],
                        'batch_id'          => $a['batch_id'],
                        'requested_qty'     => $a['quantity'],
                        'approved_qty'      => $a['quantity'],
                        'dispatched_qty'    => $a['quantity'],
                    ]);
                    $movements[] = [
                        'product_id' => (int) $line['product_id'], 'batch_id' => $a['batch_id'], 'movement_type' => $movementType,
                        'from_location_id' => (int) $t['source_location_id'], 'to_location_id' => $transitId, 'quantity' => $a['quantity'],
                    ];
                }
            }
            $this->stock->post([
                'type'           => $t['type'] === 'return_to_store' ? 'return' : 'transfer_dispatch',
                'department_id'  => (int) $t['source_department_id'],
                'performed_by'   => $userId,
                'approved_by'    => $t['approved_by'],
                'approval_reference' => $t['transfer_number'],
                'reference_type' => 'stock_transfer',
                'reference_id'   => $transferId,
                'reason'         => $t['reason'],
            ], $movements);
            $db->update('stock_transfers', [
                'status' => 'dispatched', 'dispatched_by' => $userId, 'dispatched_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $transferId]);
        });
        $t = $this->transfers->find($transferId);
        $this->audit->log('dispatch', 'stock_transfer', $transferId, "Dispatched {$t['transfer_number']} to {$t['destination_location']}");
        $this->notifyIncoming($transferId);
    }

    private function notifyIncoming(int $transferId): void
    {
        $transfer = $this->transfers->find($transferId);
        $this->notifications->notifyPermission('stock.confirm_receipt', [
            'type'        => 'stock_incoming',
            'severity'    => 'info',
            'title'       => "Incoming stock: {$transfer['transfer_number']}",
            'message'     => "{$transfer['source_department']} dispatched {$transfer['product_count']} product(s) to {$transfer['destination_location']}. Please confirm receipt.",
            'link_url'    => '/inventory/issues/' . $transferId,
            'entity_type' => 'stock_transfer',
            'entity_id'   => $transferId,
            'dedupe_key'  => 'transfer_dispatched:' . $transferId,
        ], (int) $transfer['destination_department_id']);
    }

    /**
     * Destination confirms what physically arrived.
     * @param array<int, float> $received item_id => quantity received now (base units)
     */
    public function confirmReceipt(int $transferId, array $received, ?string $notes, int $userId): void
    {
        $result = $this->db->transaction(function (Database $db) use ($transferId, $received, $notes, $userId) {
            $t = $this->transfers->lockForUpdate($transferId) ?? throw new BusinessRuleException('Transfer not found.');
            $this->gate->authorize('stock.confirm_receipt', (int) $t['destination_department_id']);
            if (!in_array($t['status'], ['dispatched', 'partially_received'], true)) {
                throw new BusinessRuleException('This delivery is not awaiting receipt.');
            }
            $transitId = $this->locations->transitId();
            $movementType = self::MOVEMENT[$t['type']];
            $movements = [];
            $outstandingAfter = 0.0;
            foreach ($this->transfers->items($transferId) as $item) {
                $outstanding = round((float) $item['dispatched_qty'] - (float) $item['received_qty'] - (float) $item['returned_qty'], 3);
                $qty = round((float) ($received[(int) $item['id']] ?? 0), 3);
                if ($qty < 0 || $qty > $outstanding + 1e-9) {
                    throw new BusinessRuleException("{$item['product_name']} ({$item['batch_code']}): received quantity must be between 0 and " . qty($outstanding) . '.');
                }
                if ($qty > 0) {
                    $db->execute('UPDATE stock_transfer_items SET received_qty = received_qty + :q WHERE id = :id', ['q' => $qty, 'id' => $item['id']]);
                    $movements[] = [
                        'product_id' => (int) $item['product_id'], 'batch_id' => (int) $item['batch_id'], 'movement_type' => $movementType,
                        'from_location_id' => $transitId, 'to_location_id' => (int) $t['destination_location_id'], 'quantity' => $qty,
                    ];
                }
                $outstandingAfter += $outstanding - $qty;
            }
            if (!$movements) {
                throw new BusinessRuleException('Enter the quantity received for at least one line.');
            }
            $this->stock->post([
                'type'           => $t['type'] === 'issue' ? 'issue_receipt' : 'transfer_receipt',
                'department_id'  => (int) $t['destination_department_id'],
                'performed_by'   => $userId,
                'reference_type' => 'stock_transfer',
                'reference_id'   => $transferId,
                'reason'         => 'Receipt confirmed',
                'notes'          => $notes,
            ], $movements);

            $complete = $outstandingAfter <= 1e-9;
            $update = ['status' => $complete ? 'received' : 'partially_received'];
            if ($complete) {
                $update['received_by'] = $userId;
                $update['received_at'] = date('Y-m-d H:i:s');
            }
            if ($notes) {
                $update['discrepancy_notes'] = mb_substr(trim(($t['discrepancy_notes'] ? $t['discrepancy_notes'] . ' | ' : '') . $notes), 0, 255);
            }
            $db->update('stock_transfers', $update, 'id = :id', ['id' => $transferId]);
            return [$t, $complete, $outstandingAfter];
        });

        [$t, $complete, $outstanding] = $result;
        if ($t['stock_request_id']) {
            $this->requestProgress->sync((int) $t['stock_request_id']);
        }
        $this->audit->log('receive', 'stock_transfer', $transferId, ($complete ? 'Confirmed full receipt of ' : 'Confirmed partial receipt of ') . $t['transfer_number'], null, ['outstanding' => $outstanding]);
        if (!$complete) {
            $this->notifications->notifyUser((int) $t['dispatched_by'], [
                'type'        => 'stock_short_receipt',
                'severity'    => 'warning',
                'title'       => "Short receipt: {$t['transfer_number']}",
                'message'     => 'The receiving department confirmed less than was dispatched. ' . qty($outstanding) . ' unit(s) remain in transit.',
                'link_url'    => '/inventory/issues/' . $transferId,
                'entity_type' => 'stock_transfer',
                'entity_id'   => $transferId,
                'dedupe_key'  => 'transfer_short:' . $transferId . ':' . date('YmdHi'),
            ]);
        }
    }

    /** Issuer returns whatever is still in transit to the source location (closes the delivery). */
    public function returnOutstanding(int $transferId, string $reason, int $userId): void
    {
        $t = $this->db->transaction(function (Database $db) use ($transferId, $reason, $userId) {
            $t = $this->transfers->lockForUpdate($transferId) ?? throw new BusinessRuleException('Transfer not found.');
            $this->gate->authorize('stock.issue', (int) $t['source_department_id']);
            if (!in_array($t['status'], ['dispatched', 'partially_received'], true)) {
                throw new BusinessRuleException('Nothing is in transit for this delivery.');
            }
            $transitId = $this->locations->transitId();
            $movements = [];
            foreach ($this->transfers->items($transferId) as $item) {
                $outstanding = round((float) $item['dispatched_qty'] - (float) $item['received_qty'] - (float) $item['returned_qty'], 3);
                if ($outstanding <= 0) {
                    continue;
                }
                $db->execute('UPDATE stock_transfer_items SET returned_qty = returned_qty + :q WHERE id = :id', ['q' => $outstanding, 'id' => $item['id']]);
                $movements[] = [
                    'product_id' => (int) $item['product_id'], 'batch_id' => (int) $item['batch_id'], 'movement_type' => 'RETURNED',
                    'from_location_id' => $transitId, 'to_location_id' => (int) $t['source_location_id'], 'quantity' => $outstanding,
                    'reason' => $reason,
                ];
            }
            if (!$movements) {
                throw new BusinessRuleException('Nothing is in transit for this delivery.');
            }
            $this->stock->post([
                'type' => 'return', 'department_id' => (int) $t['source_department_id'], 'performed_by' => $userId,
                'reference_type' => 'stock_transfer', 'reference_id' => $transferId, 'reason' => $reason,
            ], $movements);
            $db->update('stock_transfers', [
                'status' => 'received',
                'received_at' => $t['received_at'] ?? date('Y-m-d H:i:s'),
                'received_by' => $t['received_by'] ?? $userId,
                'discrepancy_notes' => mb_substr(trim(($t['discrepancy_notes'] ? $t['discrepancy_notes'] . ' | ' : '') . 'Outstanding quantity returned to source: ' . $reason), 0, 255),
            ], 'id = :id', ['id' => $transferId]);
            return $t;
        });
        if ($t['stock_request_id']) {
            $this->requestProgress->sync((int) $t['stock_request_id']);
        }
        $this->audit->log('return', 'stock_transfer', $transferId, "Returned in-transit stock of {$t['transfer_number']} to source", null, ['reason' => $reason]);
    }
}
