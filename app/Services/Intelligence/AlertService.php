<?php
declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\DepartmentRepository;
use App\Services\Support\NotificationService;
use App\Services\Support\SettingsService;

/**
 * Scheduled scanners that create de-duplicated in-system notifications.
 * Run by `php bin/console alerts:run` (cron every 15 min) and opportunistically
 * (throttled) when the dashboard is opened.
 *
 * Dedupe keys include the day or week, so a condition is announced once per
 * period, not on every run.
 */
final class AlertService
{
    private const THROTTLE_FILE = '/storage/cache/alerts_last_run';

    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private NotificationService $notify,
        private ForecastService $forecast,
        private ExpiryService $expiry,
        private AnomalyService $anomalies,
        private DepartmentRepository $departments,
        private Logger $logger,
    ) {
    }

    /** Run at most once per $minutes (used from the dashboard). */
    public function runIfDue(int $minutes = 15): void
    {
        $file = BASE_PATH . self::THROTTLE_FILE;
        if (is_file($file) && filemtime($file) > time() - $minutes * 60) {
            return;
        }
        @touch($file);
        @chmod($file, 0666); // shared by the web server and cron/CLI users
        try {
            $this->run();
        } catch (\Throwable $e) {
            $this->logger->error('Alert scan failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, int> notifications created per scanner */
    public function run(): array
    {
        @touch(BASE_PATH . self::THROTTLE_FILE);
        @chmod(BASE_PATH . self::THROTTLE_FILE, 0666);
        $day = date('Y-m-d');
        $week = date('o-W');
        $central = $this->departments->centralStoreId();
        $created = [];

        // Mark batches whose expiry date has passed (FEFO already excludes them by date).
        if ($this->db->execute("UPDATE product_batches SET status = 'expired' WHERE status = 'active' AND expiry_date < CURDATE()") > 0) {
            cache_bump();
        }

        if ($this->settings->get('notify_low_stock', true)) {
            $n = 0;
            foreach ($this->forecast->forecast(null) as $f) {
                if (!in_array($f['urgency'], ['critical', 'high'], true)) {
                    continue;
                }
                $out = $f['usable'] <= 0;
                $title = $out ? "Out of stock: {$f['name']}" : ($f['urgency'] === 'critical' ? "Stock-out risk: {$f['name']}" : "Low stock: {$f['name']}");
                $msg = $out ? 'No usable stock left in the hospital.'
                    : ($f['days_remaining'] !== null ? sprintf('About %s day(s) of stock left (lead time %d days)%s.', round($f['days_remaining'], 1), $f['lead_time'], $f['on_order'] > 0 ? ', some on order' : '') : 'At or below reorder level.');
                $data = ['type' => $out ? 'out_of_stock' : ($f['urgency'] === 'critical' ? 'stockout_risk' : 'low_stock'),
                         'severity' => $f['urgency'] === 'critical' ? 'critical' : 'warning', 'title' => $title, 'message' => $msg,
                         'link_url' => '/intelligence/stockout?q=' . rawurlencode($f['product_code']), 'entity_type' => 'product', 'entity_id' => $f['product_id'],
                         'dedupe_key' => "stock:{$f['product_id']}:{$f['urgency']}:$day"];
                $n += $this->notify->notifyPermission('purchase_requests.create', $data, $central);
                $n += $this->notify->notifyPermission('purchase_orders.manage', $data);
            }
            // Department levels
            foreach ($this->db->fetchAll(
                "SELECT s.department_id, s.product_id, s.reorder_level, COALESCE(ds.usable_qty, 0) AS usable, p.name, d.name AS dept
                   FROM department_product_settings s JOIN products p ON p.id = s.product_id AND p.is_active = 1
                   JOIN departments d ON d.id = s.department_id AND d.is_active = 1
                   LEFT JOIN v_department_stock ds ON ds.department_id = s.department_id AND ds.product_id = s.product_id
                  WHERE s.is_stocked = 1 AND s.reorder_level > 0 AND COALESCE(ds.usable_qty, 0) <= s.reorder_level"
            ) as $r) {
                $n += $this->notify->notifyPermission('requests.create', [
                    'type' => 'low_stock', 'severity' => (float) $r['usable'] <= 0 ? 'critical' : 'warning',
                    'title' => "{$r['dept']}: low on {$r['name']}", 'message' => sprintf('%s left; reorder level %s. Consider a stock request.', qty($r['usable']), qty($r['reorder_level'])),
                    'link_url' => '/departments/' . $r['department_id'] . '?filter=low', 'entity_type' => 'product', 'entity_id' => (int) $r['product_id'],
                    'dedupe_key' => "dept_low:{$r['department_id']}:{$r['product_id']}:$day",
                ], (int) $r['department_id']);
            }
            $created['low_stock'] = $n;
        }

        if ($this->settings->get('notify_expiry', true)) {
            $n = 0;
            $critical = $this->settings->int('expiry_critical_days', 30);
            $warning = $this->settings->int('expiry_warning_days', 90);
            foreach ($this->expiry->analyse(null, $warning)['rows'] as $r) {
                if ($r['at_risk'] <= 0 && (int) $r['days_to_expiry'] > $critical) {
                    continue;
                }
                $isCritical = (int) $r['days_to_expiry'] <= $critical;
                // Staff who act on stock in that department (not every viewer).
                $n += $this->notify->notifyPermission('requests.create', [
                    'type' => 'expiry_warning', 'severity' => $isCritical ? 'critical' : 'warning',
                    'title' => "Expiring " . ($isCritical ? 'soon' : 'within ' . $warning . ' days') . ": {$r['product_name']}",
                    'message' => sprintf('Batch %s at %s expires %s (%d days). %s at risk of expiring unused.', $r['batch_code'], $r['location_name'], format_date($r['expiry_date']), $r['days_to_expiry'], qty($r['at_risk'])),
                    'link_url' => '/intelligence/expiry', 'entity_type' => 'batch', 'entity_id' => (int) $r['batch_id'],
                    'dedupe_key' => "expiry:{$r['batch_id']}:{$r['location_id']}:" . ($isCritical ? $day : $week),
                ], (int) $r['department_id']);
            }
            foreach ($this->expiry->analyse(null)['expired'] as $e) {
                if ($e['location_type'] === 'quarantine') {
                    continue;
                }
                $deptId = (int) $this->db->fetchValue('SELECT department_id FROM inventory_locations WHERE id = :l', ['l' => $e['location_id']]);
                $n += $this->notify->notifyPermission('adjustments.request', [
                    'type' => 'expired', 'severity' => 'critical', 'title' => "Expired stock: {$e['product_name']}",
                    'message' => sprintf('%s of batch %s at %s expired on %s. Do not use; record disposal (Adjustments → Expired stock disposal).', qty($e['quantity']), $e['batch_code'], $e['location_name'], format_date($e['expiry_date'])),
                    'link_url' => '/adjustments/create', 'entity_type' => 'batch', 'entity_id' => (int) $e['batch_id'],
                    'dedupe_key' => "expired:{$e['batch_id']}:{$e['location_id']}:$week",
                ], $deptId);
            }
            $created['expiry'] = $n;
        }

        if ($this->settings->get('notify_delayed_delivery', true)) {
            $n = 0;
            foreach ($this->db->fetchAll(
                "SELECT po.id, po.po_number, po.expected_delivery_date, s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                  WHERE po.status IN ('approved','sent','partially_received') AND po.expected_delivery_date < CURDATE()"
            ) as $po) {
                $n += $this->notify->notifyPermission('purchase_orders.manage', [
                    'type' => 'delivery_delayed', 'severity' => 'warning', 'title' => "Delivery overdue: {$po['po_number']}",
                    'message' => "{$po['name']} was expected to deliver by " . format_date($po['expected_delivery_date']) . '.',
                    'link_url' => '/purchase-orders/' . $po['id'], 'entity_type' => 'purchase_order', 'entity_id' => (int) $po['id'],
                    'dedupe_key' => "po_late:{$po['id']}:$day",
                ]);
            }
            $created['delayed'] = $n;
        }

        // Pending approvals waiting more than 24 hours (daily digest per approver permission).
        $pending = [
            'requests.approve'          => ["SELECT COUNT(*) FROM stock_requests WHERE status = 'submitted' AND submitted_at < NOW() - INTERVAL 1 DAY", 'stock request(s)', '/requests?status=to_review'],
            'transfers.approve'         => ["SELECT COUNT(*) FROM stock_transfers WHERE status = 'pending_approval' AND created_at < NOW() - INTERVAL 1 DAY", 'transfer(s)', '/transfers?status=pending'],
            'adjustments.approve'       => ["SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending' AND created_at < NOW() - INTERVAL 1 DAY", 'adjustment(s)', '/adjustments?status=pending'],
            'purchase_requests.approve' => ["SELECT COUNT(*) FROM purchase_requests WHERE status = 'submitted' AND submitted_at < NOW() - INTERVAL 1 DAY", 'purchase request(s)', '/purchase-requests?status=submitted'],
            'purchase_orders.approve'   => ["SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval' AND updated_at < NOW() - INTERVAL 1 DAY", 'purchase order(s)', '/purchase-orders?status=pending_approval'],
        ];
        $n = 0;
        foreach ($pending as $perm => [$sql, $label, $link]) {
            $count = (int) $this->db->fetchValue($sql);
            if ($count > 0) {
                $n += $this->notify->notifyPermission($perm, [
                    'type' => 'approvals_pending', 'severity' => 'info', 'title' => "$count $label waiting over 24 hours",
                    'message' => 'Please review pending approvals.', 'link_url' => $link, 'dedupe_key' => "pending:$perm:$day",
                ]);
            }
        }
        $created['pending'] = $n;

        if ($this->settings->get('notify_anomalies', true)) {
            $n = 0;
            foreach ($this->anomalies->detect(null) as $a) {
                $data = ['type' => 'anomaly', 'severity' => $a['severity'], 'title' => $a['title'], 'message' => $a['detail'],
                         'link_url' => $a['link'], 'dedupe_key' => 'anomaly:' . $a['key']];
                $n += isset($a['department_id'])
                    ? $this->notify->notifyPermission('stock.confirm_receipt', $data, $a['department_id'])
                    : $this->notify->notifyPermission('adjustments.approve', $data);
            }
            $created['anomalies'] = $n;
        }
        return $created;
    }
}
