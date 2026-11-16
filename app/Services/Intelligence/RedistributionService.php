<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;
use App\Services\Support\CacheService;
use App\Services\Support\SettingsService;

/**
 * Smart redistribution between departments (central store excluded).
 *
 * Surplus at a department = the larger of
 *   - stock above its maximum level,
 *   - stock beyond N days of its own usage (redistribution_cover_days),
 *   - stock expected to expire before it can be used (expiry simulation),
 *   - all stock if the department has not used the product in 90 days and does not stock it,
 * but never so much that the department falls below its own reorder level.
 * Deficit = below its reorder level (to target), or < 7 days of cover (to 30 days).
 *
 * Suggestions are proposals only: creating one makes a DRAFT transfer that still
 * needs approval by an authorised person.
 */
final class RedistributionService
{
    public function __construct(private Database $db, private ExpiryService $expiry, private SettingsService $settings, private CacheService $cache)
    {
    }

    public function suggestions(?array $scope = null): array
    {
        return $this->cache->remember('redistribution:' . json_encode($scope), 600, fn () => $this->compute($scope));
    }

    private function compute(?array $scope): array
    {
        $coverDays = $this->settings->int('redistribution_cover_days', 90);
        $rates = $this->expiry->departmentRates();
        $atRisk = [];
        foreach ($this->expiry->analyse(null)['rows'] as $r) {
            if ($r['at_risk'] > 0 && !(int) $r['is_central_store']) {
                $atRisk[(int) $r['department_id']][(int) $r['product_id']] = ($atRisk[(int) $r['department_id']][(int) $r['product_id']] ?? 0) + $r['at_risk'];
            }
        }
        $holdings = $this->db->fetchAll(
            "SELECT d.id AS department_id, d.name AS department_name, p.id AS product_id, p.name AS product_name, p.product_code,
                    u.code AS unit_code, u.allows_decimal, COALESCE(ds.usable_qty, 0) AS usable,
                    s.reorder_level, s.max_level, COALESCE(s.is_stocked, 0) AS is_stocked
               FROM departments d
               CROSS JOIN products p
               JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN v_department_stock ds ON ds.department_id = d.id AND ds.product_id = p.id
               LEFT JOIN department_product_settings s ON s.department_id = d.id AND s.product_id = p.id
              WHERE d.is_active = 1 AND d.is_central_store = 0 AND p.is_active = 1
                AND (ds.product_id IS NOT NULL OR s.id IS NOT NULL)"
        );
        $byProduct = [];
        foreach ($holdings as $h) {
            $pid = (int) $h['product_id'];
            $did = (int) $h['department_id'];
            $rate = $rates[$pid][$did]['rate'] ?? 0.0;
            $usable = (float) $h['usable'];
            $reorder = (float) ($h['reorder_level'] ?? 0);
            $max = (float) ($h['max_level'] ?? 0);
            $reasons = [];
            $surplus = 0.0;
            if ($max > 0 && $usable > $max) {
                $surplus = max($surplus, $usable - $max);
                $reasons[] = 'above maximum level';
            }
            if ($rate > 0 && $usable / $rate > $coverDays) {
                $surplus = max($surplus, $usable - $rate * 60);
                $reasons[] = round($usable / $rate) . ' days of cover';
            }
            if (!empty($atRisk[$did][$pid])) {
                $surplus = max($surplus, $atRisk[$did][$pid]);
                $reasons[] = 'expected to expire unused';
            }
            if ($rate <= 0 && $usable > 0 && !(int) $h['is_stocked']) {
                $surplus = $usable;
                $reasons[] = 'not used in 90 days';
            }
            $surplus = min($surplus, max(0, $usable - $reorder));
            $deficit = 0.0;
            $need = '';
            if ($reorder > 0 && $usable <= $reorder) {
                $target = $max > 0 ? $max : 2 * $reorder;
                $deficit = $target - $usable;
                $need = 'below reorder level';
            } elseif ($rate > 0 && $usable / $rate < 7) {
                $deficit = $rate * 30 - $usable;
                $need = 'less than 7 days of cover';
            }
            $byProduct[$pid]['info'] = $h;
            if ($surplus > 0) {
                $byProduct[$pid]['sources'][] = ['department_id' => $did, 'name' => $h['department_name'], 'qty' => $surplus, 'reason' => implode(', ', $reasons), 'rate' => $rate];
            }
            if ($deficit > 0) {
                $byProduct[$pid]['needs'][] = ['department_id' => $did, 'name' => $h['department_name'], 'qty' => $deficit, 'reason' => $need, 'rate' => $rate, 'usable' => $usable];
            }
        }

        $out = [];
        foreach ($byProduct as $pid => $g) {
            if (empty($g['sources']) || empty($g['needs'])) {
                continue;
            }
            usort($g['needs'], fn ($a, $b) => $b['qty'] <=> $a['qty']);
            usort($g['sources'], fn ($a, $b) => $b['qty'] <=> $a['qty']);
            foreach ($g['needs'] as $need) {
                foreach ($g['sources'] as &$src) {
                    if ($need['qty'] <= 0 || $src['qty'] <= 0 || $src['department_id'] === $need['department_id']) {
                        continue;
                    }
                    $qty = min($need['qty'], $src['qty']);
                    if (!(int) $g['info']['allows_decimal']) {
                        $qty = floor($qty);
                    }
                    if ($qty <= 0) {
                        continue;
                    }
                    if ($scope !== null && !in_array($src['department_id'], $scope, true) && !in_array($need['department_id'], $scope, true)) {
                        continue;
                    }
                    $fromLoc = $this->mainLocation($src['department_id'], $pid);
                    $toLoc = $this->mainLocation($need['department_id'], null);
                    if (!$fromLoc || !$toLoc) {
                        continue;
                    }
                    $out[] = [
                        'product_id' => $pid, 'product_name' => $g['info']['product_name'], 'product_code' => $g['info']['product_code'],
                        'unit_code' => $g['info']['unit_code'], 'qty' => $qty,
                        'from_department_id' => $src['department_id'], 'from_department' => $src['name'], 'from_location_id' => $fromLoc,
                        'to_department_id' => $need['department_id'], 'to_department' => $need['name'], 'to_location_id' => $toLoc,
                        'reason' => "{$src['name']}: {$src['reason']}. {$need['name']}: {$need['reason']}" . ($need['rate'] > 0 ? ' (uses ' . round($need['rate'], 1) . '/day)' : '') . '.',
                    ];
                    $src['qty'] -= $qty;
                    $need['qty'] -= $qty;
                }
                unset($src);
            }
        }
        return $out;
    }

    /** Department location holding the most usable stock of the product (or its main store). */
    private function mainLocation(int $departmentId, ?int $productId): ?int
    {
        if ($productId !== null) {
            $id = $this->db->fetchValue(
                "SELECT sb.location_id FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id
                   JOIN product_batches b ON b.id = sb.batch_id
                  WHERE l.department_id = :d AND sb.product_id = :p AND l.type NOT IN ('transit','quarantine') AND l.is_active = 1
                    AND b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                  GROUP BY sb.location_id ORDER BY SUM(sb.quantity) DESC LIMIT 1",
                ['d' => $departmentId, 'p' => $productId]
            );
            if ($id) {
                return (int) $id;
            }
        }
        $id = $this->db->fetchValue("SELECT id FROM inventory_locations WHERE department_id = :d AND type = 'store' AND is_active = 1 ORDER BY id LIMIT 1", ['d' => $departmentId]);
        return $id ? (int) $id : null;
    }
}
