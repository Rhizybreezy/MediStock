<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Intelligence\ExpirySimulator;
use App\Services\Intelligence\ForecastMath;
use PHPUnit\Framework\TestCase;

final class IntelligenceMathTest extends TestCase
{
    public function testSpecificationExpiryExample(): void
    {
        // 500 units, expires in 30 days, 5 units/day → 150 used, 350 at risk
        $r = ExpirySimulator::simulate([['qty' => 500, 'days_to_expiry' => 30]], 5);
        $this->assertEqualsWithDelta(150, $r[0]['expected_use'], 0.001);
        $this->assertEqualsWithDelta(350, $r[0]['at_risk'], 0.001);
    }

    public function testFefoAwareMultiBatchSimulation(): void
    {
        // Batch A (100, 10 days) is used first for 10 days at 10/day → fully used.
        // Batch B (300, 25 days) only starts on day 10 → 15 days × 10 = 150 used, 150 at risk.
        // Batch C (100, 100 days) starts on day 25 → fully used.
        $r = ExpirySimulator::simulate([
            ['qty' => 100, 'days_to_expiry' => 10],
            ['qty' => 300, 'days_to_expiry' => 25],
            ['qty' => 100, 'days_to_expiry' => 100],
        ], 10);
        $this->assertEqualsWithDelta(0, $r[0]['at_risk'], 0.001);
        $this->assertEqualsWithDelta(150, $r[1]['at_risk'], 0.001);
        $this->assertEqualsWithDelta(0, $r[2]['at_risk'], 0.001);
    }

    public function testNoUsageMeansEverythingAtRisk(): void
    {
        $r = ExpirySimulator::simulate([['qty' => 40, 'days_to_expiry' => 300]], 0);
        $this->assertSame(40.0, $r[0]['at_risk']);
    }

    private function series(int $days, float $perDay, string $today): array
    {
        $s = [];
        for ($i = 0; $i < $days; $i++) {
            $s[date('Y-m-d', strtotime("$today -$i days"))] = $perDay;
        }
        return $s;
    }

    public function testAdcWeightingWithLongHistory(): void
    {
        $today = '2026-06-30';
        // 10/day for the last 30 days, 4/day for the 60 days before.
        $s = $this->series(30, 10, $today);
        for ($i = 30; $i < 90; $i++) {
            $s[date('Y-m-d', strtotime("$today -$i days"))] = 4;
        }
        $c = ForecastMath::consumption($s, $today, 120);
        $avg90 = (300 + 240) / 90; // 6
        $this->assertEqualsWithDelta(0.6 * 10 + 0.4 * $avg90, $c['adc'], 0.0001);
        $this->assertSame('high', $c['confidence']); // σ≈2.84 vs ADC 8.4 → CV≈0.34 < 0.5
    }

    public function testInsufficientHistory(): void
    {
        $c = ForecastMath::consumption($this->series(5, 3, '2026-06-30'), '2026-06-30', 5);
        $this->assertNull($c['adc']);
        $this->assertSame('insufficient', $c['confidence']);
    }

    public function testStableDemandIsHighConfidence(): void
    {
        $c = ForecastMath::consumption($this->series(120, 8, '2026-06-30'), '2026-06-30', 120);
        $this->assertEqualsWithDelta(8, $c['adc'], 0.0001);
        $this->assertSame('high', $c['confidence']);
        $this->assertEqualsWithDelta(0, $c['sigma'], 0.0001);
    }

    public function testSafetyStockAndReorderQuantity(): void
    {
        [$ss, $method] = ForecastMath::safetyStock(10, 4, 9, 120, 7);
        $this->assertEqualsWithDelta(1.65 * 4 * 3, $ss, 0.0001);
        $this->assertStringContainsString('Statistical', $method);
        [$ss2] = ForecastMath::safetyStock(10, 0, 9, 10, 7);
        $this->assertEqualsWithDelta(70, $ss2, 0.0001);

        // need = 10 × (9 + 14) + 19.8 − 50 − 0 = 199.8 → rounded up to packs of 100 → 200
        $this->assertSame(200.0, ForecastMath::reorderQuantity(10, 9, 14, 19.8, 50, 0, 0, 100));
        // capped by max level: 120 − 50 = 70 → packs of 100 → 100
        $this->assertSame(100.0, ForecastMath::reorderQuantity(10, 9, 14, 19.8, 50, 0, 120, 100));
        $this->assertSame(0.0, ForecastMath::reorderQuantity(10, 9, 14, 19.8, 1000, 0, 0, 1));
    }

    public function testUrgencyRules(): void
    {
        // 50 units, 10/day → 5 days left; lead time 9 days; no PO → critical
        $this->assertSame('critical', ForecastMath::urgency(50, 10, 5, 9, 110, 14, false));
        // same but a PO arrives before stock-out → high (at or below reorder point 110)
        $this->assertSame('high', ForecastMath::urgency(50, 10, 5, 9, 110, 14, true));
        $this->assertSame('medium', ForecastMath::urgency(200, 10, 20, 9, 110, 14, false));
        $this->assertSame('ok', ForecastMath::urgency(1000, 10, 100, 9, 110, 14, false));
        $this->assertSame('no_demand', ForecastMath::urgency(1000, 0, null, 9, 0, 14, false));
    }
}
