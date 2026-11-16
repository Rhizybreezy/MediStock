<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Services\Catalogue\ProductService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\PurchaseRequestService;
use App\Services\Procurement\SupplierPerformanceService;
use App\Services\Procurement\SupplierService;
use App\Services\Stock\ReceivingService;
use App\Services\Stock\ReconciliationService;

/**
 * Purchase request → approval → purchase order → approval → goods receipt → batches/stock.
 */
final class ProcurementTest extends DatabaseTestCase
{
    private int $proc;
    private int $mgr;
    private int $inv;
    private int $product;
    private int $supplier;
    private int $box;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = fn ($u) => (int) $this->db->fetchValue('SELECT id FROM users WHERE username = :u', ['u' => $u]);
        $this->proc = $uid('procurement');
        $this->mgr = $uid('manager');
        $this->inv = $uid('inventory');
        $this->actingAs('inventory');
        $cat = (int) $this->db->fetchValue("SELECT id FROM inventory_categories WHERE code = 'MED'");
        $tab = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'TAB'");
        $this->box = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'BOX'");
        $this->product = app(ProductService::class)->create([
            'name' => 'PO Test drug ' . bin2hex(random_bytes(3)), 'generic_name' => null, 'category_id' => $cat, 'base_unit_id' => $tab,
            'dosage_form' => null, 'strength' => null, 'manufacturer' => null, 'description' => null, 'storage_condition' => 'room_temp',
            'storage_notes' => null, 'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_critical' => 0, 'is_controlled' => 0,
            'min_level' => 100, 'reorder_level' => 500, 'max_level' => 3000, 'default_lead_time_days' => 10, 'is_active' => 1,
        ], [['unit_id' => $this->box, 'factor_to_base' => 100, 'gtin_barcode' => null, 'is_purchase_unit' => 1]], null, $this->inv);
        $this->actingAs('procurement');
        $this->supplier = app(SupplierService::class)->save(null, [
            'name' => 'Test Pharma ' . bin2hex(random_bytes(3)), 'contact_person' => 'A', 'phone' => null, 'email' => null, 'address' => null,
            'tax_number' => null, 'payment_terms' => '30 days', 'default_lead_time_days' => 7, 'status' => 'active', 'notes' => null,
        ], $this->proc);
        app(SupplierService::class)->saveProduct($this->supplier, ['product_id' => $this->product, 'supplier_sku' => 'X1', 'quoted_unit_price' => 0.05, 'quote_valid_until' => null, 'lead_time_days' => 7, 'is_preferred' => 1]);
    }

    private function loc(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM inventory_locations WHERE code = :c', ['c' => $code]);
    }

    private function approvedPr(float $qty): array
    {
        $cs = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'CS'");
        $prId = app(PurchaseRequestService::class)->create(['department_id' => $cs, 'priority' => 'routine', 'justification' => 'Low stock'],
            [['product_id' => $this->product, 'quantity' => $qty, 'unit_id' => null, 'estimated_unit_cost' => 0.05, 'preferred_supplier_id' => $this->supplier]], true, $this->proc);
        $this->actingAs('manager');
        app(PurchaseRequestService::class)->decide($prId, true, null, $this->mgr);
        $this->actingAs('procurement');
        $item = (int) $this->db->fetchValue('SELECT id FROM purchase_request_items WHERE purchase_request_id = :p', ['p' => $prId]);
        return [$prId, $item];
    }

    public function testLowStockProductIsSuggested(): void
    {
        $s = array_values(array_filter(app(PurchaseRequestService::class)->lowStockSuggestions(), fn ($r) => (int) $r['product_id'] === $this->product));
        $this->assertCount(1, $s);
        $this->assertEquals(3000, $s[0]['suggested_qty']);            // max level − 0 usable
        $this->assertSame($this->supplier, (int) $s[0]['preferred_supplier_id']);
    }

    public function testRequesterCannotApproveOwnPr(): void
    {
        $cs = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'CS'");
        $prId = app(PurchaseRequestService::class)->create(['department_id' => $cs], [['product_id' => $this->product, 'quantity' => 10]], true, $this->proc);
        $this->expectException(AuthorizationException::class); // procurement lacks purchase_requests.approve
        app(PurchaseRequestService::class)->decide($prId, true, null, $this->proc);
    }

    public function testFullCycleWithPartialDeliveryAndRejection(): void
    {
        [$prId, $item] = $this->approvedPr(2000);
        $pos = app(PurchaseOrderService::class)->createFromRequests([$item => ['supplier_id' => $this->supplier, 'unit_price' => 0.05, 'quantity' => 2000]], $this->loc('CS-MAIN'), $this->proc);
        $this->assertCount(1, $pos);
        $po = $pos[0];
        $this->assertSame('ordered', $this->db->fetchValue('SELECT status FROM purchase_requests WHERE id = :p', ['p' => $prId]));

        // Edit draft to order in boxes: 20 boxes x 5.00
        app(PurchaseOrderService::class)->saveDraft($po, ['supplier_id' => $this->supplier, 'delivery_location_id' => $this->loc('CS-MAIN'), 'expected_delivery_date' => null, 'tax_amount' => 0],
            [['product_id' => $this->product, 'order_unit_id' => $this->box, 'quantity' => 20, 'unit_price' => 5.00, 'purchase_request_item_id' => $item]], $this->proc);
        $this->assertEquals(100.00, (float) $this->db->fetchValue('SELECT total_amount FROM purchase_orders WHERE id = :id', ['id' => $po]));

        // Cannot receive before approval
        $this->actingAs('inventory');
        $poItem = (int) $this->db->fetchValue('SELECT id FROM purchase_order_items WHERE purchase_order_id = :id', ['id' => $po]);
        try {
            app(ReceivingService::class)->receivePurchaseOrder($po, ['receiving_location_id' => null, 'delivery_note_number' => 'DN1', 'submission_token' => bin2hex(random_bytes(16))],
                [['purchase_order_item_id' => $poItem, 'lot_number' => 'L1', 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'quantity' => 5]], $this->inv);
            $this->fail('Receipt against unapproved PO must fail');
        } catch (BusinessRuleException) {
        }

        $this->actingAs('procurement');
        app(PurchaseOrderService::class)->submit($po, $this->proc);
        try {
            app(PurchaseOrderService::class)->decide($po, true, null, $this->proc);
            $this->fail('Creator must not approve own PO');
        } catch (AuthorizationException|BusinessRuleException) {
        }
        $this->actingAs('manager');
        app(PurchaseOrderService::class)->decide($po, true, null, $this->mgr);
        $this->actingAs('procurement');
        app(PurchaseOrderService::class)->markSent($po, $this->proc);

        // Delivery 1: 12 boxes accepted, 1 box rejected (damaged)
        $this->actingAs('inventory');
        app(ReceivingService::class)->receivePurchaseOrder($po, ['receiving_location_id' => null, 'delivery_note_number' => 'DN-A', 'submission_token' => bin2hex(random_bytes(16))],
            [['purchase_order_item_id' => $poItem, 'lot_number' => 'LOT-A', 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'quantity' => 12, 'qty_rejected' => 1, 'rejection_reason' => 'Crushed']], $this->inv);
        $this->assertSame('partially_received', $this->db->fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po]));
        $this->assertEquals(1200, (float) $this->db->fetchValue('SELECT received_qty_base FROM purchase_order_items WHERE id = :i', ['i' => $poItem]));
        $this->assertEqualsWithDelta(0.05, (float) $this->db->fetchValue("SELECT unit_cost FROM product_batches WHERE product_id = :p AND lot_number = 'LOT-A'", ['p' => $this->product]), 0.0001);

        // Same delivery note again is refused
        try {
            app(ReceivingService::class)->receivePurchaseOrder($po, ['receiving_location_id' => null, 'delivery_note_number' => 'DN-A', 'submission_token' => bin2hex(random_bytes(16))],
                [['purchase_order_item_id' => $poItem, 'lot_number' => 'LOT-A', 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'quantity' => 1]], $this->inv);
            $this->fail('Duplicate delivery note must be refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already been received', $e->getMessage());
        }
        // Over-receipt is refused (8 boxes outstanding)
        try {
            app(ReceivingService::class)->receivePurchaseOrder($po, ['receiving_location_id' => null, 'delivery_note_number' => 'DN-B', 'submission_token' => bin2hex(random_bytes(16))],
                [['purchase_order_item_id' => $poItem, 'lot_number' => 'LOT-B', 'expiry_date' => date('Y-m-d', strtotime('+1 year')), 'quantity' => 9]], $this->inv);
            $this->fail('Over-receipt must be refused');
        } catch (BusinessRuleException) {
        }
        app(ReceivingService::class)->receivePurchaseOrder($po, ['receiving_location_id' => null, 'delivery_note_number' => 'DN-B', 'submission_token' => bin2hex(random_bytes(16))],
            [['purchase_order_item_id' => $poItem, 'lot_number' => 'LOT-B', 'expiry_date' => date('Y-m-d', strtotime('+2 years')), 'quantity' => 8]], $this->inv);
        $this->assertSame('received', $this->db->fetchValue('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $po]));
        $this->assertEquals(2000.0, (float) $this->db->fetchValue('SELECT SUM(quantity) FROM stock_balances WHERE product_id = :p', ['p' => $this->product]));

        $m = app(SupplierPerformanceService::class)->metrics($this->supplier)[0];
        $this->assertSame(1, $m['deliveries']);
        $this->assertEqualsWithDelta(1.0, $m['fill_rate'], 0.001);
        $this->assertEqualsWithDelta(100 / 2100, $m['rejection_rate'], 0.001);
        $this->assertSame([], app(ReconciliationService::class)->run()['mismatches']);
    }

    public function testCancellingPoReleasesPrQuantities(): void
    {
        [$prId, $item] = $this->approvedPr(500);
        [$po] = app(PurchaseOrderService::class)->createFromRequests([$item => ['supplier_id' => $this->supplier, 'unit_price' => 0.05, 'quantity' => 300]], $this->loc('CS-MAIN'), $this->proc);
        $this->assertSame('partially_ordered', $this->db->fetchValue('SELECT status FROM purchase_requests WHERE id = :p', ['p' => $prId]));
        app(PurchaseOrderService::class)->cancel($po, 'Supplier out of stock', $this->proc);
        $this->assertSame('approved', $this->db->fetchValue('SELECT status FROM purchase_requests WHERE id = :p', ['p' => $prId]));
        $this->assertEquals(0, (float) $this->db->fetchValue('SELECT ordered_qty FROM purchase_request_items WHERE id = :i', ['i' => $item]));
    }
}
