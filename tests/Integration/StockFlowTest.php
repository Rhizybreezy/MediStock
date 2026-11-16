<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Services\Catalogue\ProductService;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\IssueService;
use App\Services\Stock\ReceivingService;
use App\Services\Stock\ReconciliationService;
use App\Services\Stock\StockService;

/**
 * End-to-end stock engine tests: receiving, FEFO issuing, transit receipt,
 * shortfall return, safeguards and ledger/balance reconciliation.
 */
final class StockFlowTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->actingAs('inventory');
    }

    private function loc(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM inventory_locations WHERE code = :c', ['c' => $code]);
    }

    private function product(array $overrides = []): int
    {
        $cat = (int) $this->db->fetchValue("SELECT id FROM inventory_categories WHERE code = 'MED'");
        $tab = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'TAB'");
        $box = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'BOX'");
        return app(ProductService::class)->create(array_merge([
            'name' => 'Test Paracetamol 500mg ' . bin2hex(random_bytes(3)), 'generic_name' => 'Paracetamol',
            'category_id' => $cat, 'base_unit_id' => $tab, 'dosage_form' => 'Tablet', 'strength' => '500 mg',
            'manufacturer' => null, 'description' => null, 'storage_condition' => 'room_temp', 'storage_notes' => null,
            'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_critical' => 0, 'is_controlled' => 0,
            'min_level' => 100, 'reorder_level' => 200, 'max_level' => 2000, 'default_lead_time_days' => 14, 'is_active' => 1,
        ], $overrides), [['unit_id' => $box, 'factor_to_base' => 100, 'gtin_barcode' => null]], null, $this->userId);
    }

    private function receive(int $productId, array $lots, string $location = 'CS-MAIN'): int
    {
        $lines = [];
        foreach ($lots as [$lot, $qty, $expiry, $cost]) {
            $lines[] = ['product_id' => $productId, 'lot_number' => $lot, 'quantity' => $qty, 'unit_id' => null,
                        'expiry_date' => $expiry, 'manufacture_date' => null, 'unit_cost' => $cost];
        }
        [$id] = app(ReceivingService::class)->receive([
            'receipt_type' => 'direct', 'receiving_location_id' => $this->loc($location),
            'submission_token' => bin2hex(random_bytes(16)),
        ], $lines, $this->userId);
        return $id;
    }

    private function balance(int $productId, string $location): float
    {
        return (float) $this->db->fetchValue(
            'SELECT COALESCE(SUM(quantity), 0) FROM stock_balances WHERE product_id = :p AND location_id = :l',
            ['p' => $productId, 'l' => $this->loc($location)]
        );
    }

    public function testReceiptCreatesBatchesMovementsAndBalances(): void
    {
        $p = $this->product();
        $this->receive($p, [['LOT-A', 300, date('Y-m-d', strtotime('+200 days')), 0.05]]);
        $this->assertSame(300.0, $this->balance($p, 'CS-MAIN'));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM product_batches WHERE product_id = :p', ['p' => $p]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM stock_movements WHERE product_id = :p AND movement_type = 'RECEIVED'", ['p' => $p]));
    }

    public function testPackUnitsAreConvertedToBaseUnitsAndCostPerBaseUnit(): void
    {
        $p = $this->product();
        $box = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'BOX'");
        app(ReceivingService::class)->receive([
            'receipt_type' => 'direct', 'receiving_location_id' => $this->loc('CS-MAIN'), 'submission_token' => bin2hex(random_bytes(16)),
        ], [['product_id' => $p, 'lot_number' => 'BX1', 'quantity' => 3, 'unit_id' => $box,
             'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'manufacture_date' => null, 'unit_cost' => 5.00]], $this->userId);
        $this->assertSame(300.0, $this->balance($p, 'CS-MAIN'));
        $this->assertEqualsWithDelta(0.05, (float) $this->db->fetchValue('SELECT unit_cost FROM product_batches WHERE product_id = :p', ['p' => $p]), 0.0001);
    }

    public function testDuplicateSubmissionTokenDoesNotReceiveTwice(): void
    {
        $p = $this->product();
        $header = ['receipt_type' => 'direct', 'receiving_location_id' => $this->loc('CS-MAIN'), 'submission_token' => bin2hex(random_bytes(16))];
        $line = [['product_id' => $p, 'lot_number' => 'L1', 'quantity' => 50, 'unit_id' => null, 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'manufacture_date' => null, 'unit_cost' => 1]];
        [$first, $created1] = app(ReceivingService::class)->receive($header, $line, $this->userId);
        [$second, $created2] = app(ReceivingService::class)->receive($header, $line, $this->userId);
        $this->assertTrue($created1);
        $this->assertFalse($created2);
        $this->assertSame($first, $second);
        $this->assertSame(50.0, $this->balance($p, 'CS-MAIN'));
    }

    public function testExpiredStockCannotBeReceived(): void
    {
        $p = $this->product();
        $this->expectException(BusinessRuleException::class);
        $this->receive($p, [['OLD', 10, date('Y-m-d', strtotime('-1 day')), 1]]);
    }

    public function testColdChainProductNeedsColdLocation(): void
    {
        $p = $this->product(['storage_condition' => 'refrigerated']);
        try {
            $this->receive($p, [['V1', 10, date('Y-m-d', strtotime('+90 days')), 1]], 'CS-MAIN');
            $this->fail('Expected cold-chain rule to block the receipt');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('cold', $e->getMessage());
        }
        $this->receive($p, [['V1', 10, date('Y-m-d', strtotime('+90 days')), 1]], 'CS-COLD');
        $this->assertSame(10.0, $this->balance($p, 'CS-COLD'));
    }

    public function testFefoIssuesEarliestExpiryFirstAndSkipsExpired(): void
    {
        $p = $this->product();
        $this->receive($p, [
            ['LATE', 100, date('Y-m-d', strtotime('+300 days')), 1],
            ['EARLY', 100, date('Y-m-d', strtotime('+30 days')), 1],
            ['MID', 100, date('Y-m-d', strtotime('+120 days')), 1],
        ]);
        // Simulate a batch that has since expired: it must never be allocated.
        $this->db->execute("UPDATE product_batches SET expiry_date = CURDATE() - INTERVAL 1 DAY WHERE product_id = :p AND lot_number = 'MID'", ['p' => $p]);

        $alloc = app(FefoAllocator::class)->allocate($p, $this->loc('CS-MAIN'), 150, null, false);
        $this->assertSame(['EARLY', 'LATE'], array_column($alloc, 'lot_number'));
        $this->assertSame([100.0, 50.0], array_column($alloc, 'quantity'));

        $this->expectException(InsufficientStockException::class);
        app(FefoAllocator::class)->allocate($p, $this->loc('CS-MAIN'), 250, null, false);
    }

    public function testReservedStockIsNotAllocated(): void
    {
        $p = $this->product();
        $this->receive($p, [['R1', 100, date('Y-m-d', strtotime('+100 days')), 1]]);
        $this->db->insert('stock_reservations', [
            'location_id' => $this->loc('CS-MAIN'), 'product_id' => $p, 'quantity' => 40, 'purpose' => 'Emergency reserve',
            'status' => 'active', 'reserved_by' => $this->userId,
        ]);
        $candidates = app(FefoAllocator::class)->candidates($p, $this->loc('CS-MAIN'));
        $this->assertSame(60.0, $candidates[0]['available']);
        $this->expectException(InsufficientStockException::class);
        app(FefoAllocator::class)->allocate($p, $this->loc('CS-MAIN'), 61, null, false);
    }

    public function testIssueGoesThroughTransitAndPartialReceiptCanBeReturned(): void
    {
        $p = $this->product();
        $this->receive($p, [['A', 80, date('Y-m-d', strtotime('+60 days')), 1], ['B', 100, date('Y-m-d', strtotime('+200 days')), 1]]);
        $issue = app(IssueService::class);
        $tid = $issue->dispatch(['source_location_id' => $this->loc('CS-MAIN'), 'destination_location_id' => $this->loc('MAT-MAIN')],
            [['product_id' => $p, 'quantity' => 120, 'unit_id' => null]], $this->userId);

        $this->assertSame(60.0, $this->balance($p, 'CS-MAIN'));
        $this->assertSame(120.0, $this->balance($p, 'TRANSIT'));
        $this->assertSame(0.0, $this->balance($p, 'MAT-MAIN'));

        $items = $this->db->fetchAll('SELECT i.id, b.lot_number, i.dispatched_qty FROM stock_transfer_items i JOIN product_batches b ON b.id = i.batch_id WHERE stock_transfer_id = :t ORDER BY b.expiry_date', ['t' => $tid]);
        $this->assertSame(['A', 'B'], array_column($items, 'lot_number'));

        // Maternity confirms only 110 of 120.
        $this->actingAs('maternity');
        $issue->confirmReceipt($tid, [(int) $items[0]['id'] => 80, (int) $items[1]['id'] => 30], 'Box damaged in transit', (int) $this->db->fetchValue("SELECT id FROM users WHERE username='maternity'"));
        $this->assertSame(110.0, $this->balance($p, 'MAT-MAIN'));
        $this->assertSame(10.0, $this->balance($p, 'TRANSIT'));
        $this->assertSame('partially_received', $this->db->fetchValue('SELECT status FROM stock_transfers WHERE id = :t', ['t' => $tid]));

        // Issuer returns the outstanding 10 to the central store.
        $this->actingAs('inventory');
        $issue->returnOutstanding($tid, 'Not delivered', $this->userId);
        $this->assertSame(70.0, $this->balance($p, 'CS-MAIN'));
        $this->assertSame(0.0, $this->balance($p, 'TRANSIT'));
        $this->assertSame('received', $this->db->fetchValue('SELECT status FROM stock_transfers WHERE id = :t', ['t' => $tid]));

        $this->assertSame([], app(ReconciliationService::class)->run()['mismatches']);
    }

    public function testDepartmentUserCannotConfirmAnotherDepartmentsDelivery(): void
    {
        $p = $this->product();
        $this->receive($p, [['A', 50, date('Y-m-d', strtotime('+60 days')), 1]]);
        $tid = app(IssueService::class)->dispatch(['source_location_id' => $this->loc('CS-MAIN'), 'destination_location_id' => $this->loc('ED-MAIN')],
            [['product_id' => $p, 'quantity' => 20]], $this->userId);
        $this->actingAs('maternity');
        $this->expectException(AuthorizationException::class);
        app(IssueService::class)->confirmReceipt($tid, [], null, 1);
    }

    public function testStockServiceRefusesToOverdraw(): void
    {
        $p = $this->product();
        $this->receive($p, [['A', 10, date('Y-m-d', strtotime('+60 days')), 1]]);
        $batch = (int) $this->db->fetchValue('SELECT id FROM product_batches WHERE product_id = :p', ['p' => $p]);
        $dept = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'CS'");
        $this->expectException(InsufficientStockException::class);
        app(StockService::class)->post(['type' => 'consumption', 'department_id' => $dept, 'performed_by' => $this->userId], [
            ['product_id' => $p, 'batch_id' => $batch, 'movement_type' => 'CONSUMED', 'from_location_id' => $this->loc('CS-MAIN'), 'to_location_id' => null, 'quantity' => 6],
            ['product_id' => $p, 'batch_id' => $batch, 'movement_type' => 'CONSUMED', 'from_location_id' => $this->loc('CS-MAIN'), 'to_location_id' => null, 'quantity' => 6],
        ]);
    }

    public function testReconciliationDetectsTampering(): void
    {
        $p = $this->product();
        $this->receive($p, [['A', 10, date('Y-m-d', strtotime('+60 days')), 1]]);
        $this->db->execute('UPDATE stock_balances SET quantity = 15 WHERE product_id = :p', ['p' => $p]);
        $mismatches = app(ReconciliationService::class)->run()['mismatches'];
        $this->assertCount(1, $mismatches);
        $this->assertEqualsWithDelta(5.0, $mismatches[0]['difference'], 0.001);
    }

    public function testDepletedBatchStatusFollowsStock(): void
    {
        $p = $this->product();
        $this->receive($p, [['A', 10, date('Y-m-d', strtotime('+60 days')), 1]]);
        $batch = (int) $this->db->fetchValue('SELECT id FROM product_batches WHERE product_id = :p', ['p' => $p]);
        $dept = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'CS'");
        app(StockService::class)->post(['type' => 'consumption', 'department_id' => $dept, 'performed_by' => $this->userId], [
            ['product_id' => $p, 'batch_id' => $batch, 'movement_type' => 'CONSUMED', 'from_location_id' => $this->loc('CS-MAIN'), 'to_location_id' => null, 'quantity' => 10],
        ]);
        $this->assertSame('depleted', $this->db->fetchValue('SELECT status FROM product_batches WHERE id = :b', ['b' => $batch]));
    }
}
