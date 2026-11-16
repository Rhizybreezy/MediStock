<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;
use App\Services\Support\CacheService;
use App\Services\Support\SettingsService;

/**
 * Flags patterns worth reviewing (not accusations):
 *  - consumption spikes: a day in the last 14 days above mean + kσ of the previous 90 days (and at least 2× the mean)
 *  - repeated losses: ≥ 3 posted negative adjustments for the same product/location in 30 days
 *  - frequent adjusters: ≥ 5 negative adjustments requested by the same person in 30 days
 *  - count discrepancies: approved counts in 30 days with variance > 5 % (and > 1 unit)
 *  - stock stuck in transit beyond the configured hours
 *  - loss surge: value lost (damaged/expired/written off) in the last 30 days > 2× the previous 90-day monthly average
 */
final class AnomalyService
{
    public function __construct(private Database $db, private SettingsService $settings, private CacheService $cache, private ConsumptionQuery $consumption)
    {
    }

    public function detect(?array $scope = null): array
    {
        return $this->cache->remember('anomalies:' . json_encode($scope), 600, fn () => $this->compute($scope));
    }

    private function compute(?array $scope): array
    {
        $k = (float) $this->settings->get('anomaly_sigma', 3);
        [$deptSql, $deptParams] = $this->scope($scope, 'd.id');
        $out = [];

        // 1. Consumption spikes
        $pnames = array_column($this->db->fetchAll('SELECT id, name FROM products'), 'name', 'id');
        $dnames = array_column($this->db->fetchAll('SELECT id, name FROM departments'), 'name', 'id');
        $rows = array_map(fn ($r) => $r + ['product_name' => $pnames[$r['product_id']] ?? '', 'department_name' => $dnames[$r['department_id']] ?? ''],
            $this->consumption->daily(104, $scope));
        $series = [];
        foreach ($rows as $r) {
            $series[$r['product_id'] . ':' . $r['department_id']]['meta'] = $r;
            $series[$r['product_id'] . ':' . $r['department_id']]['days'][$r['day']] = (float) $r['qty'];
        }
        $recentFrom = date('Y-m-d', strtotime('-14 days'));
        foreach ($series as $s) {
            $base = [];
            for ($i = 15; $i < 105; $i++) {
                $base[] = $s['days'][date('Y-m-d', strtotime("-$i days"))] ?? 0.0;
            }
            if (count(array_filter($base)) < 20) {
                continue; // not enough baseline activity
            }
            $mean = array_sum($base) / count($base);
            $var = 0.0;
            foreach ($base as $v) {
                $var += ($v - $mean) ** 2;
            }
            $sigma = sqrt($var / (count($base) - 1));
            foreach ($s['days'] as $day => $q) {
                if ($day >= $recentFrom && $q > $mean + $k * $sigma && $q >= 2 * $mean && $mean > 0) {
                    $out[] = [
                        'type' => 'consumption_spike', 'severity' => $q > $mean + 2 * $k * $sigma ? 'critical' : 'warning',
                        'title' => "Unusual consumption: {$s['meta']['product_name']}",
                        'detail' => sprintf('%s used %s on %s; normal is about %s/day (σ %s).', $s['meta']['department_name'], qty($q), format_date($day), qty(round($mean, 1)), qty(round($sigma, 1))),
                        'date' => $day, 'link' => '/inventory/movements?type=CONSUMED&product_id=' . $s['meta']['product_id'] . '&department_id=' . $s['meta']['department_id'],
                        'key' => "spike:{$s['meta']['product_id']}:{$s['meta']['department_id']}:$day",
                    ];
                }
            }
        }

        // 2. Repeated losses at a location
        [$lSql, $lParams] = $this->scope($scope, 'l.department_id');
        foreach ($this->db->fetchAll(
            "SELECT a.location_id, b.product_id, p.name AS product_name, l.name AS location_name, COUNT(DISTINCT a.id) AS n, SUM(-i.quantity_change) AS qty
               FROM stock_adjustments a JOIN stock_adjustment_items i ON i.stock_adjustment_id = a.id
               JOIN product_batches b ON b.id = i.batch_id JOIN products p ON p.id = b.product_id JOIN inventory_locations l ON l.id = a.location_id
              WHERE a.status = 'posted' AND i.quantity_change < 0 AND a.approved_at >= NOW() - INTERVAL 30 DAY $lSql
              GROUP BY a.location_id, b.product_id HAVING n >= 3",
            $lParams
        ) as $r) {
            $out[] = ['type' => 'repeated_losses', 'severity' => 'warning', 'title' => "Repeated losses: {$r['product_name']}",
                'detail' => sprintf('%d negative adjustments at %s in 30 days (%s units).', $r['n'], $r['location_name'], qty($r['qty'])),
                'date' => date('Y-m-d'), 'link' => '/adjustments?status=posted', 'key' => "losses:{$r['location_id']}:{$r['product_id']}:" . date('Y-W')];
        }

        // 3. Frequent adjusters
        foreach ($this->db->fetchAll(
            "SELECT a.requested_by, u.full_name, COUNT(DISTINCT a.id) AS n
               FROM stock_adjustments a JOIN stock_adjustment_items i ON i.stock_adjustment_id = a.id
               JOIN users u ON u.id = a.requested_by JOIN inventory_locations l ON l.id = a.location_id
              WHERE i.quantity_change < 0 AND a.reason_code <> 'count_variance' AND a.created_at >= NOW() - INTERVAL 30 DAY $lSql
              GROUP BY a.requested_by HAVING n >= 5",
            $lParams
        ) as $r) {
            $out[] = ['type' => 'frequent_adjustments', 'severity' => 'info', 'title' => 'Many negative adjustments by one person',
                'detail' => sprintf('%s requested %d negative adjustments in 30 days.', $r['full_name'], $r['n']),
                'date' => date('Y-m-d'), 'link' => '/adjustments', 'key' => "adjuster:{$r['requested_by']}:" . date('Y-W')];
        }

        // 4. Count discrepancies
        foreach ($this->db->fetchAll(
            "SELECT c.id, c.count_number, l.name AS location_name, p.name AS product_name, i.system_qty, i.counted_qty, i.variance
               FROM stock_counts c JOIN stock_count_items i ON i.stock_count_id = c.id JOIN inventory_locations l ON l.id = c.location_id
               JOIN product_batches b ON b.id = i.batch_id JOIN products p ON p.id = b.product_id
              WHERE c.status = 'approved' AND c.approved_at >= NOW() - INTERVAL 30 DAY
                AND ABS(i.variance) > GREATEST(1, 0.05 * i.system_qty) $lSql",
            $lParams
        ) as $r) {
            $out[] = ['type' => 'count_discrepancy', 'severity' => (float) $r['variance'] < 0 ? 'warning' : 'info', 'title' => "Count discrepancy: {$r['product_name']}",
                'detail' => sprintf('%s at %s: system %s, counted %s (%s).', $r['count_number'], $r['location_name'], qty($r['system_qty']), qty($r['counted_qty']), ((float) $r['variance'] > 0 ? '+' : '') . qty($r['variance'])),
                'date' => date('Y-m-d'), 'link' => '/counts/' . $r['id'], 'key' => "count:{$r['id']}:{$r['product_name']}"];
        }

        // 5. Stuck in transit
        $hours = $this->settings->int('transit_alert_hours', 48);
        [$tSql, $tParams] = $this->scope($scope, 't.destination_department_id');
        foreach ($this->db->fetchAll(
            "SELECT t.id, t.transfer_number, t.dispatched_at, d.name AS destination, t.destination_department_id
               FROM stock_transfers t JOIN departments d ON d.id = t.destination_department_id
              WHERE t.status IN ('dispatched','partially_received') AND t.dispatched_at < NOW() - INTERVAL :h HOUR $tSql",
            array_merge(['h' => $hours], $tParams)
        ) as $r) {
            $out[] = ['type' => 'transit_overdue', 'severity' => 'warning', 'title' => "Not yet received: {$r['transfer_number']}",
                'detail' => sprintf('Dispatched to %s %s and still in transit (limit %d h).', $r['destination'], time_ago($r['dispatched_at']), $hours),
                'date' => substr($r['dispatched_at'], 0, 10), 'link' => '/inventory/issues/' . $r['id'], 'key' => "transit:{$r['id']}", 'department_id' => (int) $r['destination_department_id']];
        }

        // 6. Loss surge
        [$mSql, $mParams] = $this->scope($scope, 'l.department_id');
        $loss = $this->db->fetchOne(
            "SELECT SUM(CASE WHEN m.created_at >= NOW() - INTERVAL 30 DAY THEN m.quantity * m.unit_cost ELSE 0 END) AS recent,
                    SUM(CASE WHEN m.created_at < NOW() - INTERVAL 30 DAY THEN m.quantity * m.unit_cost ELSE 0 END) / 3 AS monthly_avg
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.movement_type IN ('DAMAGED','EXPIRED','WRITTEN_OFF') AND m.created_at >= NOW() - INTERVAL 120 DAY $mSql",
            $mParams
        );
        if ((float) $loss['monthly_avg'] > 0 && (float) $loss['recent'] > 2 * (float) $loss['monthly_avg']) {
            $out[] = ['type' => 'loss_surge', 'severity' => 'warning', 'title' => 'Stock losses higher than usual',
                'detail' => sprintf('Losses in the last 30 days: %s vs. a monthly average of %s.', money($loss['recent']), money($loss['monthly_avg'])),
                'date' => date('Y-m-d'), 'link' => '/inventory/movements?type=WRITTEN_OFF', 'key' => 'loss_surge:' . date('Y-m')];
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => [$rank[$a['severity']], $b['date']] <=> [$rank[$b['severity']], $a['date']]);
        return $out;
    }

    private function scope(?array $scope, string $column): array
    {
        if ($scope === null) {
            return ['', []];
        }
        [$in, $p] = Database::inClause($scope ?: [0], 'an' . substr(md5($column), 0, 4));
        return [" AND $column IN $in", $p];
    }
}
