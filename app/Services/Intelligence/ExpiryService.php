<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;
use App\Services\Support\CacheService;

/**
 * Expiry risk: for every location, batches are simulated FEFO against that
 * location's recent usage rate (stock leaving the location for consumption,
 * issues or transfers). Expired stock is listed separately and is never
 * suggested for use.
 */
final class ExpiryService
{
    public function __construct(private Database $db, private CacheService $cache, private ConsumptionQuery $consumption)
    {
    }

    /** @param int[]|null $scope departments (null = hospital-wide) */
    public function analyse(?array $scope = null, int $horizonDays = 365): array
    {
        return $this->cache->remember('expiry:' . json_encode($scope) . ':' . $horizonDays, 600, fn () => $this->compute($scope, $horizonDays));
    }

    private function compute(?array $scope, int $horizonDays): array
    {
        [$scopeSql, $params] = $this->scopeSql($scope);
        $balances = $this->db->fetchAll(
            "SELECT sb.location_id, l.name AS location_name, l.department_id, d.name AS department_name, d.is_central_store,
                    sb.product_id, p.name AS product_name, p.product_code, u.code AS unit_code,
                    sb.batch_id, b.batch_code, b.lot_number, b.expiry_date, b.unit_cost, sb.quantity,
                    DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
               FROM stock_balances sb
               JOIN product_batches b ON b.id = sb.batch_id
               JOIN products p ON p.id = sb.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN inventory_locations l ON l.id = sb.location_id
               JOIN departments d ON d.id = l.department_id
              WHERE sb.quantity > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date >= CURDATE() AND b.status = 'active'
                AND l.type NOT IN ('transit','quarantine') $scopeSql
              ORDER BY sb.location_id, sb.product_id, b.expiry_date, b.received_at",
            $params
        );
        $rates = $this->locationRates();
        $deptRates = $this->departmentRates();

        $groups = [];
        foreach ($balances as $b) {
            $groups[$b['location_id'] . ':' . $b['product_id']][] = $b;
        }
        $rows = [];
        foreach ($groups as $key => $batches) {
            [$loc, $pid] = array_map('intval', explode(':', $key));
            $rate = $rates[$loc][$pid] ?? 0.0;
            $sim = ExpirySimulator::simulate(array_map(fn ($b) => ['qty' => (float) $b['quantity'], 'days_to_expiry' => (int) $b['days_to_expiry']], $batches), $rate);
            foreach ($batches as $i => $b) {
                if ((int) $b['days_to_expiry'] > $horizonDays) {
                    continue;
                }
                $atRisk = $sim[$i]['at_risk'];
                $qty = (float) $b['quantity'];
                $row = $b + [
                    'rate' => $rate, 'expected_use' => $sim[$i]['expected_use'], 'at_risk' => $atRisk,
                    'value_at_risk' => $atRisk * (float) $b['unit_cost'],
                    'risk' => $atRisk <= 0.0005 ? 'none' : ($atRisk >= 0.5 * $qty || (int) $b['days_to_expiry'] <= 30 ? 'high' : 'medium'),
                ];
                $row['suggestion'] = $this->suggest($row, $deptRates[$pid] ?? [], (int) $b['department_id']);
                $row['assumption'] = $rate > 0
                    ? sprintf('Uses %s/day at this location (recent 30/90-day rate); earlier-expiring batches are used first.', rtrim(rtrim(number_format($rate, 2), '0'), '.'))
                    : 'No stock has left this location in the last 90 days, so all of it is at risk unless moved.';
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => [$b['value_at_risk'] > 0, $a['days_to_expiry']] <=> [$a['value_at_risk'] > 0, $b['days_to_expiry']]);

        $expired = $this->db->fetchAll(
            "SELECT sb.location_id, l.name AS location_name, l.type AS location_type, d.name AS department_name, p.name AS product_name, p.product_code,
                    u.code AS unit_code, b.id AS batch_id, b.batch_code, b.lot_number, b.expiry_date, b.unit_cost, sb.quantity,
                    sb.quantity * b.unit_cost AS value, DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
               FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id JOIN products p ON p.id = sb.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id JOIN inventory_locations l ON l.id = sb.location_id
               JOIN departments d ON d.id = l.department_id
              WHERE sb.quantity > 0 AND b.expiry_date < CURDATE() AND l.type <> 'transit' $scopeSql
              ORDER BY b.expiry_date",
            $params
        );

        $summary = ['risk_30' => 0.0, 'risk_90' => 0.0, 'risk_180' => 0.0, 'risk_365' => 0.0, 'batches_at_risk' => 0,
                    'expired_value' => array_sum(array_map(fn ($e) => (float) $e['value'], $expired)), 'expired_batches' => count($expired)];
        foreach ($rows as $r) {
            if ($r['at_risk'] <= 0) {
                continue;
            }
            $summary['batches_at_risk']++;
            foreach ([30, 90, 180, 365] as $w) {
                if ((int) $r['days_to_expiry'] <= $w) {
                    $summary["risk_$w"] += $r['value_at_risk'];
                }
            }
        }
        return ['rows' => $rows, 'expired' => $expired, 'summary' => $summary];
    }

    /**
     * Usage rate per location and product over COMPLETED days (up to yesterday):
     * weighted 60 % last 30 days + 40 % last 90 days of outflow (consumption, issues, transfers,
     * returns). Locations active for less than 90 days use their average over the active period,
     * so a new location's rate is not diluted.
     */
    public function locationRates(): array
    {
        $first = [];
        foreach ($this->db->fetchAll(
            'SELECT to_location_id AS loc, product_id, MIN(created_at) AS first FROM stock_movements
              WHERE to_location_id IS NOT NULL GROUP BY to_location_id, product_id'
        ) as $r) {
            $first[(int) $r['loc']][(int) $r['product_id']] = $r['first'];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT from_location_id AS loc, product_id,
                    SUM(CASE WHEN created_at >= CURDATE() - INTERVAL 30 DAY THEN quantity ELSE 0 END) AS q30, SUM(quantity) AS q90
               FROM stock_movements
              WHERE from_location_id IS NOT NULL AND movement_type IN ('CONSUMED','ISSUED','TRANSFERRED','RETURNED')
                AND created_at >= CURDATE() - INTERVAL 90 DAY AND created_at < CURDATE()
              GROUP BY from_location_id, product_id"
        ) as $r) {
            $loc = (int) $r['loc'];
            $pid = (int) $r['product_id'];
            $f = $first[$loc][$pid] ?? null;
            $active = $f ? (int) floor((strtotime(date('Y-m-d')) - strtotime(substr($f, 0, 10))) / 86400) : 90;
            $out[$loc][$pid] = $active >= 90
                ? 0.6 * (float) $r['q30'] / 30 + 0.4 * (float) $r['q90'] / 90
                : (float) $r['q90'] / max(1, $active);
        }
        return $out;
    }

