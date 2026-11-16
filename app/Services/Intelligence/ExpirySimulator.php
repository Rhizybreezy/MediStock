<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

/**
 * FEFO-aware expiry simulation for ONE product at ONE location.
 *
 * Batches are used earliest-expiry first, so a later batch only starts being
 * consumed after earlier batches are used up:
 *
 *   cursor = 0 (days of usage already "spoken for")
 *   for each batch in expiry order:
 *       available_days = max(0, days_to_expiry − cursor)
 *       expected_use   = min(qty, rate × available_days)
 *       at_risk        = qty − expected_use
 *       cursor        += expected_use ÷ rate
 *
 * Example: 500 units, expires in 30 days, rate 5/day → expected use 150, ≈350 at risk.
 */
final class ExpirySimulator
{
    /**
     * @param array<int, array{qty: float, days_to_expiry: int}> $batches already sorted by expiry ASC
     * @return array<int, array{expected_use: float, at_risk: float}> same keys as input
     */
    public static function simulate(array $batches, float $ratePerDay): array
    {
        $out = [];
        $cursor = 0.0;
        foreach ($batches as $key => $b) {
            $qty = (float) $b['qty'];
            if ($ratePerDay <= 0) {
                $out[$key] = ['expected_use' => 0.0, 'at_risk' => $qty];
                continue;
            }
            $availableDays = max(0.0, (float) $b['days_to_expiry'] - $cursor);
            $use = min($qty, $ratePerDay * $availableDays);
            $out[$key] = ['expected_use' => round($use, 3), 'at_risk' => round($qty - $use, 3)];
            $cursor += $use / $ratePerDay;
        }
        return $out;
    }
}
