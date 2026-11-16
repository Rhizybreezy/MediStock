<?php
declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\App;
use App\Core\Database;
use App\Core\Gate;
use App\Services\Intelligence\AnomalyService;
use App\Services\Intelligence\RedistributionService;
use App\Services\Procurement\PurchaseRequestService;
use App\Services\Procurement\SupplierPerformanceService;
use App\Services\Support\AuthService;
use App\Services\Support\SettingsService;

/**
 * Data for the role-specific dashboards. Read-only: nothing here changes data.
 * Every figure respects the viewer's department scope, and every link or button
 * is only offered when the viewer's permissions allow the target page.
 */
final class RoleDashboardService
{
    private const OPEN_TRANSIT = "('dispatched','partially_received')";

    public function __construct(
        private Database $db,
        private Gate $gate,
        private AuthService $auth,
        private SettingsService $settings,
    ) {
    }

    /** @return int[]|null null = hospital-wide */
    public function scope(): ?array
    {
        return $this->gate->departmentScope('stock.view');
    }

    // ------------------------------------------------------------------
    // Shared building blocks
    // ------------------------------------------------------------------

    /**
     * Items waiting for the viewer's decision, one row per workflow the viewer may approve.
     * Items the viewer submitted are excluded (they cannot approve their own).
     *
     * @return list<array{label: string, icon: string, count: int, url: string}>
     */
    public function approvalQueue(): array
    {
        $uid = (int) $this->auth->id();
        $defs = [
            ['requests.approve', 'Stock requests to review', 'bi-clipboard-check',
                "SELECT COUNT(*) FROM stock_requests r WHERE r.status = 'submitted' AND r.requested_by <> :uid", 'r.supplying_department_id', ['requests.index', ['status' => 'to_review']]],
            ['transfers.approve', 'Transfers to approve', 'bi-arrow-left-right',
                "SELECT COUNT(*) FROM stock_transfers t WHERE t.status = 'pending_approval' AND t.type <> 'issue' AND t.requested_by <> :uid", 't.source_department_id', ['transfers.index', ['status' => 'pending_approval']]],
            ['adjustments.approve', 'Adjustments to approve', 'bi-sliders',
                "SELECT COUNT(*) FROM stock_adjustments a JOIN inventory_locations l ON l.id = a.location_id WHERE a.status = 'pending' AND a.requested_by <> :uid", 'l.department_id', ['adjustments.index', ['status' => 'pending']]],
            ['counts.approve', 'Stock counts to approve', 'bi-list-check',
                "SELECT COUNT(*) FROM stock_counts c JOIN inventory_locations l ON l.id = c.location_id WHERE c.status = 'submitted' AND c.started_by <> :uid", 'l.department_id', ['counts.index', ['status' => 'submitted']]],
            ['reservations.approve', 'Reservations to approve', 'bi-bookmark-star',
                "SELECT COUNT(*) FROM stock_reservations s JOIN inventory_locations l ON l.id = s.location_id WHERE s.status = 'pending' AND s.reserved_by <> :uid", 'l.department_id', ['reservations.index', ['status' => 'pending']]],
            ['purchase_requests.approve', 'Purchase requests to approve', 'bi-cart-check',
                "SELECT COUNT(*) FROM purchase_requests pr WHERE pr.status = 'submitted' AND pr.requested_by <> :uid", 'pr.department_id', ['purchase_requests.index', ['status' => 'submitted']]],
            ['purchase_orders.approve', 'Purchase orders to approve', 'bi-file-earmark-check',
                "SELECT COUNT(*) FROM purchase_orders po WHERE po.status = 'pending_approval' AND po.created_by <> :uid", null, ['purchase_orders.index', ['status' => 'pending_approval']]],
        ];
        $out = [];
        foreach ($defs as [$perm, $label, $icon, $sql, $deptCol, [$route, $query]]) {
            if (!$this->gate->can($perm)) {
                continue;
            }
            $params = ['uid' => $uid];
            $scope = $this->gate->departmentScope($perm);
            if ($scope !== null && $deptCol !== null) {
                [$in, $p] = Database::inClause($scope ?: [0], 'aq');
                $sql .= " AND $deptCol IN $in";
                $params += $p;
            } elseif ($scope !== null && $deptCol === null) {
                continue; // purchase orders are hospital-level
            }
            $out[] = ['label' => $label, 'icon' => $icon, 'count' => (int) $this->db->fetchValue($sql, $params), 'url' => route($route, [], $query)];
        }
        return $out;
    }

