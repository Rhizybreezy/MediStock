<?php
declare(strict_types=1);

namespace App\Services\Reporting;

use App\Core\Database;
use App\Core\Gate;
use App\Services\Intelligence\ExpiryService;
use App\Services\Intelligence\ForecastService;
use App\Services\Procurement\SupplierPerformanceService;

/**
 * Analytics datasets. Each report returns:
 *   ['title', 'description', 'columns' => [key => ['label', 'type' => text|qty|money|pct|date|int]],
 *    'rows', 'totals' (optional), 'chart' (optional: type, labels, series)]
 * Money columns are removed for users without valuation.view. Department-scoped
 * users only ever receive their own departments' data (filtered in SQL).
 */
final class ReportService
{
    public const REPORTS = [
        'valuation'   => ['Inventory valuation', 'bi-cash-stack'],
        'consumption' => ['Consumption', 'bi-clipboard-pulse'],
        'departments' => ['Department comparison', 'bi-building'],
        'purchasing'  => ['Purchasing expenditure', 'bi-receipt'],
        'suppliers'   => ['Supplier performance', 'bi-truck-front'],
        'wastage'     => ['Expiry & wastage', 'bi-trash3'],
        'stock_risk'  => ['Low stock & stock-out risk', 'bi-graph-down-arrow'],
        'slow_moving' => ['Slow-moving inventory', 'bi-hourglass'],
        'movements'   => ['Stock movement history', 'bi-arrow-left-right'],
    ];

    public function __construct(
        private Database $db,
        private Gate $gate,
        private ForecastService $forecast,
        private ExpiryService $expiry,
        private SupplierPerformanceService $suppliers,
    ) {
    }

    /** Effective department scope: user scope narrowed by the department filter. */
    public function scope(?int $departmentId): ?array
    {
        $scope = $this->gate->departmentScope('stock.view');
        if ($departmentId) {
            return ($scope === null || in_array($departmentId, $scope, true)) ? [$departmentId] : [];
        }
        return $scope;
    }

    public function run(string $key, array $f): array
    {
        $method = 'report' . str_replace('_', '', ucwords($key, '_'));
        if (!isset(self::REPORTS[$key]) || !method_exists($this, $method)) {
            throw new \App\Exceptions\HttpException(404, 'Unknown report.');
        }
        $report = $this->$method($f) + ['totals' => null, 'chart' => null, 'notes' => []];
        if (!$this->gate->can('valuation.view')) {
            $report['columns'] = array_filter($report['columns'], fn ($c) => ($c['type'] ?? '') !== 'money');
            $report['chart'] = ($report['chart']['money'] ?? false) ? null : $report['chart'];
        }
        return $report;
    }

    // ---------------------------------------------------------------- helpers

    private function inScope(?array $scope, string $column, string $prefix): array
    {
        if ($scope === null) {
            return ['', []];
        }
        [$in, $p] = Database::inClause($scope ?: [0], $prefix);
        return [" AND $column IN $in", $p];
    }

    private function catFilter(array $f, string $column = 'p.category_id'): array
    {
        return !empty($f['category_id']) ? [" AND $column = :cat", ['cat' => (int) $f['category_id']]] : ['', []];
    }

    private function range(array $f): array
    {
        return ['from' => $f['from'] . ' 00:00:00', 'to' => $f['to'] . ' 23:59:59'];
    }

    // ---------------------------------------------------------------- reports

