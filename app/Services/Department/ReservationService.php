<?php
declare(strict_types=1);

namespace App\Services\Department;

use App\Core\Database;
use App\Core\Gate;
use App\Exceptions\BusinessRuleException;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ReservationRepository;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\QuantityConverter;
use App\Services\Support\ApprovalRules;
use App\Services\Support\AuditService;
use App\Services\Support\NotificationService;

/**
 * Emergency stock reservations: approved reservations hold stock back from
 * routine FEFO issuing/consumption until released.
 */
final class ReservationService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private ReservationRepository $reservations,
        private LocationRepository $locations,
        private ProductRepository $products,
        private QuantityConverter $converter,
        private FefoAllocator $fefo,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {
    }

    public function request(array $data, int $userId): int
    {
        $loc = $this->locations->find((int) $data['location_id']) ?? throw new BusinessRuleException('Location not found.');
        $this->gate->authorize('reservations.manage', (int) $loc['department_id']);
        $product = $this->products->find((int) $data['product_id']) ?? throw new BusinessRuleException('Product not found.');
        if ((int) $product['is_critical'] !== 1) {
            throw new BusinessRuleException("{$product['name']} is not marked as a critical item. Only critical items can be reserved.");
        }
        [$qty] = $this->converter->toBase((int) $product['id'], (float) $data['quantity'], $data['unit_id'] ?? null);
        $this->assertAvailable((int) $product['id'], (int) $loc['id'], $data['batch_id'] ?? null, $qty);
        $id = $this->db->insert('stock_reservations', [
            'location_id' => (int) $loc['id'], 'product_id' => (int) $product['id'], 'batch_id' => $data['batch_id'] ?? null,
            'quantity' => $qty, 'purpose' => $data['purpose'], 'status' => 'pending', 'reserved_by' => $userId,
            'valid_until' => $data['valid_until'] ? $data['valid_until'] . ' 23:59:59' : null,
        ]);
        $this->audit->log('create', 'stock_reservation', $id, "Requested reservation of {$product['name']} at {$loc['name']}", null, ['qty' => $qty]);
        $this->notifications->notifyPermission('reservations.approve', [
            'type' => 'reservation_pending', 'severity' => 'warning', 'title' => 'Emergency reservation awaits approval',
            'message' => qty($qty) . " {$product['unit_code']} of {$product['name']} at {$loc['name']}: {$data['purpose']}",
            'link_url' => '/reservations', 'entity_type' => 'stock_reservation', 'entity_id' => $id,
            'dedupe_key' => 'reservation_pending:' . $id,
        ], (int) $loc['department_id']);
        return $id;
    }

    public function approve(int $id, int $userId): void
    {
        $r = $this->db->transaction(function (Database $db) use ($id, $userId) {
            $r = $db->fetchOne('SELECT r.*, l.department_id FROM stock_reservations r JOIN inventory_locations l ON l.id = r.location_id WHERE r.id = :id FOR UPDATE', ['id' => $id])
                ?? throw new BusinessRuleException('Reservation not found.');
            $this->gate->authorize('reservations.approve', (int) $r['department_id']);
            ApprovalRules::assertDifferentUser((int) $r['reserved_by'], $userId, 'reservation');
            if ($r['status'] !== 'pending') {
                throw new BusinessRuleException('Only pending reservations can be approved.');
            }
            $this->assertAvailable((int) $r['product_id'], (int) $r['location_id'], $r['batch_id'] ? (int) $r['batch_id'] : null, (float) $r['quantity']);
            $db->update('stock_reservations', ['status' => 'active', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            return $r;
        });
        cache_bump();
        $this->audit->log('approve', 'stock_reservation', $id, 'Approved emergency reservation');
        $this->notifications->notifyUser((int) $r['reserved_by'], [
            'type' => 'reservation_approved', 'severity' => 'info', 'title' => 'Emergency reservation approved',
            'message' => 'The reserved stock is now held back from routine use.', 'link_url' => '/reservations',
            'entity_type' => 'stock_reservation', 'entity_id' => $id, 'dedupe_key' => 'reservation_approved:' . $id,
        ]);
    }

    public function reject(int $id, int $userId): void
    {
        $r = $this->reservations->find($id) ?? throw new BusinessRuleException('Reservation not found.');
        $this->gate->authorize('reservations.approve', (int) $r['department_id']);
        ApprovalRules::assertDifferentUser((int) $r['reserved_by'], $userId, 'reservation');
        if ($r['status'] !== 'pending') {
            throw new BusinessRuleException('Only pending reservations can be rejected.');
        }
        $this->db->update('stock_reservations', ['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        cache_bump();
        $this->audit->log('reject', 'stock_reservation', $id, 'Rejected emergency reservation');
    }

    /** Release the reservation so the stock returns to normal use. */
    public function release(int $id, int $userId): void
    {
        $r = $this->reservations->find($id) ?? throw new BusinessRuleException('Reservation not found.');
        if (!$this->gate->can('reservations.manage', (int) $r['department_id'])) {
            $this->gate->authorize('reservations.approve', (int) $r['department_id']);
        }
        if (!in_array($r['status'], ['pending', 'active'], true)) {
            throw new BusinessRuleException('This reservation is not active.');
        }
        $this->db->update('stock_reservations', ['status' => 'released', 'released_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        cache_bump();
        $this->audit->log('release', 'stock_reservation', $id, 'Released emergency reservation');
    }

    private function assertAvailable(int $productId, int $locationId, ?int $batchId, float $qty): void
    {
        $candidates = $this->fefo->candidates($productId, $locationId);
        if ($batchId !== null) {
            $candidates = array_filter($candidates, fn ($c) => $c['batch_id'] === $batchId);
        }
        $available = array_sum(array_column($candidates, 'available'));
        if ($available + 1e-9 < $qty) {
            throw new BusinessRuleException('Only ' . qty($available) . ' unreserved, usable unit(s) are available to reserve.');
        }
    }
}
