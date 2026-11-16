<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\BusinessRuleException;
use App\Services\Catalogue\ProductService;
use App\Services\ColdChain\TemperatureService;
use App\Services\Intelligence\AlertService;
use App\Services\Intelligence\ExpiryService;
use App\Services\Intelligence\ForecastService;
use App\Services\Stock\ReceivingService;
use App\Services\Stock\StockService;

/**
 * Forecast, expiry risk, alerts and cold chain against real (rolled-back) data.
 */
final class IntelligenceTest extends DatabaseTestCase
{
    private int $inv;
    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv = $this->actingAs('inventory');
        $cat = (int) $this->db->fetchValue("SELECT id FROM inventory_categories WHERE code = 'MED'");
        $tab = (int) $this->db->fetchValue("SELECT id FROM units_of_measure WHERE code = 'TAB'");
        $this->product = app(ProductService::class)->create([
            'name' => 'Forecast test ' . bin2hex(random_bytes(3)), 'generic_name' => null, 'category_id' => $cat, 'base_unit_id' => $tab,
            'dosage_form' => null, 'strength' => null, 'manufacturer' => null, 'description' => null, 'storage_condition' => 'room_temp',
            'storage_notes' => null, 'is_batch_tracked' => 1, 'is_expiry_tracked' => 1, 'is_critical' => 1, 'is_controlled' => 0,
            'min_level' => 50, 'reorder_level' => 200, 'max_level' => 2000, 'default_lead_time_days' => 10, 'is_active' => 1,
        ], [], null, $this->inv);
    }

    private function loc(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM inventory_locations WHERE code = :c', ['c' => $code]);
    }

    /** Receive stock, then record back-dated daily consumption at a department. */
    private function history(int $days, int $perDay, int $stock, string $expiry = '+400 days'): void
    {
        app(ReceivingService::class)->receive(
            ['receipt_type' => 'direct', 'receiving_location_id' => $this->loc('MAT-MAIN'), 'submission_token' => bin2hex(random_bytes(16))],
            [['product_id' => $this->product, 'lot_number' => 'F1', 'quantity' => $stock, 'unit_id' => null, 'expiry_date' => date('Y-m-d', strtotime($expiry)), 'manufacture_date' => null, 'unit_cost' => 1]],
            $this->inv
        );
        // Stock arrives on the same day consumption starts.
        $this->db->execute('UPDATE stock_movements SET created_at = NOW() - INTERVAL :d DAY WHERE product_id = :p', ['d' => $days, 'p' => $this->product]);
        $batch = (int) $this->db->fetchValue('SELECT id FROM product_batches WHERE product_id = :p', ['p' => $this->product]);
        $dept = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'MAT'");
        for ($i = $days; $i >= 1; $i--) {
            $txn = app(StockService::class)->post(['type' => 'consumption', 'department_id' => $dept, 'performed_by' => $this->inv], [[
                'product_id' => $this->product, 'batch_id' => $batch, 'movement_type' => 'CONSUMED',
                'from_location_id' => $this->loc('MAT-MAIN'), 'to_location_id' => null, 'quantity' => $perDay,
            ]]);
            $this->db->execute('UPDATE stock_movements SET created_at = NOW() - INTERVAL :d DAY WHERE transaction_id = :t', ['d' => $i, 't' => $txn]);
        }
    }

    public function testHospitalForecastFromSteadyConsumption(): void
    {
        $this->history(100, 10, 1300);           // 1000 consumed → 300 usable left
        $f = app(ForecastService::class)->forecast(null, $this->product)[0];
        $this->assertEqualsWithDelta(10, $f['adc'], 0.01);
        $this->assertSame('high', $f['confidence']);
        $this->assertEqualsWithDelta(30, $f['days_remaining'], 0.01);
        $this->assertSame(10, $f['lead_time']);
        // σ = 0 → safety stock = 7 days × 10 = 70; reorder point = 10 × 10 + 70 = 170.
        // 300 > 170 but ≤ 170 + 10 × 14-day review period (310) → "reorder soon".
        $this->assertEqualsWithDelta(170, $f['reorder_point'], 0.01);
        $this->assertSame('medium', $f['urgency']);
        $this->assertGreaterThan(0, $f['reorder_qty']);
        $this->assertNotEmpty($f['assumptions']);
    }

    public function testDepartmentForecastUsesInternalLeadTime(): void
    {
        $this->history(40, 5, 260);
        $dept = (int) $this->db->fetchValue("SELECT id FROM departments WHERE code = 'MAT'");
        $f = app(ForecastService::class)->forecast($dept, $this->product)[0];
        $this->assertEqualsWithDelta(5, $f['adc'], 0.01);
        $this->assertSame('internal supply time', $f['lead_source']);
        $this->assertEqualsWithDelta(12, $f['days_remaining'], 0.01);
    }

    public function testExpiryRiskUsesLocationUsageRate(): void
    {
        $this->history(30, 5, 650, '+40 days');   // 150 consumed → 500 left, expires in ~40 days, 5/day
        $rows = array_values(array_filter(app(ExpiryService::class)->analyse(null)['rows'], fn ($r) => (int) $r['product_id'] === $this->product));
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(5, $rows[0]['rate'], 0.01);
        $expected = 5 * (int) $rows[0]['days_to_expiry'];
        $this->assertEqualsWithDelta(500 - $expected, $rows[0]['at_risk'], 0.01);
    }

    public function testAlertsCreateDeduplicatedNotifications(): void
    {
        $this->history(100, 10, 1100); // 100 usable left → critical (10 days, lead time 10)
        $first = app(AlertService::class)->run();
        $this->assertGreaterThan(0, $first['low_stock']);
        $second = app(AlertService::class)->run();
        $this->assertSame(0, $second['low_stock'], 'Same-day alerts must not be duplicated');
    }

    public function testTemperatureExcursionRequiresActionAndAlerts(): void
    {
        $fridge = $this->loc('PH-FRIDGE');
        $this->actingAs('pharmacist');
        $uid = (int) $this->db->fetchValue("SELECT id FROM users WHERE username = 'pharmacist'");
        try {
            app(TemperatureService::class)->record(['location_id' => $fridge, 'temperature_c' => 11.5], $uid);
            $this->fail('Out-of-range reading without corrective action must be refused');
        } catch (BusinessRuleException) {
        }
        $r = app(TemperatureService::class)->record(['location_id' => $fridge, 'temperature_c' => 11.5, 'corrective_action' => 'Moved vaccines to backup fridge'], $uid);
        $this->assertTrue($r['out_of_range']);
        $this->assertGreaterThan(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM notifications WHERE dedupe_key = :k", ['k' => 'temp:' . $r['id']]));
        $ok = app(TemperatureService::class)->record(['location_id' => $fridge, 'temperature_c' => 5.0], $uid);
        $this->assertFalse($ok['out_of_range']);
    }

    public function testTemperatureOnlyForColdChainLocations(): void
    {
        $this->expectException(BusinessRuleException::class);
        app(TemperatureService::class)->record(['location_id' => $this->loc('CS-MAIN'), 'temperature_c' => 20], $this->inv);
    }
}