    /**
     * Quick-action buttons, keeping only those whose target route the viewer may open.
     *
     * @param list<array{0: string, 1: string, 2: string, 3?: array, 4?: array}> $actions [route, label, icon, query, route params]
     * @return list<array{label: string, icon: string, url: string}>
     */
    public function actions(array $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            [$route, $label, $icon] = $a;
            if ($this->canOpen($route)) {
                $out[] = ['label' => $label, 'icon' => $icon, 'url' => route($route, $a[4] ?? [], $a[3] ?? [])];
            }
        }
        return $out;
    }

    /** True when every can: rule on the named route passes for the current user. */
    public function canOpen(string $routeName): bool
    {
        foreach (App::instance()->router->routes() as $r) {
            if ($r->name !== $routeName) {
                continue;
            }
            foreach ($r->middleware as $m) {
                if (str_starts_with($m, 'can:') && !$this->gate->canAny(explode('|', substr($m, 4)))) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** Units (and value at cost) leaving stock by movement type over the last N days, within scope. */
    private function outflow(array $types, int $days, ?array $scope): array
    {
        [$tIn, $tP] = Database::inClause($types, 'mt');
        [$sSql, $sP] = $this->scopeSql('l.department_id', $scope, 'of');
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(m.quantity), 0) AS qty, COALESCE(SUM(m.quantity * m.unit_cost), 0) AS value
               FROM stock_movements m JOIN inventory_locations l ON l.id = m.from_location_id
              WHERE m.created_at >= CURDATE() - INTERVAL :d DAY AND m.movement_type IN $tIn $sSql",
            array_merge(['d' => $days], $tP, $sP)
        );
        return ['qty' => (float) $row['qty'], 'value' => (float) $row['value']];
    }

    /** Value/units received from outside the hospital since a date. */
    private function received(string $since): array
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(m.quantity), 0) AS qty, COALESCE(SUM(m.quantity * m.unit_cost), 0) AS value
               FROM stock_movements m
              WHERE m.movement_type = 'RECEIVED' AND m.from_location_id IS NULL AND m.created_at >= :s",
            ['s' => $since]
        );
        return ['qty' => (float) $row['qty'], 'value' => (float) $row['value']];
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) $this->db->fetchValue($sql, $params);
    }

    private function scopeSql(string $column, ?array $scope, string $prefix): array
    {
        if ($scope === null) {
            return ['', []];
        }
        [$in, $params] = Database::inClause($scope ?: [0], $prefix);
        return [" AND $column IN $in", $params];
    }

    // ------------------------------------------------------------------
    // Administrator
    // ------------------------------------------------------------------

    public function adminPanel(): array
    {
        $users = $this->db->fetchOne(
            "SELECT SUM(status = 'active') AS active, SUM(status = 'inactive') AS inactive,
                    SUM(status = 'locked' OR (locked_until IS NOT NULL AND locked_until > NOW())) AS locked,
                    SUM(status = 'active' AND must_change_password = 1) AS must_change
               FROM users"
        );
        $lastReconcile = $this->db->fetchOne("SELECT created_at, description FROM audit_logs WHERE action = 'reconcile' ORDER BY id DESC LIMIT 1");
        $alertsFile = BASE_PATH . '/storage/cache/alerts_last_run';
        return [
            'users_active'      => (int) $users['active'],
            'users_inactive'    => (int) $users['inactive'],
            'users_locked'      => (int) $users['locked'],
            'users_must_change' => (int) $users['must_change'],
            'failed_logins_24h' => $this->count('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND attempted_at > NOW() - INTERVAL 1 DAY'),
            'denied_7d'         => $this->count("SELECT COUNT(*) FROM audit_logs WHERE action = 'access_denied' AND created_at > NOW() - INTERVAL 7 DAY"),
            'last_reconcile'    => $lastReconcile,
            'alerts_last_run'   => is_file($alertsFile) ? date('Y-m-d H:i:s', (int) filemtime($alertsFile)) : null,
            'ai_configured'     => (string) config('ai.api_key', '') !== '' && (bool) $this->settings->get('ai_enabled', true),
            'recent_audit'      => $this->db->fetchAll(
                'SELECT a.id, a.action, a.entity_type, a.description, a.created_at, u.full_name
                   FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                  ORDER BY a.id DESC LIMIT 8'
            ),
            'actions' => $this->actions([
                ['admin.users.create', 'Add user', 'bi-person-plus'],
                ['admin.roles.index', 'Roles & permissions', 'bi-shield-lock'],
                ['admin.departments.index', 'Departments', 'bi-building'],
                ['admin.locations.index', 'Storage locations', 'bi-geo-alt'],
                ['admin.settings', 'System settings', 'bi-gear'],
                ['admin.audit.index', 'Audit log', 'bi-journal-text'],
            ]),
        ];
    }

    // ------------------------------------------------------------------
    // Hospital management (view + approve)
    // ------------------------------------------------------------------

    public function manager(): array
    {
        $scope = $this->scope();
        $stocked = $this->db->fetchOne(
            'SELECT COUNT(*) AS total, COALESCE(SUM(usable_qty > 0), 0) AS available
               FROM v_product_stock WHERE is_active = 1 AND (reorder_level > 0 OR usable_qty > 0)'
        );
        $total = (int) $stocked['total'];
        return [
            'consumption_30'  => $this->outflow(['CONSUMED'], 30, $scope),
            'wastage_90'      => $this->outflow(['EXPIRED', 'DAMAGED', 'WRITTEN_OFF'], 90, $scope),
            'received_month'  => $this->received(date('Y-m-01 00:00:00')),
            'availability'    => $total > 0 ? round(100 * (int) $stocked['available'] / $total, 1) : null,
            'approvals'       => $this->approvalQueue(),
            'actions'         => $this->actions([
                ['analytics.index', 'Analytics overview', 'bi-graph-up'],
                ['analytics.report', 'Reports', 'bi-file-earmark-bar-graph', [], ['key' => 'valuation']],
                ['intelligence.stockout', 'Stock-out forecast', 'bi-graph-down-arrow'],
                ['intelligence.expiry', 'Expiry risk', 'bi-hourglass-split'],
                ['suppliers.performance', 'Supplier performance', 'bi-truck-front'],
                ['admin.audit.index', 'Audit log', 'bi-journal-text'],
            ]),
        ];
    }

    // ------------------------------------------------------------------
    // Inventory manager (runs the whole inventory)
    // ------------------------------------------------------------------

    public function inventory(RedistributionService $redistribution, AnomalyService $anomalies): array
    {
        $scope = $this->scope();
        [$sSql, $sP] = $this->scopeSql('t.destination_department_id', $scope, 'it');
        return [
            'in_transit'      => $this->count("SELECT COUNT(*) FROM stock_transfers t WHERE t.status IN " . self::OPEN_TRANSIT . $sSql, $sP),
            'to_issue'        => $this->count("SELECT COUNT(*) FROM stock_requests WHERE status IN ('approved','partially_approved','partially_issued')"),
            'to_receive'      => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received')"),
            'late_deliveries' => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received') AND expected_delivery_date < CURDATE()"),
            'counts_open'     => $this->count("SELECT COUNT(*) FROM stock_counts WHERE status = 'in_progress'"),
            'recalls_open'    => $this->count("SELECT COUNT(*) FROM batch_recalls WHERE status <> 'closed'"),
            'redistribution'  => count($redistribution->suggestions($scope)),
            'anomalies'       => count($anomalies->detect($scope)),
            'approvals'       => $this->approvalQueue(),
            'work'            => array_values(array_filter([
                $this->canOpen('requests.index') ? ['label' => 'Approved requests to issue', 'icon' => 'bi-box-arrow-right', 'url' => route('requests.index', [], ['status' => 'to_issue'])] : null,
                $this->canOpen('inventory.receipts.po') ? ['label' => 'Purchase orders to receive', 'icon' => 'bi-truck', 'url' => route('inventory.receipts.po')] : null,
                $this->canOpen('inventory.issues.index') ? ['label' => 'Stock in transit', 'icon' => 'bi-signpost-split', 'url' => route('inventory.issues.index')] : null,
                $this->canOpen('counts.index') ? ['label' => 'Counts in progress', 'icon' => 'bi-list-check', 'url' => route('counts.index', [], ['status' => 'in_progress'])] : null,
                $this->canOpen('recalls.index') ? ['label' => 'Open recalls', 'icon' => 'bi-exclamation-octagon', 'url' => route('recalls.index')] : null,
            ])),
            'actions'         => $this->actions([
                ['inventory.receipts.po', 'Receive against PO', 'bi-truck'],
                ['inventory.receipts.create', 'Direct receipt', 'bi-box-arrow-in-down'],
                ['inventory.issues.create', 'Issue stock', 'bi-box-arrow-right'],
                ['transfers.create', 'New transfer', 'bi-arrow-left-right'],
                ['counts.index', 'Stock counts', 'bi-list-check'],
                ['codes.labels', 'Print labels', 'bi-qr-code'],
                ['scan', 'Scan', 'bi-upc-scan'],
            ]),
        ];
    }

    // ------------------------------------------------------------------
    // Procurement officer
    // ------------------------------------------------------------------

    public function procurement(PurchaseRequestService $prs, SupplierPerformanceService $suppliers): array
    {
        $suggestions = $this->gate->can('purchase_requests.create')
            ? count(array_filter($prs->lowStockSuggestions(), fn ($r) => (float) $r['suggested_qty'] > 0)) : null;
        $perf = array_values(array_filter($suppliers->metrics(null, 365), fn ($m) => (int) $m['deliveries'] > 0));
        usort($perf, fn ($a, $b) => ($b['rating'] <=> $a['rating']));
        return [
            'low_stock_suggestions' => $suggestions,
            'prs_to_order'  => $this->count("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('approved','partially_ordered')"),
            'pos_draft'     => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status = 'draft'"),
            'pos_pending'   => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval'"),
            'pos_open'      => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received')"),
            'pos_late'      => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved','sent','partially_received') AND expected_delivery_date < CURDATE()"),
            'spend_month'   => $this->received(date('Y-m-01 00:00:00')),
            'spend_last'    => $this->received(date('Y-m-01 00:00:00', strtotime('first day of last month'))),
            'open_pos'      => $this->db->fetchAll(
                "SELECT po.id, po.po_number, po.status, po.expected_delivery_date, po.total_amount, s.name AS supplier_name,
                        (po.expected_delivery_date IS NOT NULL AND po.expected_delivery_date < CURDATE()
                         AND po.status IN ('approved','sent','partially_received')) AS is_late
                   FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                  WHERE po.status IN ('draft','pending_approval','approved','sent','partially_received')
                  ORDER BY is_late DESC, po.expected_delivery_date IS NULL, po.expected_delivery_date, po.id DESC
                  LIMIT 8"
            ),
            'prs_waiting'   => $this->db->fetchAll(
                "SELECT pr.id, pr.pr_number, pr.priority, pr.status, pr.approved_at, d.name AS department_name,
                        (SELECT COUNT(*) FROM purchase_request_items i WHERE i.purchase_request_id = pr.id) AS line_count
                   FROM purchase_requests pr JOIN departments d ON d.id = pr.department_id
                  WHERE pr.status IN ('approved','partially_ordered')
                  ORDER BY FIELD(pr.priority, 'emergency', 'urgent', 'routine'), pr.approved_at
                  LIMIT 6"
            ),
            'suppliers'     => array_slice($perf, 0, 5),
            'actions'       => $this->actions([
                ['purchase_requests.low_stock', 'Low-stock suggestions', 'bi-lightbulb'],
                ['purchase_requests.create', 'New purchase request', 'bi-cart-plus'],
                ['purchase_orders.from_requests', 'PO from approved requests', 'bi-file-earmark-plus'],
                ['purchase_orders.create', 'New purchase order', 'bi-file-earmark-text'],
                ['suppliers.index', 'Suppliers', 'bi-truck-front'],
            ]),
        ];
    }

    // ------------------------------------------------------------------
    // Department users: pharmacist and storekeeper
    // ------------------------------------------------------------------

    public function department(string $variant): array
    {
        $scope = $this->scope() ?? [];
        $hospitalWide = $this->scope() === null;
        [$dSql, $dP] = $this->scopeSql('l.department_id', $hospitalWide ? null : $scope, 'dp');
        [$tSql, $tP] = $this->scopeSql('t.destination_department_id', $hospitalWide ? null : $scope, 'dt');
        [$rSql, $rP] = $this->scopeSql('r.requesting_department_id', $hospitalWide ? null : $scope, 'dr');
        [$lSql, $lP] = $this->scopeSql('dps.department_id', $hospitalWide ? null : $scope, 'dl');
        $warningDays = $this->settings->int('expiry_warning_days', 90);

        $data = [
            'products_in_stock' => $this->count(
                "SELECT COUNT(DISTINCT sb.product_id) FROM stock_balances sb
                   JOIN inventory_locations l ON l.id = sb.location_id
                   JOIN product_batches b ON b.id = sb.batch_id
                  WHERE sb.quantity > 0 AND l.type NOT IN ('quarantine','transit') AND b.status = 'active'
                    AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) $dSql", $dP),
            'used_today'   => $this->outflow(['CONSUMED'], 0, $hospitalWide ? null : $scope)['qty'],
            'used_7d'      => $this->outflow(['CONSUMED'], 7, $hospitalWide ? null : $scope)['qty'],
            'incoming'     => $this->db->fetchAll(
                "SELECT t.id, t.transfer_number, t.type, t.status, t.dispatched_at, sd.name AS source_department,
                        dl.name AS destination_location,
                        (SELECT COUNT(*) FROM stock_transfer_items i WHERE i.stock_transfer_id = t.id) AS line_count
                   FROM stock_transfers t
                   JOIN departments sd ON sd.id = t.source_department_id
                   JOIN inventory_locations dl ON dl.id = t.destination_location_id
                  WHERE t.status IN " . self::OPEN_TRANSIT . " $tSql
                  ORDER BY t.dispatched_at LIMIT 6", $tP),
            'requests'     => $this->db->fetchAll(
                "SELECT r.id, r.request_number, r.status, r.priority, r.created_at, d.name AS department_name
                   FROM stock_requests r JOIN departments d ON d.id = r.requesting_department_id
                  WHERE r.status IN ('draft','submitted','approved','partially_approved','partially_issued','issued') $rSql
                  ORDER BY r.id DESC LIMIT 6", $rP),
            'low_items'    => $this->db->fetchAll(
                "SELECT p.id AS product_id, p.name, u.code AS unit, d.name AS department_name, dps.reorder_level,
                        COALESCE(ds.usable_qty, 0) AS usable_qty
                   FROM department_product_settings dps
                   JOIN products p ON p.id = dps.product_id AND p.is_active = 1
                   JOIN units_of_measure u ON u.id = p.base_unit_id
                   JOIN departments d ON d.id = dps.department_id
                   LEFT JOIN v_department_stock ds ON ds.department_id = dps.department_id AND ds.product_id = dps.product_id
                  WHERE dps.is_stocked = 1 AND dps.reorder_level > 0 AND COALESCE(ds.usable_qty, 0) <= dps.reorder_level $lSql
                  ORDER BY COALESCE(ds.usable_qty, 0) / dps.reorder_level, p.name LIMIT 8", $lP),
            'expiring'     => $this->db->fetchAll(
                "SELECT b.id AS batch_id, b.batch_code, b.expiry_date, p.name AS product_name, l.name AS location_name,
                        u.code AS unit, SUM(sb.quantity) AS qty
                   FROM stock_balances sb
                   JOIN product_batches b ON b.id = sb.batch_id
                   JOIN products p ON p.id = sb.product_id
                   JOIN units_of_measure u ON u.id = p.base_unit_id
                   JOIN inventory_locations l ON l.id = sb.location_id
                  WHERE sb.quantity > 0 AND l.type NOT IN ('transit') AND b.expiry_date IS NOT NULL
                    AND b.expiry_date <= CURDATE() + INTERVAL :wd DAY $dSql
                  GROUP BY b.id, l.id
                  ORDER BY b.expiry_date LIMIT 8", array_merge(['wd' => $warningDays], $dP)),
            'expiry_days'  => $warningDays,
            'cold_chain'   => $this->coldChain($hospitalWide ? null : $scope),
        ];

        $data['actions'] = $variant === 'pharmacist'
            ? $this->actions([
                ['consumption.create', 'Record dispensing', 'bi-capsule'],
                ['inventory.incoming', 'Confirm deliveries', 'bi-box-seam'],
                ['requests.create', 'Request stock', 'bi-clipboard-plus'],
                ['cold_chain.index', 'Fridge temperatures', 'bi-thermometer-snow'],
                ['inventory.batches.index', 'Batches & expiry', 'bi-hourglass-split', ['expiry' => '90']],
                ['scan', 'Scan', 'bi-upc-scan'],
            ])
            : $this->actions([
                ['consumption.create', 'Record consumption', 'bi-clipboard-pulse'],
                ['inventory.incoming', 'Confirm deliveries', 'bi-box-seam'],
                ['requests.create', 'Request stock', 'bi-clipboard-plus'],
                ['transfers.create', 'Return to store', 'bi-arrow-return-left', ['type' => 'return_to_store']],
                ['counts.index', 'Stock count', 'bi-list-check'],
                ['adjustments.create', 'Report damage / loss', 'bi-exclamation-triangle'],
            ]);
        return $data;
    }

    /** Latest reading per cold-chain location in scope, flagging excursions and overdue readings (12 h). */
    private function coldChain(?array $scope): array
    {
        [$sSql, $sP] = $this->scopeSql('l.department_id', $scope, 'cc');
        return $this->db->fetchAll(
            "SELECT l.id, l.name, l.min_temp_c, l.max_temp_c, t.temperature_c, t.recorded_at, t.is_out_of_range,
                    (t.recorded_at IS NULL OR t.recorded_at < NOW() - INTERVAL 12 HOUR) AS is_overdue
               FROM inventory_locations l
               LEFT JOIN temperature_logs t ON t.id = (SELECT t2.id FROM temperature_logs t2 WHERE t2.location_id = l.id ORDER BY t2.recorded_at DESC, t2.id DESC LIMIT 1)
              WHERE l.is_cold_chain = 1 AND l.is_active = 1 $sSql
              ORDER BY l.name",
            $sP
        );
    }

    // ------------------------------------------------------------------
    // AI assistant prompts per dashboard
    // ------------------------------------------------------------------

    /** @return string[] */
    public static function aiPrompts(string $type): array
    {
        return match ($type) {
            'admin'       => ['Give me an overall stock summary for the hospital.', 'Are there any unusual stock movements?', 'Which departments hold the most stock?'],
            'manager'     => ['What is the total value of our inventory by category?', 'Which department consumed the most supplies this month?', 'How are our suppliers performing on delivery?', 'What stock is likely to expire unused in the next 90 days?'],
            'inventory'   => ['Which products should we reorder this week?', 'Which items could be redistributed between departments?', 'Which products expire within the next 30 days?', 'Are there any unusual stock movements?'],
            'procurement' => ['Which products are below their reorder level?', 'Which suppliers have the best on-time delivery?', 'What should we order this week and how much?'],
            'pharmacist'  => ['Which medicines in the pharmacy are running low?', 'Which pharmacy batches expire in the next 60 days?', 'When will we run out of our most used medicines?'],
            'storekeeper' => ['What stock does my department hold?', 'Which items in my department are running low?', 'Which of my items expire soon?'],
            default       => ['Which medicines are running low?', 'Which products expire within the next 30 days?'],
        };
    }
}