    /** Consumption rate per product per (non-central) department. */
    public function departmentRates(): array
    {
        $out = [];
        $names = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM departments WHERE is_central_store = 0 AND is_active = 1') as $d) {
            $names[(int) $d['id']] = $d['name'];
        }
        $agg = [];
        $cut30 = date('Y-m-d', strtotime('-30 days'));
        foreach ($this->consumption->daily(90, array_keys($names), null, true) as $r) {
            $k = $r['product_id'] . ':' . $r['department_id'];
            $agg[$k]['q90'] = ($agg[$k]['q90'] ?? 0) + (float) $r['qty'];
            $agg[$k]['q30'] = ($agg[$k]['q30'] ?? 0) + ($r['day'] >= $cut30 ? (float) $r['qty'] : 0);
        }
        foreach ($agg as $k => $a) {
            [$pid, $did] = array_map('intval', explode(':', $k));
            $out[$pid][$did] = ['name' => $names[$did], 'rate' => 0.6 * $a['q30'] / 30 + 0.4 * $a['q90'] / 90];
        }
        return $out;
    }

    private function suggest(array $row, array $deptRates, int $ownDept): string
    {
        if ($row['at_risk'] <= 0) {
            return 'Expected to be used before expiry at the current rate.';
        }
        $days = max(1, (int) $row['days_to_expiry']);
        $best = null;
        foreach ($deptRates as $deptId => $d) {
            if ($deptId === $ownDept || $d['rate'] <= 0) {
                continue;
            }
            $canUse = $d['rate'] * $days;
            if ($best === null || $canUse > $best['can_use']) {
                $best = ['name' => $d['name'], 'can_use' => $canUse, 'rate' => $d['rate']];
            }
        }
        $q = fn ($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
        if ($best !== null) {
            return sprintf('Consider moving up to %s to %s (uses about %s/day) — for authorised review.',
                $q(min($row['at_risk'], $best['can_use'])), $best['name'], $q($best['rate']));
        }
        return 'Prioritise this batch for issue; if it still cannot be used, check supplier return options before expiry.';
    }

    private function scopeSql(?array $scope): array
    {
        if ($scope === null) {
            return ['', []];
        }
        [$in, $params] = Database::inClause($scope ?: [0], 'sc');
        return [" AND l.department_id IN $in", $params];
    }
}
