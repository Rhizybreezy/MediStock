<?php
declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Database;
use App\Core\Gate;
use App\Services\Support\CacheService;
use App\Services\Support\SettingsService;

/**
 * Dashboard figures, computed live from MySQL.
 *
 * Department-scoped users (pharmacist, storekeeper) only see figures for their
 * own departments: every query is filtered by Gate::departmentScope('stock.view').
 * Money values are only returned to users with 'valuation.view'.
 */
final class DashboardService
{
    public function __construct(private Database $db, private Gate $gate, private SettingsService $settings, private CacheService $cache)
    {
    }

    /** @return int[]|null null = hospital-wide */
    public function scope(): ?array
    {
        return $this->gate->departmentScope('stock.view');
    }

    public function showValues(): bool
    {
        return $this->gate->can('valuation.view');
    }

    public function summary(): array
    {
        $scope = $this->scope();
        [$locSql, $locParams] = $this->scopeSql('l.department_id', $scope, 'ls');
        $warningDays = $this->settings->int('expiry_warning_days', 90);

        $stock = $this->db->fetchOne(
            "SELECT COALESCE(SUM(sb.quantity), 0) AS qty,
                    COALESCE(SUM(sb.quantity * b.unit_cost), 0) AS value,
                    COUNT(DISTINCT CASE WHEN sb.quantity > 0 THEN sb.batch_id END) AS batches
               FROM stock_balances sb
               JOIN product_batches b     ON b.id = sb.batch_id
               JOIN inventory_locations l ON l.id = sb.location_id
              WHERE l.type <> 'quarantine' $locSql",
            $locParams
        );

        $expiry = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT CASE WHEN b.status = 'active' AND b.expiry_date >= CURDATE()
                                         AND b.expiry_date <= CURDATE() + INTERVAL :wd DAY THEN b.id END) AS expiring,
                    COUNT(DISTINCT CASE WHEN b.expiry_date < CURDATE() THEN b.id END) AS expired
               FROM stock_balances sb
               JOIN product_batches b     ON b.id = sb.batch_id
               JOIN inventory_locations l ON l.id = sb.location_id
              WHERE sb.quantity > 0 $locSql",
            array_merge(['wd' => $warningDays], $locParams)
        );

        $requestScope = '';
        $requestParams = [];
        if ($scope !== null) {
            [$in1, $p1] = Database::inClause($scope, 'rq');
            [$in2, $p2] = Database::inClause($scope, 'rs');
            $requestScope = " AND (requesting_department_id IN $in1 OR supplying_department_id IN $in2)";
            $requestParams = $p1 + $p2;
        }
        $pendingRequests = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM stock_requests WHERE status = 'submitted' $requestScope",
            $requestParams
        );

        $canSeePurchasing = $this->gate->canAny(['purchase_orders.manage', 'purchase_orders.approve', 'goods_receipts.create', 'suppliers.view']);
        $pendingPOs = $canSeePurchasing ? (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending_approval','approved','sent','partially_received')"
        ) : null;

        return [
            'products_total'   => (int) $this->db->fetchValue('SELECT COUNT(*) FROM products WHERE is_active = 1'),
            'stock_qty'        => (float) $stock['qty'],
            'stock_value'      => $this->showValues() ? (float) $stock['value'] : null,
            'batches_in_stock' => (int) $stock['batches'],
            'expiring_soon'    => (int) $expiry['expiring'],
            'expired'          => (int) $expiry['expired'],
            'expiry_days'      => $warningDays,
            'pending_requests' => $pendingRequests,
            'pending_pos'      => $pendingPOs,
        ] + $this->stockLevelCounts($scope);
    }

    /** Low-stock and out-of-stock counts (hospital level or per assigned department). */
    private function stockLevelCounts(?array $scope): array
    {
        if ($scope === null) {
            $row = $this->db->fetchOne(
                'SELECT COALESCE(SUM(usable_qty > 0 AND reorder_level > 0 AND usable_qty <= reorder_level), 0) AS low,
                        COALESCE(SUM(usable_qty <= 0), 0) AS out_of_stock
                   FROM v_product_stock WHERE is_active = 1'
            );
        } else {
            [$in, $params] = Database::inClause($scope, 'sd');
            $row = $this->db->fetchOne(
                "SELECT COALESCE(SUM(COALESCE(ds.usable_qty, 0) > 0 AND COALESCE(ds.usable_qty, 0) <= dps.reorder_level), 0) AS low,
                        COALESCE(SUM(COALESCE(ds.usable_qty, 0) <= 0), 0) AS out_of_stock
                   FROM department_product_settings dps
                   JOIN products p ON p.id = dps.product_id AND p.is_active = 1
                   LEFT JOIN v_department_stock ds ON ds.department_id = dps.department_id AND ds.product_id = dps.product_id
                  WHERE dps.is_stocked = 1 AND dps.department_id IN $in",
                $params
            );
        }
        return ['low_stock' => (int) $row['low'], 'out_of_stock' => (int) $row['out_of_stock']];
    }

    public function recentMovements(int $limit = 8): array
    {
        $scope = $this->scope();
        $limit = max(1, min(50, $limit));
        // Pick the latest movement ids first (index-only), then join details for just those rows.
        if ($scope === null) {
            $inner = "SELECT id FROM stock_movements ORDER BY id DESC LIMIT $limit";
            $params = [];
        } else {
            [$in1, $p1] = Database::inClause($scope ?: [0], 'f');
            [$in2, $p2] = Database::inClause($scope ?: [0], 't');
            $inner = "SELECT m.id FROM stock_movements m
                        LEFT JOIN inventory_locations fl ON fl.id = m.from_location_id
                        LEFT JOIN inventory_locations tl ON tl.id = m.to_location_id
                       WHERE (fl.department_id IN $in1 OR tl.department_id IN $in2)
                       ORDER BY m.id DESC LIMIT $limit";
            $params = $p1 + $p2;
        }
        return $this->db->fetchAll(
            "SELECT m.id, m.movement_type, m.quantity, m.created_at, m.reason,
                    p.name AS product_name, p.product_code, b.batch_code, uom.code AS unit,
                    fl.name AS from_location, tl.name AS to_location, u.full_name AS user_name
               FROM ($inner) latest
               JOIN stock_movements m          ON m.id = latest.id
               JOIN products p                 ON p.id = m.product_id
               JOIN product_batches b          ON b.id = m.batch_id
               JOIN units_of_measure uom       ON uom.id = p.base_unit_id
               LEFT JOIN inventory_locations fl ON fl.id = m.from_location_id
               LEFT JOIN inventory_locations tl ON tl.id = m.to_location_id
               JOIN users u                    ON u.id = m.performed_by
              ORDER BY m.id DESC",
            $params
        );
    }

    public function departmentSummaries(): array
    {
        [$sql, $params] = $this->scopeSql('d.id', $this->scope(), 'ds');
        return $this->db->fetchAll(
            "SELECT d.id, d.code, d.name, d.is_central_store,
                    COUNT(DISTINCT CASE WHEN sb.quantity > 0 THEN sb.product_id END) AS products,
                    COALESCE(SUM(sb.quantity), 0) AS qty,
                    COALESCE(SUM(sb.quantity * b.unit_cost), 0) AS value,
                    (SELECT COUNT(*) FROM stock_requests sr
                      WHERE sr.requesting_department_id = d.id AND sr.status = 'submitted') AS pending_requests
               FROM departments d
               LEFT JOIN inventory_locations l ON l.department_id = d.id AND l.type NOT IN ('transit','quarantine')
               LEFT JOIN stock_balances sb     ON sb.location_id = l.id
               LEFT JOIN product_batches b     ON b.id = sb.batch_id
              WHERE d.is_active = 1 $sql
              GROUP BY d.id
              ORDER BY d.is_central_store DESC, d.name",
            $params
        );
    }

    /** Chart datasets for the dashboard (12 months). */
    public function charts(): array
    {
        $scope = $this->scope();
        return $this->cache->remember('dashboard_charts:' . json_encode($scope) . ':' . (int) $this->showValues(), 600, fn () => $this->computeCharts($scope));
    }

    private function computeCharts(?array $scope): array
    {
        $months = [];
        $start = new \DateTimeImmutable('first day of this month -11 months');
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->modify("+$i months")->format('Y-m');
        }
        $since = $start->format('Y-m-01 00:00:00');

        // Monthly consumption (CONSUMED movements leaving department locations)
        [$fSql, $fParams] = $this->scopeSql('l.department_id', $scope, 'cf');
        $consumption = $this->indexByMonth($this->db->fetchAll(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, SUM(m.quantity) AS qty, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.movement_type = 'CONSUMED' AND m.created_at >= :since $fSql
              GROUP BY ym",
            array_merge(['since' => $since], $fParams)
        ));

        // Stock in (received) vs stock out of the hospital (consumed, damaged, expired, written off, adjusted out)
        [$tSql, $tParams] = $this->scopeSql('l.department_id', $scope, 'tf');
        $inbound = $this->indexByMonth($this->db->fetchAll(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, SUM(m.quantity) AS qty, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.to_location_id
              WHERE m.movement_type IN ('RECEIVED','ADJUSTED_IN') AND m.from_location_id IS NULL AND m.created_at >= :since $tSql
              GROUP BY ym",
            array_merge(['since' => $since], $tParams)
        ));
        $outbound = $this->indexByMonth($this->db->fetchAll(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, SUM(m.quantity) AS qty, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.to_location_id IS NULL AND m.created_at >= :since $fSql
              GROUP BY ym",
            array_merge(['since' => $since], $fParams)
        ));

        // Current stock by category
        [$cSql, $cParams] = $this->scopeSql('l.department_id', $scope, 'cc');
        $byCategory = $this->db->fetchAll(
            "SELECT c.name, SUM(sb.quantity) AS qty, SUM(sb.quantity * b.unit_cost) AS value
               FROM stock_balances sb
               JOIN product_batches b      ON b.id = sb.batch_id
               JOIN products p             ON p.id = sb.product_id
               JOIN inventory_categories c ON c.id = p.category_id
               JOIN inventory_locations l  ON l.id = sb.location_id
              WHERE sb.quantity > 0 AND l.type <> 'quarantine' $cSql
              GROUP BY c.id ORDER BY value DESC",
            $cParams
        );

        $useValue = $this->showValues();
        $metric = $useValue ? 'value' : 'qty';
        $series = fn (array $indexed) => array_map(fn ($m) => round((float) ($indexed[$m][$metric] ?? 0), 2), $months);

        return [
            'metric'      => $metric,
            'labels'      => array_map(fn ($m) => date('M Y', strtotime($m . '-01')), $months),
            'consumption' => $series($consumption),
            'inbound'     => $series($inbound),
            'outbound'    => $series($outbound),
            'categories'  => [
                'labels' => array_column($byCategory, 'name'),
                'data'   => array_map(fn ($r) => round((float) $r[$metric], 2), $byCategory),
            ],
        ];
    }

    private function indexByMonth(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['ym']] = $r;
        }
        return $out;
    }

    /** "AND col IN (...)" fragment for a department scope (empty for hospital-wide users). */
    private function scopeSql(string $column, ?array $scope, string $prefix): array
    {
        if ($scope === null) {
            return ['', []];
        }
        [$in, $params] = Database::inClause($scope, $prefix);
        return [" AND $column IN $in", $params];
    }
}
