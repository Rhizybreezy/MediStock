<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\DepartmentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TransferRepository;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\IssueService;
use App\Services\Stock\QuantityConverter;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Department-to-department transfers and returns to the central store:
 *   pending_approval → approved | rejected → dispatched → (partially_)received
 * Dispatch and receipt reuse IssueService (FEFO, transit, confirmation).
 */
final class TransferService
{
    public const TYPES = ['transfer' => 'Department transfer', 'return_to_store' => 'Return to store', 'redistribution' => 'Redistribution'];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private TransferRepository $transfers,
        private LocationRepository $locations,
        private DepartmentRepository $departments,
        private ProductRepository $products,
        private QuantityConverter $converter,
        private FefoAllocator $fefo,
        private IssueService $issues,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function create(string $type, array $header, array $lines, int $userId, string $origin = 'manual'): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new BusinessRuleException('Invalid transfer type.');
        }
        $source = $this->locations->find((int) $header['source_location_id']) ?? throw new BusinessRuleException('Source location not found.');
        $dest = $this->locations->find((int) $header['destination_location_id']) ?? throw new BusinessRuleException('Destination location not found.');
        $this->gate->authorize('transfers.create', (int) $source['department_id']);
        foreach ([$source, $dest] as $loc) {
            if ((int) $loc['is_active'] !== 1 || $loc['type'] === 'transit') {
                throw new BusinessRuleException("{$loc['name']} cannot be used for transfers.");
            }
        }
        if ((int) $source['id'] === (int) $dest['id']) {
            throw new BusinessRuleException('Source and destination must be different locations.');
        }
        if ($type === 'return_to_store' && (int) $dest['department_id'] !== $this->departments->centralStoreId()) {
            throw new BusinessRuleException('Returns must go to a central store location.');
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one product.');
        }

        $items = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $product = $this->products->find((int) $line['product_id']) ?? throw new BusinessRuleException("Line $n: product not found.");
            [$qty] = $this->converter->toBase((int) $product['id'], (float) $line['quantity'], $line['unit_id'] ?? null, "Line $n quantity");
            $batch = !empty($line['batch_id']) ? (int) $line['batch_id'] : null;
            // Early availability check (re-checked with locks at dispatch).
            $available = array_sum(array_column(array_filter(
                $this->fefo->candidates((int) $product['id'], (int) $source['id']),
                fn ($c) => $batch === null || $c['batch_id'] === $batch
            ), 'available'));
            if ($available + 1e-9 < $qty) {
                throw new BusinessRuleException("Line $n: only " . qty($available) . " {$product['unit_code']} of {$product['name']} available at {$source['name']}.");
            }
            $items[] = ['product_id' => (int) $product['id'], 'batch_id' => $batch, 'qty' => $qty];
        }

        if (!in_array($origin, ['manual', 'ai_proposal', 'redistribution_engine'], true)) {
            throw new BusinessRuleException('Invalid transfer origin.');
        }
        $id = $this->db->transaction(function (Database $db) use ($type, $header, $items, $source, $dest, $userId, $origin) {
            $id = $db->insert('stock_transfers', [
                'transfer_number'           => $this->numbers->next($type === 'return_to_store' ? 'RTN' : 'TRF'),
                'type'                      => $type,
                'source'                    => $origin,
                'source_department_id'      => (int) $source['department_id'],
                'source_location_id'        => (int) $source['id'],
                'destination_department_id' => (int) $dest['department_id'],
                'destination_location_id'   => (int) $dest['id'],
                'status'                    => 'pending_approval',
                'reason'                    => $header['reason'] ?? null,
                'requested_by'              => $userId,
            ]);
            foreach ($items as $it) {
                $db->insert('stock_transfer_items', [
                    'stock_transfer_id' => $id, 'product_id' => $it['product_id'], 'batch_id' => $it['batch_id'],
                    'requested_qty' => $it['qty'],
                ]);
            }
            return $id;
        });
        $t = $this->transfers->find($id);
        $this->audit->log('create', 'stock_transfer', $id, self::TYPES[$type] . " {$t['transfer_number']} requested", null, ['lines' => count($items)]);
        $this->notifications->notifyPermission('transfers.approve', [
            'type' => 'transfer_pending', 'severity' => 'info',
            'title' => self::TYPES[$type] . " {$t['transfer_number']} awaits approval",
            'message' => "{$t['source_department']} → {$t['destination_department']}: {$t['product_count']} product(s).",
            'link_url' => '/transfers/' . $id, 'entity_type' => 'stock_transfer', 'entity_id' => $id,
            'dedupe_key' => 'transfer_pending:' . $id,
        ], (int) $source['department_id']);
        return $id;
    }

    /** @param array<int, float> $approved item_id => approved quantity */
    public function approve(int $id, array $approved, int $userId): void
    {
        $t = $this->db->transaction(function (Database $db) use ($id, $approved, $userId) {
            $t = $this->transfers->lockForUpdate($id) ?? throw new BusinessRuleException('Transfer not found.');
            $this->gate->authorize('transfers.approve', (int) $t['source_department_id']);
            ApprovalRules::assertDifferentUser((int) $t['requested_by'], $userId, 'transfer');
            if ($t['status'] !== 'pending_approval') {
                throw new BusinessRuleException('Only transfers awaiting approval can be approved.');
            }
            $any = false;
            foreach ($this->requestLines($id) as $line) {
                $qty = round((float) ($approved[(int) $line['id']] ?? $line['requested_qty']), 3);
                if ($qty < 0 || $qty > (float) $line['requested_qty'] + 1e-9) {
                    throw new BusinessRuleException("{$line['product_name']}: approved quantity must be between 0 and " . qty($line['requested_qty']) . '.');
                }
                $any = $any || $qty > 0;
                $db->update('stock_transfer_items', ['approved_qty' => $qty], 'id = :id', ['id' => $line['id']]);
            }
            if (!$any) {
                throw new BusinessRuleException('Approve at least one quantity, or reject the transfer instead.');
            }
            $db->update('stock_transfers', ['status' => 'approved', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            return $t;
        });
        $this->audit->log('approve', 'stock_transfer', $id, "Approved {$t['transfer_number']}");
        $this->notifications->notifyUser((int) $t['requested_by'], [
            'type' => 'transfer_approved', 'severity' => 'info', 'title' => "{$t['transfer_number']} approved",
            'message' => 'The transfer was approved and can now be dispatched.', 'link_url' => '/transfers/' . $id,
            'entity_type' => 'stock_transfer', 'entity_id' => $id, 'dedupe_key' => 'transfer_approved:' . $id,
        ]);
    }

    public function reject(int $id, string $reason, int $userId): void
    {
        $t = $this->transfers->find($id) ?? throw new BusinessRuleException('Transfer not found.');
        $this->gate->authorize('transfers.approve', (int) $t['source_department_id']);
        ApprovalRules::assertDifferentUser((int) $t['requested_by'], $userId, 'transfer');
        if ($t['status'] !== 'pending_approval') {
            throw new BusinessRuleException('Only transfers awaiting approval can be rejected.');
        }
        $this->db->update('stock_transfers', ['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'), 'rejection_reason' => $reason], 'id = :id', ['id' => $id]);
        $this->audit->log('reject', 'stock_transfer', $id, "Rejected {$t['transfer_number']}", null, ['reason' => $reason]);
        $this->notifications->notifyUser((int) $t['requested_by'], [
            'type' => 'transfer_rejected', 'severity' => 'warning', 'title' => "{$t['transfer_number']} rejected",
            'message' => "Reason: $reason", 'link_url' => '/transfers/' . $id,
            'entity_type' => 'stock_transfer', 'entity_id' => $id, 'dedupe_key' => 'transfer_rejected:' . $id,
        ]);
    }

    public function cancel(int $id, int $userId): void
    {
        $t = $this->transfers->find($id) ?? throw new BusinessRuleException('Transfer not found.');
        $this->gate->authorize('transfers.create', (int) $t['source_department_id']);
        if (!in_array($t['status'], ['pending_approval', 'approved'], true)) {
            throw new BusinessRuleException('Only transfers that have not been dispatched can be cancelled.');
        }
        $this->db->update('stock_transfers', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        $this->audit->log('cancel', 'stock_transfer', $id, "Cancelled {$t['transfer_number']}");
    }

    public function dispatch(int $id, int $userId): void
    {
        $this->issues->dispatchApproved($id, $userId);
    }

    private function requestLines(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name FROM stock_transfer_items i JOIN products p ON p.id = i.product_id
              WHERE i.stock_transfer_id = :t AND i.parent_item_id IS NULL AND i.dispatched_qty = 0',
            ['t' => $id]
        );
    }
}