    private function reportValuation(array $f): array
    {
        [$s, $sp] = $this->inScope($this->scope($f['department_id']), 'l.department_id', 'sc');
        [$c, $cp] = $this->catFilter($f);
        $rows = $this->db->fetchAll(
            "SELECT p.product_code, p.name AS product, cat.name AS category, u.code AS unit,
                    SUM(sb.quantity) AS on_hand,
                    SUM(CASE WHEN b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) AND l.type NOT IN ('quarantine','transit') THEN sb.quantity ELSE 0 END) AS usable,
                    SUM(sb.quantity * b.unit_cost) AS value,
                    SUM(CASE WHEN b.expiry_date < CURDATE() OR b.status IN ('recalled','quarantined','expired') THEN sb.quantity * b.unit_cost ELSE 0 END) AS unusable_value
               FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id JOIN products p ON p.id = sb.product_id
               JOIN inventory_categories cat ON cat.id = p.category_id JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN inventory_locations l ON l.id = sb.location_id
              WHERE sb.quantity > 0 $s $c GROUP BY p.id ORDER BY value DESC",
            $sp + $cp
        );
        $total = array_sum(array_column($rows, 'value'));
        foreach ($rows as &$r) {
            $r['share'] = $total > 0 ? (float) $r['value'] / $total : 0;
        }
        unset($r);
        $byCat = [];
        foreach ($rows as $r) {
            $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float) $r['value'];
        }
        arsort($byCat);
        return [
            'title' => 'Inventory valuation', 'description' => 'Current stock at batch purchase cost (as of now). Unusable = expired, recalled or quarantined.',
            'columns' => ['product_code' => ['label' => 'Code'], 'product' => ['label' => 'Product'], 'category' => ['label' => 'Category'], 'unit' => ['label' => 'Unit'],
                'on_hand' => ['label' => 'On hand', 'type' => 'qty'], 'usable' => ['label' => 'Usable', 'type' => 'qty'],
                'value' => ['label' => 'Value', 'type' => 'money'], 'unusable_value' => ['label' => 'Unusable value', 'type' => 'money'], 'share' => ['label' => 'Share', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['product' => count($rows) . ' products', 'value' => $total, 'unusable_value' => array_sum(array_column($rows, 'unusable_value')), 'share' => 1],
            'chart' => ['type' => 'doughnut', 'money' => true, 'labels' => array_keys($byCat), 'series' => [['label' => 'Value', 'data' => array_values($byCat)]]],
            'dated' => false,
        ];
    }

    private function reportConsumption(array $f): array
    {
        [$s, $sp] = $this->inScope($this->scope($f['department_id']), 'l.department_id', 'sc');
        [$c, $cp] = $this->catFilter($f);
        $group = in_array($f['group'] ?? '', ['department', 'product', 'category', 'month'], true) ? $f['group'] : 'department';
        [$sel, $grp, $label] = match ($group) {
            'product'  => ['p.name', 'p.id', 'Product'],
            'category' => ['cat.name', 'cat.id', 'Category'],
            'month'    => ["DATE_FORMAT(m.created_at, '%Y-%m')", "DATE_FORMAT(m.created_at, '%Y-%m')", 'Month'],
            default    => ['d.name', 'd.id', 'Department'],
        };
        $days = max(1, (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1);
        $prevFrom = date('Y-m-d', strtotime($f['from'] . " -$days days")) . ' 00:00:00';
        $sql = "SELECT $sel AS name,
                       SUM(CASE WHEN m.created_at BETWEEN :from AND :to THEN m.quantity ELSE 0 END) AS qty,
                       SUM(CASE WHEN m.created_at BETWEEN :from2 AND :to2 THEN m.quantity * m.unit_cost ELSE 0 END) AS value,
                       SUM(CASE WHEN m.created_at >= :pfrom AND m.created_at < :pto THEN m.quantity * m.unit_cost ELSE 0 END) AS prev_value,
                       COUNT(DISTINCT CASE WHEN m.created_at BETWEEN :from3 AND :to3 THEN m.transaction_id END) AS records
                  FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
                  JOIN departments d ON d.id = l.department_id JOIN products p ON p.id = m.product_id
                  JOIN inventory_categories cat ON cat.id = p.category_id
                 WHERE m.movement_type = 'CONSUMED' AND m.created_at >= :pfrom2 AND m.created_at <= :to4 $s $c
                 GROUP BY $grp HAVING value > 0 OR prev_value > 0 ORDER BY " . ($group === 'month' ? 'name' : 'value DESC');
        $r = $this->range($f);
        $rows = $this->db->fetchAll($sql, [
            'from' => $r['from'], 'to' => $r['to'], 'from2' => $r['from'], 'to2' => $r['to'], 'from3' => $r['from'], 'to3' => $r['to'], 'to4' => $r['to'],
            'pfrom' => $prevFrom, 'pto' => $r['from'], 'pfrom2' => $prevFrom,
        ] + $sp + $cp);
        if ($group === 'month') {
            $rows = array_values(array_filter($rows, fn ($x) => (float) $x['value'] > 0));
        }
        foreach ($rows as &$x) {
            $x['change'] = (float) $x['prev_value'] > 0 ? ((float) $x['value'] - (float) $x['prev_value']) / (float) $x['prev_value'] : null;
            $x['per_day'] = (float) $x['qty'] / $days;
        }
        unset($x);
        $columns = ['name' => ['label' => $label], 'records' => ['label' => 'Records', 'type' => 'int']];
        if ($group === 'product') {
            $columns += ['qty' => ['label' => 'Quantity', 'type' => 'qty'], 'per_day' => ['label' => 'Per day', 'type' => 'qty']];
        }
        $columns += ['value' => ['label' => 'Value', 'type' => 'money'], 'prev_value' => ['label' => 'Previous period', 'type' => 'money'], 'change' => ['label' => 'Change', 'type' => 'pct_change']];
        $top = $group === 'month' ? $rows : array_slice($rows, 0, 12);
        return [
            'title' => 'Consumption by ' . strtolower($label), 'description' => "Stock recorded as used in departments, {$f['from']} to {$f['to']} ($days days), compared with the previous $days days.",
            'columns' => $columns, 'rows' => $rows,
            'totals' => ['name' => 'Total', 'records' => array_sum(array_column($rows, 'records')), 'value' => array_sum(array_column($rows, 'value')), 'prev_value' => array_sum(array_column($rows, 'prev_value'))]
                + ($group === 'product' ? ['qty' => null] : []),
            'chart' => ['type' => $group === 'month' ? 'line' : 'bar', 'money' => true, 'labels' => array_column($top, 'name'),
                'series' => [['label' => 'Value', 'data' => array_map('floatval', array_column($top, 'value'))]] + ($group === 'month' ? [] : [1 => ['label' => 'Previous period', 'data' => array_map('floatval', array_column($top, 'prev_value'))]])],
            'dated' => true, 'groups' => ['department' => 'Department', 'product' => 'Product', 'category' => 'Category', 'month' => 'Month'], 'group' => $group,
        ];
    }

    private function reportDepartments(array $f): array
    {
        [$s, $sp] = $this->inScope($this->scope($f['department_id']), 'd.id', 'sc');
        $r = $this->range($f);
        $cats = $this->db->fetchAll('SELECT id, name FROM inventory_categories WHERE is_active = 1 ORDER BY name');
        $data = $this->db->fetchAll(
            "SELECT d.id, d.name, p.category_id, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id JOIN departments d ON d.id = l.department_id
               JOIN products p ON p.id = m.product_id
              WHERE m.movement_type = 'CONSUMED' AND m.created_at BETWEEN :from AND :to $s GROUP BY d.id, p.category_id",
            ['from' => $r['from'], 'to' => $r['to']] + $sp
        );
        $loss = $this->db->fetchAll(
            "SELECT d.id, SUM(m.quantity * m.unit_cost) AS value FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
               JOIN departments d ON d.id = l.department_id
              WHERE m.movement_type IN ('DAMAGED','EXPIRED','WRITTEN_OFF') AND m.created_at BETWEEN :from AND :to $s GROUP BY d.id",
            ['from' => $r['from'], 'to' => $r['to']] + $sp
        );
        $held = $this->db->fetchAll("SELECT department_id AS id, SUM(value) AS value FROM v_department_stock GROUP BY department_id");
        $rows = [];
        foreach ($data as $x) {
            $rows[$x['id']]['name'] = $x['name'];
            $rows[$x['id']]['cat_' . $x['category_id']] = (float) $x['value'];
            $rows[$x['id']]['total'] = ($rows[$x['id']]['total'] ?? 0) + (float) $x['value'];
        }
        foreach ($loss as $x) {
            if (isset($rows[$x['id']])) {
                $rows[$x['id']]['wastage'] = (float) $x['value'];
            }
        }
        foreach ($held as $x) {
            if (isset($rows[$x['id']])) {
                $rows[$x['id']]['held'] = (float) $x['value'];
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        $columns = ['name' => ['label' => 'Department']];
        $usedCats = [];
        foreach ($cats as $cat) {
            if (array_filter($rows, fn ($x) => !empty($x['cat_' . $cat['id']]))) {
                $columns['cat_' . $cat['id']] = ['label' => $cat['name'], 'type' => 'money'];
                $usedCats[] = $cat;
            }
        }
        $columns += ['total' => ['label' => 'Total used', 'type' => 'money'], 'wastage' => ['label' => 'Wastage', 'type' => 'money'], 'held' => ['label' => 'Stock held now', 'type' => 'money']];
        $totals = ['name' => 'Total'];
        foreach (array_keys($columns) as $k) {
            if ($k !== 'name') {
                $totals[$k] = array_sum(array_map(fn ($x) => (float) ($x[$k] ?? 0), $rows));
            }
        }
        return [
            'title' => 'Department comparison', 'description' => "Consumption value by department and category, {$f['from']} to {$f['to']}.",
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals,
            'chart' => ['type' => 'bar', 'stacked' => true, 'money' => true, 'labels' => array_column($rows, 'name'),
                'series' => array_map(fn ($cat) => ['label' => $cat['name'], 'data' => array_map(fn ($x) => (float) ($x['cat_' . $cat['id']] ?? 0), $rows)], $usedCats)],
            'dated' => true,
        ];
    }

    private function reportPurchasing(array $f): array
    {
        $group = in_array($f['group'] ?? '', ['supplier', 'month', 'category'], true) ? $f['group'] : 'supplier';
        [$sel, $grp, $label] = match ($group) {
            'month'    => ["DATE_FORMAT(x.day, '%Y-%m')", "DATE_FORMAT(x.day, '%Y-%m')", 'Month'],
            'category' => ['cat.name', 'cat.id', 'Category'],
            default    => ['s.name', 's.id', 'Supplier'],
        };
        [$c, $cp] = $this->catFilter($f);
        $r = $this->range($f);
        // Ordered = PO lines by order date; received = accepted GRN lines by posting date (PO and direct receipts).
        $rows = $this->db->fetchAll(
            "SELECT $sel AS name, SUM(x.ordered) AS ordered, SUM(x.received) AS received, COUNT(DISTINCT x.po_id) AS orders
               FROM (
                    SELECT po.supplier_id, poi.product_id, po.order_date AS day, poi.line_total AS ordered, 0 AS received, po.id AS po_id
                      FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                     WHERE po.status NOT IN ('draft','pending_approval','cancelled') AND po.order_date BETWEEN :f1 AND :t1
                    UNION ALL
                    SELECT g.supplier_id, gi.product_id, DATE(g.posted_at), 0, gi.qty_received * gi.unit_cost, NULL
                      FROM goods_receipt_items gi JOIN goods_receipts g ON g.id = gi.goods_receipt_id
                     WHERE g.status = 'posted' AND g.receipt_type IN ('purchase_order','direct') AND g.posted_at BETWEEN :f2 AND :t2
               ) x
               JOIN products p ON p.id = x.product_id JOIN inventory_categories cat ON cat.id = p.category_id
               LEFT JOIN suppliers s ON s.id = x.supplier_id
              WHERE 1=1 $c
              GROUP BY $grp ORDER BY " . ($group === 'month' ? 'name' : 'received DESC'),
            ['f1' => $f['from'], 't1' => $f['to'], 'f2' => $r['from'], 't2' => $r['to']] + $cp
        );
        foreach ($rows as &$x) {
            $x['name'] = $x['name'] ?? 'No supplier recorded';
        }
        unset($x);
        return [
            'title' => 'Purchasing expenditure by ' . strtolower($label), 'description' => "Ordered = approved purchase orders by order date. Received = accepted goods (PO and direct purchases) by receipt date, {$f['from']} to {$f['to']}. Donations and opening balances excluded.",
            'columns' => ['name' => ['label' => $label], 'orders' => ['label' => 'POs', 'type' => 'int'], 'ordered' => ['label' => 'Ordered', 'type' => 'money'], 'received' => ['label' => 'Received', 'type' => 'money']],
            'rows' => $rows,
            'totals' => ['name' => 'Total', 'orders' => array_sum(array_column($rows, 'orders')), 'ordered' => array_sum(array_column($rows, 'ordered')), 'received' => array_sum(array_column($rows, 'received'))],
            'chart' => ['type' => $group === 'month' ? 'line' : 'bar', 'money' => true, 'labels' => array_column(array_slice($rows, 0, 15), 'name'),
                'series' => [['label' => 'Received', 'data' => array_map('floatval', array_column(array_slice($rows, 0, 15), 'received'))], ['label' => 'Ordered', 'data' => array_map('floatval', array_column(array_slice($rows, 0, 15), 'ordered'))]]],
            'dated' => true, 'groups' => ['supplier' => 'Supplier', 'month' => 'Month', 'category' => 'Category'], 'group' => $group, 'hospital_only' => true,
        ];
    }

    private function reportSuppliers(array $f): array
    {
        $days = max(30, (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1);
        $rows = $this->suppliers->metrics(null, $days);
        return [
            'title' => 'Supplier performance', 'description' => "Based on purchase orders created in the last $days days and their goods receipts. Rating = 5 × (40% on-time + 30% fill rate + 30% acceptance).",
            'columns' => ['supplier_code' => ['label' => 'Code'], 'name' => ['label' => 'Supplier'], 'po_count' => ['label' => 'POs', 'type' => 'int'], 'spend' => ['label' => 'Spend', 'type' => 'money'],
                'deliveries' => ['label' => 'Delivered POs', 'type' => 'int'], 'on_time_rate' => ['label' => 'On time', 'type' => 'pct'], 'avg_lead_days' => ['label' => 'Avg lead (days)', 'type' => 'qty'],
                'fill_rate' => ['label' => 'Fill rate', 'type' => 'pct'], 'rejection_rate' => ['label' => 'Rejected', 'type' => 'pct'], 'late_open_pos' => ['label' => 'Overdue POs', 'type' => 'int'], 'rating' => ['label' => 'Rating /5', 'type' => 'qty']],
            'rows' => $rows,
            'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'name'), 'series' => [['label' => 'Rating (0–5)', 'data' => array_map(fn ($x) => $x['rating'] ?? 0, $rows)]]],
            'dated' => true, 'hospital_only' => true,
        ];
    }

    private function reportWastage(array $f): array
    {
        [$s, $sp] = $this->inScope($this->scope($f['department_id']), 'l.department_id', 'sc');
        [$c, $cp] = $this->catFilter($f);
        $r = $this->range($f);
        $rows = $this->db->fetchAll(
            "SELECT p.product_code, p.name AS product, cat.name AS category, u.code AS unit,
                    SUM(CASE WHEN m.movement_type = 'EXPIRED' THEN m.quantity ELSE 0 END) AS expired_qty,
                    SUM(CASE WHEN m.movement_type = 'DAMAGED' THEN m.quantity ELSE 0 END) AS damaged_qty,
                    SUM(CASE WHEN m.movement_type = 'WRITTEN_OFF' THEN m.quantity ELSE 0 END) AS written_off_qty,
                    SUM(CASE WHEN m.movement_type = 'ADJUSTED_OUT' THEN m.quantity ELSE 0 END) AS adjusted_qty,
                    SUM(CASE WHEN m.movement_type IN ('EXPIRED','DAMAGED','WRITTEN_OFF','ADJUSTED_OUT') THEN m.quantity * m.unit_cost ELSE 0 END) AS loss_value,
                    SUM(CASE WHEN m.movement_type = 'CONSUMED' THEN m.quantity * m.unit_cost ELSE 0 END) AS used_value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
               JOIN products p ON p.id = m.product_id JOIN inventory_categories cat ON cat.id = p.category_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE m.movement_type IN ('EXPIRED','DAMAGED','WRITTEN_OFF','ADJUSTED_OUT','CONSUMED') AND m.created_at BETWEEN :from AND :to $s $c
              GROUP BY p.id HAVING loss_value > 0 ORDER BY loss_value DESC",
            ['from' => $r['from'], 'to' => $r['to']] + $sp + $cp
        );
        foreach ($rows as &$x) {
            $x['loss_rate'] = ((float) $x['loss_value'] + (float) $x['used_value']) > 0 ? (float) $x['loss_value'] / ((float) $x['loss_value'] + (float) $x['used_value']) : null;
        }
        unset($x);
        $expiry = $this->expiry->analyse($this->scope($f['department_id']), 90);
        $byMonth = $this->db->fetchAll(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym, m.movement_type, SUM(m.quantity * m.unit_cost) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.movement_type IN ('EXPIRED','DAMAGED','WRITTEN_OFF','ADJUSTED_OUT') AND m.created_at BETWEEN :from AND :to $s
              GROUP BY ym, m.movement_type ORDER BY ym",
            ['from' => $r['from'], 'to' => $r['to']] + $sp
        );
        $months = array_values(array_unique(array_column($byMonth, 'ym')));
        $series = [];
        foreach (['EXPIRED' => 'Expired', 'DAMAGED' => 'Damaged', 'WRITTEN_OFF' => 'Written off', 'ADJUSTED_OUT' => 'Adjusted out'] as $t => $l) {
            $series[] = ['label' => $l, 'data' => array_map(fn ($ym) => (float) (array_values(array_filter($byMonth, fn ($b) => $b['ym'] === $ym && $b['movement_type'] === $t))[0]['value'] ?? 0), $months)];
        }
        $totalLoss = array_sum(array_column($rows, 'loss_value'));
        $totalUsed = array_sum(array_column($rows, 'used_value'));
        return [
            'title' => 'Expiry & wastage', 'description' => "Stock lost to expiry, damage, write-off and negative corrections, {$f['from']} to {$f['to']}. Loss rate = loss ÷ (loss + consumption) for each product.",
            'columns' => ['product_code' => ['label' => 'Code'], 'product' => ['label' => 'Product'], 'category' => ['label' => 'Category'],
                'expired_qty' => ['label' => 'Expired', 'type' => 'qty'], 'damaged_qty' => ['label' => 'Damaged', 'type' => 'qty'], 'written_off_qty' => ['label' => 'Written off', 'type' => 'qty'], 'adjusted_qty' => ['label' => 'Adjusted out', 'type' => 'qty'],
                'loss_value' => ['label' => 'Loss value', 'type' => 'money'], 'loss_rate' => ['label' => 'Loss rate', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['product' => 'Total', 'loss_value' => $totalLoss, 'loss_rate' => ($totalLoss + $totalUsed) > 0 ? $totalLoss / ($totalLoss + $totalUsed) : null],
            'chart' => $months ? ['type' => 'bar', 'stacked' => true, 'money' => true, 'labels' => $months, 'series' => $series] : null,
            'notes' => [sprintf('Currently expired stock still held: %s batch-location(s). Expiring within 90 days and at risk of being unused: %d batch-location(s).',
                $expiry['summary']['expired_batches'], $expiry['summary']['batches_at_risk'])],
            'dated' => true,
        ];
    }

    private function reportStockRisk(array $f): array
    {
        $dept = $f['department_id'] ?: null;
        $scope = $this->gate->departmentScope('stock.view');
        if ($scope !== null && (!$dept || !in_array($dept, $scope, true))) {
            $dept = $scope[0] ?? 0;
        }
        $rows = array_values(array_filter($this->forecast->forecast($dept), fn ($x) => in_array($x['urgency'], ['critical', 'high', 'medium'], true)));
        $labels = ['critical' => 'Critical', 'high' => 'Reorder now', 'medium' => 'Reorder soon'];
        foreach ($rows as &$x) {
            $x['urgency_label'] = $labels[$x['urgency']];
        }
        unset($x);
        $counts = array_count_values(array_column($rows, 'urgency'));
        return [
            'title' => 'Low stock & stock-out risk', 'description' => ($dept ? 'Department' : 'Hospital-wide') . ' forecast (estimates). Days left = usable stock ÷ average daily use; see Intelligence → Stock-out forecast for assumptions.',
            'columns' => ['product_code' => ['label' => 'Code'], 'name' => ['label' => 'Product'], 'usable' => ['label' => 'Usable', 'type' => 'qty'], 'adc' => ['label' => 'Use/day', 'type' => 'qty'],
                'days_remaining' => ['label' => 'Days left', 'type' => 'qty'], 'stockout_date' => ['label' => 'Runs out ≈', 'type' => 'date'], 'lead_time' => ['label' => 'Lead time', 'type' => 'int'],
                'on_order' => ['label' => 'On order', 'type' => 'qty'], 'reorder_qty' => ['label' => 'Suggested', 'type' => 'qty'], 'urgency_label' => ['label' => 'Status'], 'confidence' => ['label' => 'Confidence']],
            'rows' => $rows,
            'chart' => ['type' => 'doughnut', 'labels' => array_values(array_intersect_key($labels, $counts)), 'series' => [['label' => 'Products', 'data' => array_values(array_intersect_key($counts, $labels))]]],
            'dated' => false,
        ];
    }

    private function reportSlowMoving(array $f): array
    {
        $days = in_array((int) ($f['days'] ?? 0), [30, 60, 90, 180, 365], true) ? (int) $f['days'] : 90;
        [$s, $sp] = $this->inScope($this->scope($f['department_id']), 'l.department_id', 'sc');
        [$c, $cp] = $this->catFilter($f);
        $rows = $this->db->fetchAll(
            "SELECT p.product_code, p.name AS product, cat.name AS category, d.name AS department, l.name AS location, u.code AS unit,
                    SUM(sb.quantity) AS on_hand, SUM(sb.quantity * b.unit_cost) AS value,
                    (SELECT MAX(m.created_at) FROM stock_movements m WHERE m.from_location_id = l.id AND m.product_id = p.id
                        AND m.movement_type IN ('CONSUMED','ISSUED','TRANSFERRED','RETURNED')) AS last_out,
                    MIN(b.expiry_date) AS next_expiry
               FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id AND b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
               JOIN products p ON p.id = sb.product_id JOIN inventory_categories cat ON cat.id = p.category_id JOIN units_of_measure u ON u.id = p.base_unit_id
               JOIN inventory_locations l ON l.id = sb.location_id AND l.type NOT IN ('transit','quarantine') JOIN departments d ON d.id = l.department_id
              WHERE sb.quantity > 0 $s $c
              GROUP BY l.id, p.id
             HAVING last_out IS NULL OR last_out < NOW() - INTERVAL :days DAY
              ORDER BY value DESC",
            $sp + $cp + ['days' => $days]
        );
        foreach ($rows as &$x) {
            $x['idle_days'] = $x['last_out'] ? (int) floor((time() - strtotime($x['last_out'])) / 86400) : null;
            $x['last_out'] = $x['last_out'] ? substr($x['last_out'], 0, 10) : 'Never';
        }
        unset($x);
        return [
            'title' => "Slow-moving inventory (no movement out in $days days)", 'description' => 'Usable stock that has not been used, issued or transferred from its location in the selected period: candidates for redistribution or reduced ordering.',
            'columns' => ['product_code' => ['label' => 'Code'], 'product' => ['label' => 'Product'], 'department' => ['label' => 'Department'], 'location' => ['label' => 'Location'],
                'on_hand' => ['label' => 'On hand', 'type' => 'qty'], 'unit' => ['label' => 'Unit'], 'value' => ['label' => 'Value tied up', 'type' => 'money'],
                'last_out' => ['label' => 'Last movement out'], 'idle_days' => ['label' => 'Idle days', 'type' => 'int'], 'next_expiry' => ['label' => 'Next expiry', 'type' => 'date']],
            'rows' => $rows,
            'totals' => ['product' => count($rows) . ' item-location(s)', 'value' => array_sum(array_column($rows, 'value'))],
            'dated' => false, 'idle' => $days,
        ];
    }

    private function reportMovements(array $f): array
    {
        $scope = $this->scope($f['department_id']);
        $scopeSql = '';
        $p1 = $p2 = [];
        if ($scope !== null) {
            [$in1, $p1] = Database::inClause($scope ?: [0], 'sa');
            [$in2, $p2] = Database::inClause($scope ?: [0], 'sb');
            $scopeSql = " AND (fl.department_id IN $in1 OR tl.department_id IN $in2)";
        }
        [$c, $cp] = $this->catFilter($f);
        $type = in_array($f['type'] ?? '', \App\Services\Stock\StockService::MOVEMENT_TYPES, true) ? $f['type'] : null;
        $r = $this->range($f);
        $rows = $this->db->fetchAll(
            "SELECT m.created_at, t.txn_number, m.movement_type, p.product_code, p.name AS product, b.batch_code, b.lot_number,
                    m.quantity, u.code AS unit, m.quantity * m.unit_cost AS value, fl.name AS from_location, tl.name AS to_location, us.full_name AS user, m.reason
               FROM stock_movements m JOIN stock_transactions t ON t.id = m.transaction_id JOIN products p ON p.id = m.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id JOIN product_batches b ON b.id = m.batch_id JOIN users us ON us.id = m.performed_by
               LEFT JOIN inventory_locations fl ON fl.id = m.from_location_id LEFT JOIN inventory_locations tl ON tl.id = m.to_location_id
              WHERE m.created_at BETWEEN :from AND :to $scopeSql $c" . ($type ? ' AND m.movement_type = :type' : '') . '
              ORDER BY m.id DESC LIMIT 5000',
            ['from' => $r['from'], 'to' => $r['to']] + $p1 + $p2 + $cp + ($type ? ['type' => $type] : [])
        );
        return [
            'title' => 'Stock movement history', 'description' => "Ledger entries {$f['from']} to {$f['to']}" . ($type ? " (type $type)" : '') . '. Limited to the latest 5,000 rows; narrow the dates for more.',
            'columns' => ['created_at' => ['label' => 'Date/time'], 'txn_number' => ['label' => 'Transaction'], 'movement_type' => ['label' => 'Type'], 'product_code' => ['label' => 'Code'],
                'product' => ['label' => 'Product'], 'batch_code' => ['label' => 'Batch'], 'quantity' => ['label' => 'Qty', 'type' => 'qty'], 'unit' => ['label' => 'Unit'],
                'value' => ['label' => 'Value', 'type' => 'money'], 'from_location' => ['label' => 'From'], 'to_location' => ['label' => 'To'], 'user' => ['label' => 'By'], 'reason' => ['label' => 'Reason']],
            'rows' => $rows, 'dated' => true, 'types' => \App\Services\Stock\StockService::MOVEMENT_TYPES,
        ];
    }

    /** KPI overview for the analytics landing page (cached 10 min, invalidated on data changes). */
    public function overview(array $f): array
    {
        $scope = $this->scope($f['department_id']);
        return app(\App\Services\Support\CacheService::class)->remember('overview:' . json_encode([$f, $scope]), 600, fn () => $this->computeOverview($f, $scope));
    }

    private function computeOverview(array $f, ?array $scope): array
    {
        [$s, $sp] = $this->inScope($scope, 'l.department_id', 'sc');
        $r = $this->range($f);
        $days = max(1, (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1);
        $prevFrom = date('Y-m-d', strtotime($f['from'] . " -$days days")) . ' 00:00:00';
        $k = $this->db->fetchOne(
            "SELECT SUM(CASE WHEN m.movement_type = 'CONSUMED' AND m.created_at BETWEEN :f1 AND :t1 THEN m.quantity * m.unit_cost ELSE 0 END) AS used,
                    SUM(CASE WHEN m.movement_type = 'CONSUMED' AND m.created_at >= :pf1 AND m.created_at < :f2 THEN m.quantity * m.unit_cost ELSE 0 END) AS used_prev,
                    SUM(CASE WHEN m.movement_type IN ('EXPIRED','DAMAGED','WRITTEN_OFF','ADJUSTED_OUT') AND m.created_at BETWEEN :f3 AND :t3 THEN m.quantity * m.unit_cost ELSE 0 END) AS lost
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.created_at >= :pf2 $s",
            ['f1' => $r['from'], 't1' => $r['to'], 'f2' => $r['from'], 'f3' => $r['from'], 't3' => $r['to'], 'pf1' => $prevFrom, 'pf2' => $prevFrom] + $sp
        );
        $value = (float) $this->db->fetchValue(
            "SELECT COALESCE(SUM(sb.quantity * b.unit_cost), 0) FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id
               JOIN inventory_locations l ON l.id = sb.location_id WHERE sb.quantity > 0 $s",
            $sp
        );
        $spend = $scope === null ? (float) $this->db->fetchValue(
            "SELECT COALESCE(SUM(gi.qty_received * gi.unit_cost), 0) FROM goods_receipt_items gi JOIN goods_receipts g ON g.id = gi.goods_receipt_id
              WHERE g.status = 'posted' AND g.receipt_type IN ('purchase_order','direct') AND g.posted_at BETWEEN :f AND :t",
            ['f' => $r['from'], 't' => $r['to']]
        ) : null;
        $trend = $this->db->fetchAll(
            "SELECT DATE_FORMAT(m.created_at, '%Y-%m') AS ym,
                    SUM(CASE WHEN m.movement_type = 'CONSUMED' THEN m.quantity * m.unit_cost ELSE 0 END) AS used,
                    SUM(CASE WHEN m.movement_type IN ('EXPIRED','DAMAGED','WRITTEN_OFF','ADJUSTED_OUT') THEN m.quantity * m.unit_cost ELSE 0 END) AS lost
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.created_at BETWEEN :f AND :t $s GROUP BY ym ORDER BY ym",
            ['f' => $r['from'], 't' => $r['to']] + $sp
        );
        $forecast = $this->forecast->forecast($scope === null ? null : ($scope[0] ?? 0));
        $exp = $this->expiry->analyse($scope, 90);
        $used = (float) $k['used'];
        $lost = (float) $k['lost'];
        return [
            'days' => $days, 'value' => $value, 'used' => $used, 'used_prev' => (float) $k['used_prev'],
            'used_change' => (float) $k['used_prev'] > 0 ? ($used - (float) $k['used_prev']) / (float) $k['used_prev'] : null,
            'lost' => $lost, 'loss_rate' => ($used + $lost) > 0 ? $lost / ($used + $lost) : null, 'spend' => $spend,
            'critical' => count(array_filter($forecast, fn ($x) => $x['urgency'] === 'critical')),
            'reorder' => count(array_filter($forecast, fn ($x) => $x['urgency'] === 'high')),
            'expiry_risk' => $exp['summary']['risk_90'], 'expired_value' => $exp['summary']['expired_value'],
            'turnover_days' => $used > 0 ? $value / ($used / $days) : null,
            'trend' => $trend,
        ];
    }
}
