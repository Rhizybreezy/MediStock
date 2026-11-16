<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\BatchRepository;
use App\Repositories\LocationRepository;
use App\Repositories\RecallRepository;
use App\Services\Stock\StockService;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;

/**
 * Batch recalls: block the batch everywhere, trace every department that
 * received it, quarantine remaining stock, then close the recall.
 */
final class RecallService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private RecallRepository $recalls,
        private BatchRepository $batches,
        private LocationRepository $locations,
        private StockService $stock,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function create(array $data, int $userId): int
    {
        $this->gate->authorize('recalls.manage');
        $batch = $this->batches->find((int) $data['batch_id']) ?? throw new BusinessRuleException('Batch not found.');
        if ($this->db->fetchValue("SELECT 1 FROM batch_recalls WHERE batch_id = :b AND status <> 'closed'", ['b' => $batch['id']])) {
            throw new BusinessRuleException('There is already an open recall for this batch.');
        }
        $id = $this->db->transaction(function (Database $db) use ($data, $batch, $userId) {
            $id = $db->insert('batch_recalls', [
                'recall_number' => $this->numbers->next('RCL'), 'batch_id' => (int) $batch['id'],
                'reason' => $data['reason'], 'severity' => $data['severity'], 'source' => $data['source'],
                'status' => 'open', 'initiated_by' => $userId,
            ]);
            // Blocks the batch from FEFO allocation everywhere immediately.
            $db->update('product_batches', ['status' => 'recalled'], 'id = :id', ['id' => $batch['id']]);
            return $id;
        });
        cache_bump();
        $recall = $this->recalls->find($id);
        $this->audit->log('create', 'batch_recall', $id, "Recall {$recall['recall_number']} for batch {$batch['batch_code']} ({$batch['product_name']})", null, ['reason' => $data['reason']]);

        foreach ($this->trace($id)['holding'] as $h) {
            $this->notifications->notifyPermission('stock.view', [
                'type' => 'recall', 'severity' => 'critical',
                'title' => "RECALL: {$batch['product_name']} lot {$batch['lot_number']}",
                'message' => qty($h['quantity']) . " unit(s) of batch {$batch['batch_code']} at {$h['location_name']}. Stop using it and keep it aside.",
                'link_url' => '/recalls/' . $id, 'entity_type' => 'batch_recall', 'entity_id' => $id,
                'dedupe_key' => 'recall:' . $id . ':' . $h['department_id'],
            ], (int) $h['department_id']);
        }
        return $id;
    }

    /** Where the batch is now, and every department that ever received it. */
    public function trace(int $id): array
    {
        $recall = $this->recalls->find($id) ?? throw new BusinessRuleException('Recall not found.');
        $holding = $this->db->fetchAll(
            'SELECT l.id AS location_id, l.name AS location_name, l.type, d.id AS department_id, d.name AS department_name, sb.quantity
               FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id JOIN departments d ON d.id = l.department_id
              WHERE sb.batch_id = :b AND sb.quantity > 0 ORDER BY d.name, l.name',
            ['b' => $recall['batch_id']]
        );
        $received = $this->db->fetchAll(
            "SELECT d.name AS department_name, l.name AS location_name, SUM(m.quantity) AS received_qty,
                    MIN(m.created_at) AS first_received, MAX(m.created_at) AS last_received,
                    (SELECT COALESCE(SUM(c.quantity), 0) FROM stock_movements c
                      WHERE c.batch_id = m.batch_id AND c.from_location_id = l.id AND c.movement_type = 'CONSUMED') AS consumed_qty
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.to_location_id JOIN departments d ON d.id = l.department_id
              WHERE m.batch_id = :b AND l.type <> 'transit'
              GROUP BY l.id ORDER BY first_received",
            ['b' => $recall['batch_id']]
        );
        return ['recall' => $recall, 'holding' => $holding, 'received' => $received];
    }

    /** Move all stock of the batch (in locations the user may manage) into quarantine. */
    public function quarantine(int $id, int $userId): int
    {
        $recall = $this->recalls->find($id) ?? throw new BusinessRuleException('Recall not found.');
        $this->gate->authorize('recalls.manage');
        if ($recall['status'] === 'closed') {
            throw new BusinessRuleException('This recall is closed.');
        }
        $moved = 0;
        $byDept = [];
        foreach ($this->trace($id)['holding'] as $h) {
            if (in_array($h['type'], ['quarantine', 'transit'], true) || !$this->gate->can('recalls.manage', (int) $h['department_id'])) {
                continue;
            }
            $byDept[(int) $h['department_id']][] = $h;
        }
        if (!$byDept) {
            throw new BusinessRuleException('No stock of this batch outside quarantine in locations you manage.');
        }
        $this->db->transaction(function () use ($byDept, $recall, $userId, &$moved) {
            foreach ($byDept as $deptId => $rows) {
                $quarantine = $this->locations->quarantineFor($deptId) ?? throw new BusinessRuleException('No quarantine location is configured. Create one in Admin → Storage locations.');
                $lines = [];
                foreach ($rows as $h) {
                    $lines[] = [
                        'product_id' => (int) $recall['product_id'], 'batch_id' => (int) $recall['batch_id'], 'movement_type' => 'QUARANTINED',
                        'from_location_id' => (int) $h['location_id'], 'to_location_id' => $quarantine, 'quantity' => (float) $h['quantity'],
                        'reason' => 'Recall ' . $recall['recall_number'],
                    ];
                    $moved++;
                }
                $this->stock->post([
                    'type' => 'recall_quarantine', 'department_id' => $deptId, 'performed_by' => $userId,
                    'reference_type' => 'batch_recall', 'reference_id' => (int) $recall['id'],
                    'approval_reference' => $recall['recall_number'], 'reason' => 'Batch recall',
                ], $lines);
            }
            $this->db->update('batch_recalls', ['status' => 'in_progress'], 'id = :id', ['id' => $recall['id']]);
        });
        $this->audit->log('quarantine', 'batch_recall', $id, "Quarantined recalled stock from $moved location(s)");
        return $moved;
    }

    public function close(int $id, string $notes, int $userId): void
    {
        $recall = $this->recalls->find($id) ?? throw new BusinessRuleException('Recall not found.');
        $this->gate->authorize('recalls.manage');
        if ($recall['status'] === 'closed') {
            throw new BusinessRuleException('This recall is already closed.');
        }
        $this->db->update('batch_recalls', [
            'status' => 'closed', 'closed_by' => $userId, 'closed_at' => date('Y-m-d H:i:s'), 'closure_notes' => $notes,
        ], 'id = :id', ['id' => $id]);
        $this->audit->log('close', 'batch_recall', $id, "Closed recall {$recall['recall_number']}", null, ['notes' => $notes]);
    }
}
