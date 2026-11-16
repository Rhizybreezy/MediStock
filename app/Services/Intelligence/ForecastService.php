<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;
use App\Services\Support\CacheService;
use App\Services\Support\SettingsService;

/**
 * Stock-out prediction and reorder recommendations.
 *
 * Hospital level (departmentId = null): usable stock across the hospital vs. total consumption,
 *   supplier lead time, open purchase orders. Reorder quantities become purchase requests.
 * Department level: the department's usable stock vs. its own consumption, internal supply
 *   time, stock in transit / approved requests. Reorder quantities become stock requests.
 *
 * Every row carries its assumptions so estimates are never presented as certainties.
 */
final class ForecastService
{
    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private ConsumptionQuery $consumption,
        private CacheService $cache,
    ) {
    }

    public function forecast(?int $departmentId = null, ?int $productId = null): array
    {
        if ($productId !== null) {
            return $this->compute($departmentId, $productId);
        }
        // Cached for 10 minutes; any stock/PO/level/setting change invalidates it.
        return $this->cache->remember('forecast:' . ($departmentId ?? 'all'), 600, fn () => $this->compute($departmentId, null));
    }

    private function compute(?int $departmentId, ?int $productId): array
    {
        $today = date('Y-m-d');
        // Usage windows end YESTERDAY: today's figures are incomplete and would understate demand.
        $asOf = date('Y-m-d', strtotime('-1 day'));
        $review = $this->settings->int('review_period_days', 14);
        $safetyDays = $this->settings->int('safety_stock_days', 7);
        $defaultLead = $this->settings->int('default_lead_time_days', 14);
        $internalLead = $this->settings->int('internal_lead_time_days', 2);
        $productFilter = $productId ? ' AND p.id = ' . (int) $productId : '';

        if ($departmentId === null) {
            $products = $this->db->fetchAll(
                "SELECT p.id, p.product_code, p.name, p.is_critical, p.reorder_level, p.min_level, p.max_level, p.default_lead_time_days,
                        u.code AS unit_code, COALESCE(v.usable_qty, 0) AS usable,
                        (SELECT COALESCE(sp.lead_time_days, s.default_lead_time_days) FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
                          WHERE sp.product_id = p.id AND sp.is_preferred = 1 LIMIT 1) AS supplier_lead,
                        (SELECT pu.factor_to_base FROM product_units pu WHERE pu.product_id = p.id AND pu.is_purchase_unit = 1 ORDER BY pu.factor_to_base LIMIT 1) AS pack
                   FROM products p JOIN units_of_measure u ON u.id = p.base_unit_id
                   LEFT JOIN v_product_stock v ON v.product_id = p.id
                  WHERE p.is_active = 1 $productFilter"
            );
            $onOrder = $this->indexBy($this->db->fetchAll(
                "SELECT poi.product_id, SUM(poi.ordered_qty_base - poi.received_qty_base) AS qty, MIN(po.expected_delivery_date) AS expected
                   FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                  WHERE po.status IN ('approved','sent','partially_received') AND poi.ordered_qty_base > poi.received_qty_base
                  GROUP BY poi.product_id"
            ));
            $series = $this->seriesFrom($this->consumption->daily(365, null, $productId));
            // Proxy demand for products whose departments do not record consumption: stock arriving in departments.
            $proxySeries = $this->series(
                "SELECT m.product_id, DATE(m.created_at) AS day, SUM(m.quantity) AS qty
                   FROM stock_movements m JOIN inventory_locations fl ON fl.id = m.from_location_id AND fl.type = 'transit'
                   JOIN inventory_locations tl ON tl.id = m.to_location_id JOIN departments d ON d.id = tl.department_id AND d.is_central_store = 0
                  WHERE m.created_at > CURDATE() - INTERVAL 365 DAY GROUP BY m.product_id, DATE(m.created_at)",
                []
            );
            $firstSeen = $this->indexBy($this->db->fetchAll('SELECT product_id, MIN(created_at) AS first FROM stock_movements GROUP BY product_id'));
        } else {
            $products = $this->db->fetchAll(
                "SELECT p.id, p.product_code, p.name, p.is_critical, COALESCE(s.reorder_level, 0) AS reorder_level, COALESCE(s.min_level, 0) AS min_level,
                        COALESCE(s.max_level, 0) AS max_level, NULL AS default_lead_time_days, NULL AS supplier_lead, NULL AS pack,
                        u.code AS unit_code, COALESCE(ds.usable_qty, 0) AS usable
                   FROM products p JOIN units_of_measure u ON u.id = p.base_unit_id
                   LEFT JOIN department_product_settings s ON s.product_id = p.id AND s.department_id = :d1
                   LEFT JOIN v_department_stock ds ON ds.product_id = p.id AND ds.department_id = :d2
                  WHERE p.is_active = 1 AND (s.id IS NOT NULL OR ds.product_id IS NOT NULL
                        OR EXISTS (SELECT 1 FROM stock_movements c JOIN inventory_locations cl ON cl.id = c.from_location_id
                                    WHERE c.product_id = p.id AND c.movement_type = 'CONSUMED' AND cl.department_id = :d3 AND c.created_at >= CURDATE() - INTERVAL 365 DAY)) $productFilter",
                ['d1' => $departmentId, 'd2' => $departmentId, 'd3' => $departmentId]
            );
            $onOrder = $this->indexBy($this->db->fetchAll(
                "SELECT x.product_id, SUM(x.qty) AS qty, NULL AS expected FROM (
                    SELECT i.product_id, i.dispatched_qty - i.received_qty - i.returned_qty AS qty
                      FROM stock_transfer_items i JOIN stock_transfers t ON t.id = i.stock_transfer_id
                     WHERE t.destination_department_id = :d1 AND t.status IN ('dispatched','partially_received')
                    UNION ALL
                    SELECT ri.product_id, COALESCE(ri.approved_qty, 0) - ri.issued_qty FROM stock_request_items ri JOIN stock_requests r ON r.id = ri.stock_request_id
                     WHERE r.requesting_department_id = :d2 AND r.status IN ('approved','partially_approved','partially_issued')
                 ) x GROUP BY x.product_id",
                ['d1' => $departmentId, 'd2' => $departmentId]
            ));
            $series = $this->seriesFrom($this->consumption->daily(365, [$departmentId], $productId));
            $proxySeries = [];
            $firstSeen = $this->indexBy($this->db->fetchAll(
                'SELECT m.product_id, MIN(m.created_at) AS first FROM stock_movements m JOIN inventory_locations l ON l.id = m.to_location_id
                  WHERE l.department_id = :d GROUP BY m.product_id',
                ['d' => $departmentId]
            ));
        }

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $first = $firstSeen[$pid]['first'] ?? null;
            $historyDays = $first ? (int) min(365, max(0, floor((strtotime($asOf) - strtotime(substr($first, 0, 10))) / 86400) + 1)) : 0;
            $daily = $series[$pid] ?? [];
            $proxy = false;
            if ($departmentId === null && !$this->hasRecent($daily, $asOf, 90) && $this->hasRecent($proxySeries[$pid] ?? [], $asOf, 90)) {
                $daily = $proxySeries[$pid];
                $proxy = true;
            }
            $c = ForecastMath::consumption($daily, $asOf, $historyDays);
            $seasonal = ForecastMath::seasonalFactor($daily, $today, $historyDays);
            $adc = $c['adc'] !== null ? $c['adc'] * ($seasonal ?? 1) : null;

            if ($departmentId === null) {
                [$lead, $leadSource] = match (true) {
                    $p['supplier_lead'] !== null => [(int) $p['supplier_lead'], 'preferred supplier'],
                    $p['default_lead_time_days'] !== null => [(int) $p['default_lead_time_days'], 'product setting'],
                    default => [$defaultLead, 'system default'],
                };
            } else {
                [$lead, $leadSource] = [$internalLead, 'internal supply time'];
            }
            $usable = (float) $p['usable'];
            $ordered = (float) ($onOrder[$pid]['qty'] ?? 0);
            $expected = $onOrder[$pid]['expected'] ?? null;

            $safety = 0.0;
            $safetyMethod = '—';
            $reorderPoint = (float) $p['reorder_level'];
            $daysRemaining = null;
            $stockoutDate = null;
            $reorderQty = 0.0;
            $poInTime = false;
            if ($adc !== null && $adc > 0) {
                [$safety, $safetyMethod] = ForecastMath::safetyStock($adc, $c['sigma'], $lead, $historyDays, $safetyDays);
                $reorderPoint = $adc * $lead + $safety;
                $daysRemaining = $usable / $adc;
                $stockoutDate = date('Y-m-d', strtotime($today . ' +' . (int) floor($daysRemaining) . ' days'));
                $poInTime = $ordered > 0 && ($expected === null || $expected <= $stockoutDate);
                $reorderQty = ForecastMath::reorderQuantity($adc, $lead, $review, $safety, $usable, $ordered, (float) $p['max_level'], (float) ($p['pack'] ?: 1));
            }
            $urgency = ForecastMath::urgency($usable, $adc, $daysRemaining, $lead, $reorderPoint, $review, $poInTime);
            // Without a demand estimate, fall back to the configured reorder level.
            if (in_array($urgency, ['insufficient', 'no_demand'], true) && (float) $p['reorder_level'] > 0 && $usable <= (float) $p['reorder_level']) {
                $urgency = $usable <= 0 ? 'critical' : 'high';
            }

            $assumptions = [
                'Usage rate: ' . ($adc !== null ? round($adc, 2) . ' per day — ' . $c['basis'] : $c['basis']) . ($proxy ? ' (estimated from stock issued to departments, because departments have not recorded consumption)' : ''),
                $seasonal !== null ? 'Seasonal adjustment ×' . round($seasonal, 2) . ' for this month (based on last year)' : 'No seasonal adjustment (needs 12 months of history)',
                "Lead time: $lead days ($leadSource)",
                'Safety stock: ' . round($safety, 1) . " — $safetyMethod",
                $ordered > 0 ? 'On order: ' . round($ordered, 1) . ($expected ? " (expected by $expected)" : '') : 'Nothing on order',
                'Assumes usage continues at the current rate; actual demand can differ.',
            ];
            $rows[] = [
                'product_id' => $pid, 'product_code' => $p['product_code'], 'name' => $p['name'], 'unit_code' => $p['unit_code'],
                'is_critical' => (int) $p['is_critical'], 'usable' => $usable, 'on_order' => $ordered, 'expected' => $expected,
                'adc' => $adc, 'sigma' => $c['sigma'], 'history_days' => $historyDays, 'confidence' => $c['confidence'], 'proxy' => $proxy,
                'seasonal' => $seasonal, 'lead_time' => $lead, 'lead_source' => $leadSource, 'safety_stock' => $safety,
                'reorder_point' => $reorderPoint, 'reorder_level' => (float) $p['reorder_level'], 'max_level' => (float) $p['max_level'],
                'days_remaining' => $daysRemaining, 'stockout_date' => $stockoutDate, 'po_in_time' => $poInTime,
                'reorder_qty' => $reorderQty, 'urgency' => $urgency, 'assumptions' => $assumptions,
            ];
        }
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'ok' => 3, 'insufficient' => 4, 'no_demand' => 5];
        usort($rows, fn ($a, $b) => [$rank[$a['urgency']], $a['days_remaining'] ?? PHP_INT_MAX, $a['name']] <=> [$rank[$b['urgency']], $b['days_remaining'] ?? PHP_INT_MAX, $b['name']]);
        return $rows;
    }

    /** Weekly consumption for charts (last N weeks). */
    public function weeklyConsumption(int $productId, ?int $departmentId, int $weeks = 26): array
    {
        $rows = [];
        foreach ($this->consumption->daily($weeks * 7, $departmentId !== null ? [$departmentId] : null, $productId) as $r) {
            $yw = (int) date('oW', strtotime($r['day']));
            $rows[$yw]['qty'] = ($rows[$yw]['qty'] ?? 0) + (float) $r['qty'];
        }
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i weeks");
            $yw = (int) date('oW', $ts);
            $out[] = ['label' => date('d M', strtotime('monday this week', $ts)), 'qty' => (float) ($rows[$yw]['qty'] ?? 0)];
        }
        return $out;
    }

    private function seriesFrom(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']][(string) $r['day']] = ($out[(int) $r['product_id']][(string) $r['day']] ?? 0) + (float) $r['qty'];
        }
        return $out;
    }

    private function series(string $sql, array $params): array
    {
        $out = [];
        foreach ($this->db->fetchAll($sql, $params) as $r) {
            $out[(int) $r['product_id']][(string) $r['day']] = ($out[(int) $r['product_id']][(string) $r['day']] ?? 0) + (float) $r['qty'];
        }
        return $out;
    }

    private function hasRecent(array $daily, string $today, int $days): bool
    {
        $from = date('Y-m-d', strtotime("$today -$days days"));
        foreach ($daily as $d => $q) {
            if ($d > $from && $q > 0) {
                return true;
            }
        }
        return false;
    }

    private function indexBy(array $rows, string $key = 'product_id'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r[$key]] = $r;
        }
        return $out;
    }
}
