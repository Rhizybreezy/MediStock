<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Catalogue\ProductService;
use App\Services\Codes\CodeService;
use App\Services\Codes\ScanService;
use App\Services\Stock\ReceivingService;

final class CodesTest extends DatabaseTestCase
{
    private int $product;
    private string $productCode;
    private string $gtin;

    protected function setUp(): void
    {
        parent::setUp();
        $inv = $this->actingAs('inventory');
        $cat = (int) $this->db->fetchValue("SELECT id FROM inventory_categories WHERE code = 'MED'");
        $tab = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'TAB'");
        $box = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'BOX'");
        $this->gtin = '0' . random_int(100000000000, 999999999999);
        $this->product = app(ProductService::class)->create([
            'name' => 'Code test ' . bin2hex(random_bytes(3)), 'generic_name' => null, 'category_id' => $cat, 'base_unit_id' => $tab,
            'dosage_form' => null, 'strength' => null, 'manufacturer' => null, 'description' => null, 'storage_condition' => 'room_temp',
            'storage_notes' => null, 'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_critical' => 0, 'is_controlled' => 0,
            'min_level' => 0, 'reorder_level' => 0, 'max_level' => 0, 'default_lead_time_days' => null, 'is_active' => 1,
        ], [['unit_id' => $box, 'factor_to_base' => 100, 'gtin_barcode' => $this->gtin]], null, $inv);
        $this->productCode = (string) $this->db->fetchValue('SELECT product_code FROM products WHERE id = :p', ['p' => $this->product]);
        app(ReceivingService::class)->receive(
            ['receipt_type' => 'direct', 'receiving_location_id' => (int) $this->db->fetchValue("SELECT id FROM inventory_locations WHERE code='CS-MAIN'"), 'submission_token' => bin2hex(random_bytes(16))],
            [['product_id' => $this->product, 'lot_number' => 'QR1', 'quantity' => 50, 'unit_id' => null, 'expiry_date' => date('Y-m-d', strtotime('+200 days')), 'manufacture_date' => null, 'unit_cost' => 1]],
            $inv
        );
    }

    private function batchCode(): string
    {
        return (string) $this->db->fetchValue('SELECT batch_code FROM product_batches WHERE product_id = :p', ['p' => $this->product]);
    }

    public function testResolvesLabelsBareCodesAndGtin(): void
    {
        $codes = app(CodeService::class);
        $this->assertSame('product', $codes->resolve('MSP:' . $this->productCode)['type']);
        $this->assertSame('product', $codes->resolve(strtolower($this->productCode))['type']);
        $this->assertSame('batch', $codes->resolve('MSB:' . $this->batchCode())['type']);
        $this->assertSame('batch', $codes->resolve(' ' . $this->batchCode() . "\r\n")['type']);
        $g = $codes->resolve($this->gtin);
        $this->assertSame($this->product, $g['product_id']);
        $this->assertStringContainsString('manufacturer barcode', $g['matched_by']);
        $this->assertSame('unknown', $codes->resolve('MSB:NOPE-1')['type']);
        $this->assertSame('unknown', $codes->resolve('')['type']);
    }

    public function testProductAndBatchPayloadsAreDistinct(): void
    {
        $this->assertStringStartsWith('MSP:', CodeService::productPayload('MED-000001'));
        $this->assertStringStartsWith('MSB:', CodeService::batchPayload('B26-000001'));
        $svg = app(CodeService::class)->qrSvg('MSB:B26-000001');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringStartsWith('data:image/png;base64,', app(CodeService::class)->qrPngDataUri('MSP:X'));
        $this->assertStringContainsString('<svg', app(CodeService::class)->barcodeSvg('MSB:B26-000001'));
    }

    public function testRecalledAndExpiredBatchesAreBlockedWithWarnings(): void
    {
        $scan = app(ScanService::class);
        $ok = $scan->lookup('MSB:' . $this->batchCode());
        $this->assertFalse($ok['batch']['blocked']);
        $this->assertSame(50.0, $ok['stock'][0]['quantity']);

        $this->db->execute("UPDATE product_batches SET status = 'recalled' WHERE product_id = :p", ['p' => $this->product]);
        $r = $scan->lookup('MSB:' . $this->batchCode());
        $this->assertTrue($r['batch']['blocked']);
        $this->assertStringContainsString('RECALLED', $r['batch']['warning']);

        $this->db->execute("UPDATE product_batches SET status = 'active', expiry_date = CURDATE() - INTERVAL 2 DAY WHERE product_id = :p", ['p' => $this->product]);
        $e = $scan->lookup('MSB:' . $this->batchCode());
        $this->assertTrue($e['batch']['blocked']);
        $this->assertStringContainsString('EXPIRED', $e['batch']['warning']);
    }

    public function testScanStockRespectsDepartmentScope(): void
    {
        $this->actingAs('maternity');
        $r = app(ScanService::class)->lookup('MSP:' . $this->productCode);
        $this->assertSame([], $r['stock'], 'Central-store stock must not be shown to a maternity storekeeper');
    }
}
