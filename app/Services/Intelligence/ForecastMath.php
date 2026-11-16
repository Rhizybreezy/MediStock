<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

/**
 * Pure forecasting formulas (no database access) so they can be unit-tested
 * and explained. All quantities are in base units, all rates per day.
 *
 *   ADC (average daily consumption)
 *     ≥ 90 days history : 0.6 × avg(last 30 d) + 0.4 × avg(last 90 d)
 *     14–89 days        : total ÷ days of history
 *     < 14 days         : insufficient data (no forecast)
 *   σ   = standard deviation of daily consumption (last 90 days, zero days included)
 *   Safety stock = 1.65 × σ × √LT   (≈95 % service level)  when ≥ 30 days of history,
 *                  otherwise safety_days × ADC
 *   Reorder point = ADC × LT + safety stock
 *   Reorder qty   = max(0, ADC × (LT + review) + SS − usable − on order), capped at max level, rounded up to pack size
 */
final class ForecastMath
{
    public const Z_95 = 1.65;

    /**
     * @param array<string, float> $daily date (Y-m-d) => quantity consumed (missing days = 0)
     * @return array{adc: ?float, avg30: float, avg90: float, history_days: int, sigma: float, cv: ?float, basis: string, confidence: string}
     */
    public static function consumption(array $daily, string $today, int $historyDays): array
    {
        $sum = function (int $days) use ($daily, $today): float {
            $from = date('Y-m-d', strtotime("$today -$days days"));
            $total = 0.0;
            foreach ($daily as $d => $q) {
                if ($d > $from && $d <= $today) {
                    $total += (float) $q;
                }
            }
            return $total;
        };
        $avg30 = $sum(30) / 30;
        $avg90 = $sum(90) / 90;

        // σ over the last min(90, history) days including zero-consumption days.
        $window = max(1, min(90, $historyDays));
        $values = [];
        for ($i = 0; $i < $window; $i++) {
            $d = date('Y-m-d', strtotime("$today -$i days"));
            $values[] = (float) ($daily[$d] ?? 0);
        }
        $mean = array_sum($values) / count($values);
        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $mean) ** 2;
        }
        $sigma = count($values) > 1 ? sqrt($var / (count($values) - 1)) : 0.0;

        if ($historyDays < 14) {
            return ['adc' => null, 'avg30' => $avg30, 'avg90' => $avg90, 'history_days' => $historyDays, 'sigma' => $sigma, 'cv' => null,
                    'basis' => "Only $historyDays day(s) of history; at least 14 are needed", 'confidence' => 'insufficient'];
        }
        if ($historyDays >= 90) {
            $adc = 0.6 * $avg30 + 0.4 * $avg90;
            $basis = 'Weighted: 60% last 30 days + 40% last 90 days';
        } else {
            $adc = $sum($historyDays) / $historyDays;
            $basis = "Average over $historyDays days of history";
        }
        $cv = $adc > 0 ? $sigma / $adc : null;
        $confidence = match (true) {
            $adc <= 0                                => 'no_demand',
            $historyDays >= 90 && $cv !== null && $cv < 0.5 => 'high',
            $historyDays >= 30 && $cv !== null && $cv < 1.0 => 'medium',
            default                                  => 'low',
        };
        return ['adc' => $adc, 'avg30' => $avg30, 'avg90' => $avg90, 'history_days' => $historyDays, 'sigma' => $sigma, 'cv' => $cv, 'basis' => $basis, 'confidence' => $confidence];
    }

    /** Seasonal factor = same calendar month last year ÷ average month, clamped 0.5–2 (needs ≥ 365 days). */
    public static function seasonalFactor(array $daily, string $today, int $historyDays): ?float
    {
        if ($historyDays < 365) {
            return null;
        }
        $month = date('m', strtotime($today));
        $byMonth = [];
        foreach ($daily as $d => $q) {
            $byMonth[substr($d, 5, 2)] = ($byMonth[substr($d, 5, 2)] ?? 0) + (float) $q;
        }
        if (count($byMonth) < 10) {
            return null;
        }
        $avg = array_sum($byMonth) / 12;
        if ($avg <= 0 || !isset($byMonth[$month])) {
            return null;
        }
        return max(0.5, min(2.0, $byMonth[$month] / $avg));
    }

    /** @return array{0: float, 1: string} [safety stock, method] */
    public static function safetyStock(float $adc, float $sigma, int $leadDays, int $historyDays, int $safetyDays): array
    {
        if ($historyDays >= 30 && $sigma > 0) {
            return [self::Z_95 * $sigma * sqrt(max(1, $leadDays)), 'Statistical: 1.65 × σ × √lead time (≈95% service level)'];
        }
        return [$safetyDays * $adc, "Rule of thumb: $safetyDays days of average use"];
    }

    public static function reorderQuantity(float $adc, int $leadDays, int $reviewDays, float $safety, float $usable, float $onOrder, float $maxLevel, float $packSize): float
    {
        $need = $adc * ($leadDays + $reviewDays) + $safety - $usable - $onOrder;
        if ($maxLevel > 0) {
            $need = min($need, $maxLevel - $usable - $onOrder);
        }
        if ($need <= 0) {
            return 0.0;
        }
        $pack = $packSize > 0 ? $packSize : 1.0;
        return ceil($need / $pack) * $pack;
    }

    /**
     * critical: no usable stock, or it runs out before a new delivery could arrive (and no PO arrives in time)
     * high:     at or below reorder point
     * medium:   will reach the reorder point within one review period
     */
    public static function urgency(float $usable, ?float $adc, ?float $daysRemaining, int $leadDays, float $reorderPoint, int $reviewDays, bool $poArrivesInTime): string
    {
        if ($adc === null) {
            return 'insufficient';
        }
        if ($adc <= 0) {
            return 'no_demand';
        }
        if ($usable <= 0 || ($daysRemaining !== null && $daysRemaining < $leadDays && !$poArrivesInTime)) {
            return 'critical';
        }
        if ($usable <= $reorderPoint) {
            return 'high';
        }
        if ($usable <= $reorderPoint + $adc * $reviewDays) {
            return 'medium';
        }
        return 'ok';
    }
}
