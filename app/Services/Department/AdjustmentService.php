<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\AdjustmentRepository;
use App\Repositories\LocationRepository;
use App\Services\Stock\StockService;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\NotificationService;
use App\Services\Support\SettingsService;

/**
 * Stock adjustments (damage, loss, expiry disposal, write-off, corrections).
 * Always two people: one requests, a different authorised person approves.
 * Stock only changes when the adjustment is approved (posted).
 */
final class AdjustmentService
{
    public const REASONS = [
        'damaged'         => ['Damaged', 'out'],
        'expired'         => ['Expired stock disposal', 'out'],
        'lost'            => ['Lost / missing', 'out'],
        'theft_suspected' => ['Suspected theft', 'out'],
        'write_off'       => ['Write-off (other)', 'out'],
        'found'           => ['Found stock', 'in'],
        'data_correction' => ['Data correction', 'both'],
        'count_variance'  => ['Stock count variance', 'both'],
    ];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private StockService $stock,
        private AdjustmentRepository $adjustments,
        private LocationRepository $locations,
        private DocumentNumberService $numbers,
        private NotificationService $notifications,
        private SettingsService $settings,
        private AuditService $audit,
    ) {
    }

    /** @param array $lines [['batch_id', 'quantity_change' (signed, base units), 'reason'?], ...] */
    public function request(int $locationId, string $reasonCode, array $lines, ?string $notes, int $userId): int
    {
        $loc = $this->locations->find($locationId) ?? throw new BusinessRuleException('Location not found.');
        $this->gate->authorize('adjustments.request', (int) $loc['department_id']);
        if ($loc['type'] === 'transit') {
            throw new BusinessRuleException('Stock in transit is resolved from the delivery, not by adjustment.');
        }
        if (!isset(self::REASONS[$reasonCode]) || $reasonCode === 'count_variance') {
            throw new BusinessRuleException('Choose a valid adjustment reason.');
        }
        if (in_array($reasonCode, ['lost', 'data_correction'], true) && !$this->settings->get('allow_negative_adjustment_without_count', true)) {
            throw new BusinessRuleException('Hospital policy requires losses and corrections to be recorded through a stock count.');
        }
        if (!$lines) {
            throw new BusinessRuleException('Add at least one batch.');
        }
        $direction = self::REASONS[$reasonCode][1];
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $change = round((float) $line['quantity_change'], 3);
            $batch = $this->db->fetchOne(
                'SELECT b.*, p.name AS product_name, u.allows_decimal FROM product_batches b
                   JOIN products p ON p.id = b.product_id
                   JOIN units_of_measure u ON u.id = p.base_unit_id
                  WHERE b.id = :b',
                ['b' => (int) $line['batch_id']]
            ) ?? throw new BusinessRuleException("Line $n: batch not found.");
            if (isset($seen[(int) $batch['id']])) {
                throw new BusinessRuleException("Line $n: batch {$batch['batch_code']} is listed twice.");
            }
            $seen[(int) $batch['id']] = true;
            if ($change == 0.0) {
                throw new BusinessRuleException("Line $n: quantity cannot be zero.");
            }
            if ((int) $batch['allows_decimal'] === 0 && abs($change - round($change)) > 1e-9) {
                throw new BusinessRuleException("Line $n: {$batch['product_name']} is counted in whole units.");
            }
            if (($direction === 'out' && $change > 0) || ($direction === 'in' && $change < 0)) {
                throw new BusinessRuleException("Line $n: " . self::REASONS[$reasonCode][0] . ($direction === 'out' ? ' reduces stock (use a negative quantity).' : ' increases stock (use a positive quantity).'));
            }
            if ($reasonCode === 'expired' && (!$batch['expiry_date'] || $batch['expiry_date'] >= date('Y-m-d'))) {
                throw new BusinessRuleException("Line $n: batch {$batch['batch_code']} is not expired.");
            }
            if ($change < 0) {
                $have = (float) $this->db->fetchValue('SELECT COALESCE(quantity, 0) FROM stock_balances WHERE location_id = :l AND batch_id = :b', ['l' => $locationId, 'b' => $batch['id']]);
                if ($have + 1e-9 < abs($change)) {
                    throw new BusinessRuleException("Line $n: only " . qty($have) . " of batch {$batch['batch_code']} at {$loc['name']}.");
                }
            }
        }

        $id = $this->db->transaction(function (Database $db) use ($locationId, $reasonCode, $lines, $notes, $userId) {
            $id = $db->insert('stock_adjustments', [
                'adjustment_number' => $this->numbers->next('ADJ'),
                'location_id' => $locationId, 'reason_code' => $reasonCode, 'status' => 'pending',
                'notes' => $notes, 'requested_by' => $userId,
            ]);
            foreach ($lines as $line) {
                $db->insert('stock_adjustment_items', [
                    'stock_adjustment_id' => $id, 'batch_id' => (int) $line['batch_id'],
                    'quantity_change' => round((float) $line['quantity_change'], 3),
                    'reason' => isset($line['reason']) ? mb_substr((string) $line['reason'], 0, 255) : null,
                ]);
            }
            return $id;
        });
        $adj = $this->adjustments->find($id);
        $this->audit->log('create', 'stock_adjustment', $id, "Requested {$adj['adjustment_number']} (" . self::REASONS[$reasonCode][0] . ')', null, ['lines' => count($lines)]);
        $this->notifications->notifyPermission('adjustments.approve', [
            'type' => 'adjustment_pending', 'severity' => 'warning',
            'title' => "Adjustment {$adj['adjustment_number']} awaits approval",
            'message' => self::REASONS[$reasonCode][0] . " at {$adj['location_name']} requested by {$adj['requested_by_name']}.",
            'link_url' => '/adjustments/' . $id, 'entity_type' => 'stock_adjustment', 'entity_id' => $id,
            'dedupe_key' => 'adjustment_pending:' . $id,
        ], (int) $loc['department_id']);
        return $id;
    }

    public function approve(int $id, int $userId): void
    {
        $adj = $this->db->transaction(function (Database $db) use ($id, $userId) {
            $adj = $db->fetchOne('SELECT a.*, l.department_id FROM stock_adjustments a JOIN inventory_locations l ON l.id = a.location_id WHERE a.id = :id FOR UPDATE', ['id' => $id])
                ?? throw new BusinessRuleException('Adjustment not found.');
            $this->gate->authorize('adjustments.approve', (int) $adj['department_id']);
            ApprovalRules::assertDifferentUser((int) $adj['requested_by'], $userId, 'adjustment');
            if ($adj['status'] !== 'pending') {
                throw new BusinessRuleException('Only pending adjustments can be approved.');
            }
            $this->post($adj, $userId);
            return $adj;
        });
        $this->audit->log('approve', 'stock_adjustment', $id, "Approved and posted {$adj['adjustment_number']}");
        $this->notifications->notifyUser((int) $adj['requested_by'], [
            'type' => 'adjustment_approved', 'severity' => 'info', 'title' => "Adjustment {$adj['adjustment_number']} approved",
            'message' => 'The stock adjustment was approved and posted.', 'link_url' => '/adjustments/' . $id,
            'entity_type' => 'stock_adjustment', 'entity_id' => $id, 'dedupe_key' => 'adjustment_approved:' . $id,
        ]);
    }

    public function reject(int $id, string $reason, int $userId): void
    {
        $adj = $this->adjustments->find($id) ?? throw new BusinessRuleException('Adjustment not found.');
        $this->gate->authorize('adjustments.approve', (int) $adj['department_id']);
        ApprovalRules::assertDifferentUser((int) $adj['requested_by'], $userId, 'adjustment');
        if ($adj['status'] !== 'pending') {
            throw new BusinessRuleException('Only pending adjustments can be rejected.');
        }
        $this->db->update('stock_adjustments', ['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'), 'rejection_reason' => $reason], 'id = :id', ['id' => $id]);
        $this->audit->log('reject', 'stock_adjustment', $id, "Rejected {$adj['adjustment_number']}", null, ['reason' => $reason]);
        $this->notifications->notifyUser((int) $adj['requested_by'], [
            'type' => 'adjustment_rejected', 'severity' => 'warning', 'title' => "Adjustment {$adj['adjustment_number']} rejected",
            'message' => "Reason: $reason", 'link_url' => '/adjustments/' . $id,
            'entity_type' => 'stock_adjustment', 'entity_id' => $id, 'dedupe_key' => 'adjustment_rejected:' . $id,
        ]);
    }

    /**
     * Writes the movements for an adjustment (inside the caller's transaction).
     * Also used by stock-count approval for variance adjustments.
     */
    public function post(array $adj, int $approverId): void
    {
        $movements = [];
        foreach ($this->db->fetchAll('SELECT i.*, b.product_id FROM stock_adjustment_items i JOIN product_batches b ON b.id = i.batch_id WHERE i.stock_adjustment_id = :id', ['id' => $adj['id']]) as $item) {
            $change = (float) $item['quantity_change'];
            $type = match ($adj['reason_code']) {
                'damaged' => 'DAMAGED',
                'expired' => 'EXPIRED',
                'lost', 'theft_suspected', 'write_off' => 'WRITTEN_OFF',
                default => $change > 0 ? 'ADJUSTED_IN' : 'ADJUSTED_OUT',
            };
            $movements[] = [
                'product_id' => (int) $item['product_id'], 'batch_id' => (int) $item['batch_id'], 'movement_type' => $type,
                'from_location_id' => $change < 0 ? (int) $adj['location_id'] : null,
                'to_location_id'   => $change > 0 ? (int) $adj['location_id'] : null,
                'quantity' => abs($change), 'reason' => $item['reason'] ?? self::REASONS[$adj['reason_code']][0],
            ];
        }
        $deptId = (int) $this->db->fetchValue('SELECT department_id FROM inventory_locations WHERE id = :l', ['l' => $adj['location_id']]);
        $this->stock->post([
            'type' => match ($adj['reason_code']) {
                'expired' => 'expiry_disposal', 'count_variance' => 'count_correction',
                'lost', 'theft_suspected', 'write_off', 'damaged' => 'write_off', default => 'adjustment',
            },
            'department_id' => $deptId, 'performed_by' => (int) $adj['requested_by'], 'approved_by' => $approverId,
            'approval_reference' => $adj['adjustment_number'], 'reference_type' => 'stock_adjustment', 'reference_id' => (int) $adj['id'],
            'reason' => self::REASONS[$adj['reason_code']][0], 'notes' => $adj['notes'],
        ], $movements);
        $this->db->update('stock_adjustments', ['status' => 'posted', 'approved_by' => $approverId, 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $adj['id']]);
    }
}
