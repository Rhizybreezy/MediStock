<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Services\Catalogue\ProductService;
use App\Services\Department\AdjustmentService;
use App\Services\Department\ConsumptionService;
use App\Services\Department\RecallService;
use App\Services\Department\ReservationService;
use App\Services\Department\StockCountService;
use App\Services\Department\StockRequestService;
use App\Services\Department\TransferService;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\IssueService;
use App\Services\Stock\ReceivingService;
use App\Services\Stock\ReconciliationService;

/**
 * Phase 3 workflows: requests, transfers/returns, consumption, adjustments,
 * counts, reservations and recalls, including segregation of duties.
 */
final class DepartmentWorkflowTest extends DatabaseTestCase
{
    private int $inv;
    private int $mgr;
    private int $mat;
    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = fn ($u) => (int) $this->db->fetchValue('SELECT id FROM users WHERE username = :u', ['u' => $u]);
        $this->inv = $uid('inventory');
        $this->mgr = $uid('manager');
        $this->mat = $uid('maternity');
        $this->actingAs('inventory');
        $this->product = $this->makeProduct(['is_critical' => 1]);
        $this->receive($this->product, 'LOT-1', 500, '+60 days');
        $this->receive($this->product, 'LOT-2', 500, '+300 days');
    }

    private function loc(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM inventory_locations WHERE code = :c', ['c' => $code]);
    }

    private function makeProduct(array $o = []): int
    {
        $cat = (int) $this->db->fetchValue("SELECT id FROM inventory_categories WHERE code = 'PPE'");
        $pcs = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'PCS'");
        return app(ProductService::class)->create(array_merge([
            'name' => 'Test gloves ' . bin2hex(random_bytes(3)), 'generic_name' => null, 'category_id' => $cat, 'base_unit_id' => $pcs,
            'dosage_form' => null, 'strength' => null, 'manufacturer' => null, 'description' => null, 'storage_condition' => 'room_temp',
            'storage_notes' => null, 'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_critical' => 0, 'is_controlled' => 0,
            'min_level' => 0, 'reorder_level' => 0, 'max_level' => 0, 'default_lead_time_days' => null, 'is_active' => 1,
        ], $o), [], null, $this->inv);
    }

    private function receive(int $p, string $lot, float $qty, string $expiry, string $loc = 'CS-MAIN'): void
    {
        app(ReceivingService::class)->receive(
            ['receipt_type' => 'direct', 'receiving_location_id' => $this->loc($loc), 'submission_token' => bin2hex(random_bytes(16))],
            [['product_id' => $p, 'lot_number' => $lot, 'quantity' => $qty, 'unit_id' => null, 'expiry_date' => date('Y-m-d', strtotime($expiry)), 'manufacture_date' => null, 'unit_cost' => 0.1]],
            $this->inv
        );
    }

    private function balance(string $loc): float
    {
        return (float) $this->db->fetchValue('SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE product_id = :p AND location_id = :l', ['p' => $this->product, 'l' => $this->loc($loc)]);
    }

    private function confirmAll(int $transferId, int $userId): void
    {
        $received = [];
        foreach ($this->db->fetchAll('SELECT id, dispatched_qty - received_qty - returned_qty AS q FROM stock_transfer_items WHERE stock_transfer_id = :t AND dispatched_qty > 0', ['t' => $transferId]) as $r) {
            $received[(int) $r['id']] = (float) $r['q'];
        }
        app(IssueService::class)->confirmReceipt($transferId, $received, null, $userId);
    }

    public function testRequestApprovalPartialIssueAndReceipt(): void
    {
        $this->actingAs('maternity');
        $svc = app(StockRequestService::class);
        $id = $svc->create(['destination_location_id' => $this->loc('MAT-MAIN'), 'priority' => 'urgent'], [['product_id' => $this->product, 'quantity' => 300]], true, $this->mat);
        $item = (int) $this->db->fetchValue('SELECT id FROM stock_request_items WHERE stock_request_id = :r', ['r' => $id]);

        // Requester cannot approve; inventory manager approves 200 of 300.
        $this->actingAs('inventory');
        $svc->review($id, 'approve', [$item => 200], 'Partial due to stock', $this->inv);
        $this->assertSame('partially_approved', $this->db->fetchValue('SELECT status FROM stock_requests WHERE id = :r', ['r' => $id]));

        // Issue 150 now, 50 later.
        $t1 = $svc->issue($id, $this->loc('CS-MAIN'), [$item => 150], $this->inv);
        $this->assertSame('partially_issued', $this->db->fetchValue('SELECT status FROM stock_requests WHERE id = :r', ['r' => $id]));
        try {
            $svc->issue($id, $this->loc('CS-MAIN'), [$item => 51], $this->inv);
            $this->fail('Over-issue must be refused');
        } catch (BusinessRuleException) {
        }
        $t2 = $svc->issue($id, $this->loc('CS-MAIN'), [$item => 50], $this->inv);
        $this->assertSame('issued', $this->db->fetchValue('SELECT status FROM stock_requests WHERE id = :r', ['r' => $id]));

        $this->actingAs('maternity');
        $this->confirmAll($t1, $this->mat);
        $this->assertSame('issued', $this->db->fetchValue('SELECT status FROM stock_requests WHERE id = :r', ['r' => $id]));
        $this->confirmAll($t2, $this->mat);
        $this->assertSame('received', $this->db->fetchValue('SELECT status FROM stock_requests WHERE id = :r', ['r' => $id]));
        $this->assertSame(200.0, (float) $this->db->fetchValue('SELECT received_qty FROM stock_request_items WHERE id = :i', ['i' => $item]));
        $this->assertSame(200.0, $this->balance('MAT-MAIN'));
    }

    public function testRequesterCannotReviewOwnRequest(): void
    {
        $id = app(StockRequestService::class)->create(['destination_location_id' => $this->loc('ED-MAIN')], [['product_id' => $this->product, 'quantity' => 10]], true, $this->inv);
        $this->expectException(BusinessRuleException::class);
        app(StockRequestService::class)->review($id, 'approve', [], null, $this->inv);
    }

    public function testStorekeeperCannotRequestForAnotherDepartment(): void
    {
        $this->actingAs('maternity');
        $this->expectException(AuthorizationException::class);
        app(StockRequestService::class)->create(['destination_location_id' => $this->loc('ED-MAIN')], [['product_id' => $this->product, 'quantity' => 10]], true, $this->mat);
    }

    public function testReturnToStoreNeedsApprovalThenDispatchAndReceipt(): void
    {
        // Give maternity stock first.
        $tid = app(IssueService::class)->dispatch(['source_location_id' => $this->loc('CS-MAIN'), 'destination_location_id' => $this->loc('MAT-MAIN')], [['product_id' => $this->product, 'quantity' => 100]], $this->inv);
        $this->actingAs('maternity');
        $this->confirmAll($tid, $this->mat);

        $svc = app(TransferService::class);
        $rid = $svc->create('return_to_store', ['source_location_id' => $this->loc('MAT-MAIN'), 'destination_location_id' => $this->loc('CS-MAIN'), 'reason' => 'Overstock'], [['product_id' => $this->product, 'quantity' => 40]], $this->mat);
        try {
            $svc->dispatch($rid, $this->mat);
            $this->fail('Unapproved transfer must not dispatch');
        } catch (BusinessRuleException) {
        }
        $this->actingAs('manager');
        $line = (int) $this->db->fetchValue('SELECT id FROM stock_transfer_items WHERE stock_transfer_id = :t', ['t' => $rid]);
        $svc->approve($rid, [$line => 30], $this->mgr);

        $this->actingAs('maternity');
        $svc->dispatch($rid, $this->mat);
        $this->assertSame(70.0, $this->balance('MAT-MAIN'));
        $this->actingAs('inventory');
        $this->confirmAll($rid, $this->inv);
        $this->assertSame('received', $this->db->fetchValue('SELECT status FROM stock_transfers WHERE id = :t', ['t' => $rid]));
        $this->assertSame(930.0, $this->balance('CS-MAIN'));
    }

    public function testConsumptionUsesFefo(): void
    {
        app(ConsumptionService::class)->record(['location_id' => $this->loc('CS-MAIN'), 'purpose' => 'patient_care'], [['product_id' => $this->product, 'quantity' => 520]], $this->inv);
        $lots = $this->db->fetchAll("SELECT b.lot_number, m.quantity FROM stock_movements m JOIN product_batches b ON b.id = m.batch_id WHERE m.product_id = :p AND m.movement_type = 'CONSUMED' ORDER BY b.expiry_date", ['p' => $this->product]);
        $this->assertSame(['LOT-1', 'LOT-2'], array_column($lots, 'lot_number'));
        $this->assertSame([500.0, 20.0], array_map('floatval', array_column($lots, 'quantity')));
    }

    public function testAdjustmentRequiresDifferentApprover(): void
    {
        $batch = (int) $this->db->fetchValue("SELECT id FROM product_batches WHERE product_id = :p AND lot_number = 'LOT-1'", ['p' => $this->product]);
        $svc = app(AdjustmentService::class);
        $id = $svc->request($this->loc('CS-MAIN'), 'damaged', [['batch_id' => $batch, 'quantity_change' => -25]], 'Water damage', $this->inv);
        $this->assertSame(1000.0, $this->balance('CS-MAIN'), 'Stock must not change before approval');
        try {
            $svc->approve($id, $this->inv);
            $this->fail('Self-approval must be refused');
        } catch (BusinessRuleException) {
        }
        $this->actingAs('manager');
        $svc->approve($id, $this->mgr);
        $this->assertSame(975.0, $this->balance('CS-MAIN'));
        $this->assertSame('DAMAGED', $this->db->fetchValue('SELECT movement_type FROM stock_movements WHERE product_id = :p ORDER BY id DESC LIMIT 1', ['p' => $this->product]));
    }

    public function testExpiredDisposalOnlyForExpiredBatches(): void
    {
        $batch = (int) $this->db->fetchValue("SELECT id FROM product_batches WHERE product_id = :p AND lot_number = 'LOT-1'", ['p' => $this->product]);
        $this->expectException(BusinessRuleException::class);
        app(AdjustmentService::class)->request($this->loc('CS-MAIN'), 'expired', [['batch_id' => $batch, 'quantity_change' => -10]], null, $this->inv);
    }

    public function testStockCountPostsVarianceOnApproval(): void
    {
        $svc = app(StockCountService::class);
        $cid = $svc->start($this->loc('CS-MAIN'), 'cycle', null, $this->inv);
        $items = $this->db->fetchAll('SELECT i.id, b.lot_number FROM stock_count_items i JOIN product_batches b ON b.id = i.batch_id WHERE i.stock_count_id = :c AND b.product_id = :p', ['c' => $cid, 'p' => $this->product]);
        $counted = [];
        foreach ($this->db->fetchAll('SELECT i.id, i.system_qty FROM stock_count_items i WHERE i.stock_count_id = :c', ['c' => $cid]) as $row) {
            $counted[(int) $row['id']] = (float) $row['system_qty'];
        }
        foreach ($items as $it) {
            $counted[(int) $it['id']] = $it['lot_number'] === 'LOT-1' ? 490.0 : 503.0;
        }
        $svc->saveCounts($cid, $counted, $this->inv, true);
        try {
            $svc->approve($cid, $this->inv);
            $this->fail('Counter must not approve own count');
        } catch (BusinessRuleException) {
        }
        $this->actingAs('manager');
        // manager lacks counts.approve by default
        try {
            $svc->approve($cid, $this->mgr);
            $this->fail('Manager without counts.approve must be refused');
        } catch (AuthorizationException) {
        }
        // Give the approval to a second inventory manager account.
        $second = $this->db->insert('users', ['full_name' => 'Second IM', 'username' => 'im2_' . bin2hex(random_bytes(3)), 'email' => bin2hex(random_bytes(4)) . '@t.local', 'password_hash' => 'x', 'status' => 'active']);
        $this->db->insert('user_roles', ['user_id' => $second, 'role_id' => (int) $this->db->fetchValue("SELECT id FROM roles WHERE code = 'inventory_manager'")]);
        $_SESSION['user_id'] = $second;
        app(\App\Core\Gate::class)->flush();
        $adjId = $svc->approve($cid, $second);
        $this->assertNotNull($adjId);
        $this->assertSame(993.0, $this->balance('CS-MAIN'));
        $this->assertSame([], app(ReconciliationService::class)->run()['mismatches']);
    }

    public function testReservationHoldsStockBack(): void
    {
        $svc = app(ReservationService::class);
        $rid = $svc->request(['location_id' => $this->loc('CS-MAIN'), 'product_id' => $this->product, 'quantity' => 900, 'purpose' => 'Mass casualty reserve', 'valid_until' => null], $this->inv);
        $this->actingAs('manager');
        $svc->approve($rid, $this->mgr);
        $this->actingAs('inventory');
        $this->assertSame(100.0, array_sum(array_column(app(FefoAllocator::class)->candidates($this->product, $this->loc('CS-MAIN')), 'available')));
        try {
            app(ConsumptionService::class)->record(['location_id' => $this->loc('CS-MAIN'), 'purpose' => 'patient_care'], [['product_id' => $this->product, 'quantity' => 150]], $this->inv);
            $this->fail('Reserved stock must not be consumed');
        } catch (InsufficientStockException) {
        }
        $svc->release($rid, $this->inv);
        $this->assertSame(1000.0, array_sum(array_column(app(FefoAllocator::class)->candidates($this->product, $this->loc('CS-MAIN')), 'available')));
    }

    public function testRecallBlocksBatchAndQuarantines(): void
    {
        $batch = (int) $this->db->fetchValue("SELECT id FROM product_batches WHERE product_id = :p AND lot_number = 'LOT-1'", ['p' => $this->product]);
        $tid = app(IssueService::class)->dispatch(['source_location_id' => $this->loc('CS-MAIN'), 'destination_location_id' => $this->loc('ED-MAIN')], [['product_id' => $this->product, 'quantity' => 100]], $this->inv);
        $this->db->execute("UPDATE users SET status = 'active' WHERE id = :u", ['u' => $this->inv]);
        $svc = app(RecallService::class);
        $rcl = $svc->create(['batch_id' => $batch, 'reason' => 'Manufacturer defect', 'severity' => 'critical', 'source' => 'manufacturer'], $this->inv);
        $this->assertSame('recalled', $this->db->fetchValue('SELECT status FROM product_batches WHERE id = :b', ['b' => $batch]));
        // FEFO now skips LOT-1 entirely
        $alloc = app(FefoAllocator::class)->allocate($this->product, $this->loc('CS-MAIN'), 10, null, false);
        $this->assertSame('LOT-2', $alloc[0]['lot_number']);

        $moved = $svc->quarantine($rcl, $this->inv);
        $this->assertGreaterThanOrEqual(1, $moved);
        $this->assertSame(0.0, (float) $this->db->fetchValue("SELECT COALESCE(SUM(sb.quantity),0) FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id WHERE sb.batch_id = :b AND l.type NOT IN ('quarantine','transit')", ['b' => $batch]));
        $trace = $svc->trace($rcl);
        $this->assertNotEmpty($trace['received']);
        $this->assertSame([], app(ReconciliationService::class)->run()['mismatches']);
    }
}
