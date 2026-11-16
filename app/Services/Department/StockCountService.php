<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Repositories\StockCountRepository;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Physical stock counts (blind: counters do not see system quantities).
 *   in_progress → submitted → approved (variances posted as a count_variance adjustment)
 * The approver must be a different person from the one who started the count.
 */
final class StockCountService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockCountRepository $counts,
        private LocationRepository $locations,
        private AdjustmentService $adjustments,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function start(int $locationId, string $type, ?string $notes, int $userId): int
    {
        $loc = $this->locations->find($locationId) ?? throw new BusinessRuleException('Location not found.');
        $this->gate->authorize('counts.perform', (int) $loc['department_id']);
        if ($loc['type'] === 'transit') {
            throw new BusinessRuleException('The In Transit location cannot be counted.');
        }
        if ($this->db->fetchValue("SELECT 1 FROM stock_counts WHERE location_id = :l AND status IN ('in_progress','submitted')", ['l' => $locationId])) {
            throw new BusinessRuleException('A count is already open for this location. Finish or cancel it first.');
        }
        $id = $this->db->transaction(function (Database $db) use ($locationId, $type, $notes, $userId) {
            $id = $db->insert('stock_counts', [
                'count_number' => $this->numbers->next('CNT'), 'location_id' => $locationId, 'type' => $type,
                'status' => 'in_progress', 'notes' => $notes, 'started_by' => $userId,
            ]);
            // Snapshot every batch held at the location.
            $db->execute(
                'INSERT INTO stock_count_items (stock_count_id, batch_id, system_qty)
                 SELECT :c, sb.batch_id, sb.quantity FROM stock_balances sb WHERE sb.location_id = :l AND sb.quantity > 0',
                ['c' => $id, 'l' => $locationId]
            );
            return $id;
        });
        $this->audit->log('create', 'stock_count', $id, "Started count at {$loc['name']}");
        return $id;
    }

    /** @param array<int, ?float> $counted item_id => counted quantity (null = not counted yet) */
    public function saveCounts(int $id, array $counted, int $userId, bool $submit): void
    {
        $count = $this->counts->find($id) ?? throw new BusinessRuleException('Count not found.');
        $this->gate->authorize('counts.perform', (int) $count['department_id']);
        if ($count['status'] !== 'in_progress') {
            throw new BusinessRuleException('This count is no longer open.');
        }
        $this->db->transaction(function (Database $db) use ($id, $counted, $userId, $submit) {
            foreach ($this->counts->items($id) as $item) {
                if (!array_key_exists((int) $item['id'], $counted)) {
                    continue;
                }
                $value = $counted[(int) $item['id']];
                if ($value !== null && $value < 0) {
                    throw new BusinessRuleException('Counted quantities cannot be negative.');
                }
                $db->update('stock_count_items', [
                    'counted_qty' => $value, 'counted_by' => $value === null ? null : $userId,
                    'counted_at' => $value === null ? null : date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $item['id']]);
            }
            if ($submit) {
                $missing = (int) $db->fetchValue('SELECT COUNT(*) FROM stock_count_items WHERE stock_count_id = :id AND counted_qty IS NULL', ['id' => $id]);
                if ($missing > 0) {
                    throw new BusinessRuleException("$missing batch(es) have not been counted yet. Enter 0 for batches that are not physically present.");
                }
                $db->update('stock_counts', ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            }
        });
        if ($submit) {
            $this->audit->log('submit', 'stock_count', $id, "Submitted count {$count['count_number']}");
            $this->notifications->notifyPermission('counts.approve', [
                'type' => 'count_submitted', 'severity' => 'info', 'title' => "Stock count {$count['count_number']} submitted",
                'message' => "Count at {$count['location_name']} is ready for review.", 'link_url' => '/counts/' . $id,
                'entity_type' => 'stock_count', 'entity_id' => $id, 'dedupe_key' => 'count_submitted:' . $id,
            ], (int) $count['department_id']);
        }
    }

    public function approve(int $id, int $userId): ?int
    {
        $result = $this->db->transaction(function (Database $db) use ($id, $userId) {
            $count = $db->fetchOne('SELECT c.*, l.department_id FROM stock_counts c JOIN inventory_locations l ON l.id = c.location_id WHERE c.id = :id FOR UPDATE', ['id' => $id])
                ?? throw new BusinessRuleException('Count not found.');
            $this->gate->authorize('counts.approve', (int) $count['department_id']);
            ApprovalRules::assertDifferentUser((int) $count['started_by'], $userId, 'stock count');
            if ($count['status'] !== 'submitted') {
                throw new BusinessRuleException('Only submitted counts can be approved.');
            }
            $variances = $db->fetchAll('SELECT batch_id, variance FROM stock_count_items WHERE stock_count_id = :id AND variance <> 0', ['id' => $id]);
            $adjId = null;
            if ($variances) {
                $adjId = $db->insert('stock_adjustments', [
                    'adjustment_number' => $this->numbers->next('ADJ'), 'location_id' => (int) $count['location_id'],
                    'reason_code' => 'count_variance', 'status' => 'pending', 'stock_count_id' => $id,
                    'notes' => 'Variance from stock count ' . $count['count_number'], 'requested_by' => (int) $count['started_by'],
                ]);
                foreach ($variances as $v) {
                    $db->insert('stock_adjustment_items', [
                        'stock_adjustment_id' => $adjId, 'batch_id' => (int) $v['batch_id'],
                        'quantity_change' => (float) $v['variance'], 'reason' => 'Count ' . $count['count_number'],
                    ]);
                }
                $adj = $db->fetchOne('SELECT * FROM stock_adjustments WHERE id = :id', ['id' => $adjId]);
                $this->adjustments->post($adj, $userId);
            }
            $db->update('stock_counts', ['status' => 'approved', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            return [$count, $adjId, count($variances)];
        });
        [$count, $adjId, $n] = $result;
        $this->audit->log('approve', 'stock_count', $id, "Approved count {$count['count_number']}: $n variance(s) posted");
        return $adjId;
    }

    public function cancel(int $id, int $userId): void
    {
        $count = $this->counts->find($id) ?? throw new BusinessRuleException('Count not found.');
        $this->gate->authorize('counts.perform', (int) $count['department_id']);
        if (!in_array($count['status'], ['in_progress', 'submitted'], true)) {
            throw new BusinessRuleException('This count cannot be cancelled.');
        }
        $this->db->update('stock_counts', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        $this->audit->log('cancel', 'stock_count', $id, "Cancelled count {$count['count_number']}");
    }
}
