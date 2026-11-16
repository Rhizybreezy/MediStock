<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Core\Database;
use App\Core\Session;
use App\Services\Department\ConsumptionService;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\IssueService;
use App\Services\Stock\ReceivingService;

/**
 * DEVELOPMENT ONLY — generates realistic, back-dated inventory history so that
 * forecasts, expiry risk, anomalies and charts have data to work with.
 *
 * Every operation goes through the normal services (ReceivingService,
 * IssueService, ConsumptionService → StockService), so the ledger and balances
 * stay consistent. Afterwards the timestamps of the created records are moved
 * back to the simulated date. Refuses to run unless APP_ENV=development.
 *
 * Simulation per day:
 *   - each department consumes its products (weekday/weekend pattern, random variation,
 *     seasonal malaria peak for antimalarials and test kits)
 *   - weekly: departments are topped up from the central store (issue + receipt)
 *   - weekly: the central store receives deliveries when below reorder level
 */
final class DemoHistoryGenerator
{
    private const USAGE = [
        // category => departments using it and their share of hospital demand
        'MED' => ['PH' => 0.45, 'IPD' => 0.25, 'ED' => 0.15, 'MAT' => 0.15],
        'PPE' => ['ED' => 0.25, 'IPD' => 0.25, 'MAT' => 0.2, 'OT' => 0.2, 'LAB' => 0.1],
        'GMC' => ['ED' => 0.35, 'IPD' => 0.35, 'OPD' => 0.15, 'MAT' => 0.15],
        'SUR' => ['OT' => 0.6, 'MAT' => 0.25, 'ED' => 0.15],
        'LAB' => ['LAB' => 0.85, 'OPD' => 0.15],
        'CLN' => ['IPD' => 0.4, 'ED' => 0.2, 'MAT' => 0.2, 'OT' => 0.2],
    ];

    private array $log = [];
    private array $markers = [];

    public function __construct(
        private Database $db,
        private ReceivingService $receiving,
        private IssueService $issues,
        private ConsumptionService $consumption,
        private FefoAllocator $fefo,
    ) {
    }

    public function run(int $days, callable $progress): array
    {
        if (env('APP_ENV') !== 'development') {
            throw new \RuntimeException('The demo generator only runs when APP_ENV=development.');
        }
        mt_srand(20261113);
        $userId = (int) $this->db->fetchValue("SELECT id FROM users WHERE username = 'inventory'")
            ?: throw new \RuntimeException('Run db:seed --demo-users first (needs the demo inventory manager).');
        $_SESSION['user_id'] = $userId; // act as the inventory manager (global permissions)
        app(\App\Core\Gate::class)->flush();

        $loc = fn (string $code) => (int) $this->db->fetchValue('SELECT id FROM inventory_locations WHERE code = :c', ['c' => $code]);
        $central = $loc('CS-MAIN');
        $coldCentral = $loc('CS-COLD');
        $supplier = $this->db->fetchValue("SELECT id FROM suppliers WHERE status = 'active' ORDER BY id LIMIT 1");
        $products = $this->db->fetchAll(
            "SELECT p.id, p.name, p.reorder_level, p.max_level, p.storage_condition, p.is_expiry_tracked, c.code AS cat
               FROM products p JOIN inventory_categories c ON c.id = p.category_id
              WHERE p.is_active = 1 AND c.code IN ('MED','PPE','GMC','SUR','LAB','CLN') AND p.reorder_level > 0"
        );
        if (!$products) {
            throw new \RuntimeException('No products with reorder levels. Run db:seed --demo-data first.');
        }
        $deptLoc = [];
        foreach (['PH' => 'PH-MAIN', 'IPD' => 'IPD-MAIN', 'ED' => 'ED-MAIN', 'MAT' => 'MAT-MAIN', 'OT' => 'OT-MAIN', 'LAB' => 'LAB-MAIN', 'OPD' => 'OPD-MAIN'] as $d => $c) {
            $deptLoc[$d] = $loc($c);
        }
        $deptColdLoc = ['PH' => $loc('PH-FRIDGE'), 'LAB' => $loc('LAB-FRIDGE')];

        // Daily hospital demand ≈ reorder level ÷ 20 (reorder levels were set for ~3 weeks of use).
        $plan = [];
        foreach ($products as $p) {
            $cold = in_array($p['storage_condition'], ['refrigerated', 'frozen'], true);
            $users = self::USAGE[$p['cat']];
            if ($cold) { // only departments with fridges
                $users = array_intersect_key(['PH' => 0.8, 'LAB' => 0.2], $users + ['PH' => 1, 'LAB' => 1]);
            }
            $plan[(int) $p['id']] = $p + ['daily' => max(1, (float) $p['reorder_level'] / 20), 'users' => $users, 'cold' => $cold,
                'slow' => mt_rand(1, 6) === 1, 'malaria' => (bool) preg_match('/artemether|malaria/i', $p['name'])];
        }

        // Department stock levels (≈ 1 week reorder, 3 weeks max) so department alerts and redistribution work.
        foreach ($plan as $pid => $p) {
            foreach ($p['users'] as $dept => $share) {
                $deptId = (int) $this->db->fetchValue('SELECT department_id FROM inventory_locations WHERE id = :l', ['l' => $deptLoc[$dept]]);
                $daily = $p['daily'] * $share * ($p['slow'] ? 0.25 : 1);
                $this->db->execute(
                    'INSERT IGNORE INTO department_product_settings (department_id, product_id, min_level, reorder_level, max_level, is_stocked)
                     VALUES (:d, :p, :mn, :ro, :mx, 1)',
                    ['d' => $deptId, 'p' => $pid, 'mn' => ceil($daily * 3), 'ro' => ceil($daily * 7), 'mx' => ceil($daily * 21)]
                );
            }
        }

        $start = strtotime("-$days days");
        for ($d = 0; $d <= $days; $d++) {
            $ts = $start + $d * 86400;
            $date = date('Y-m-d', $ts);
            $dow = (int) date('N', $ts);

            // Monday: central receipts for anything below reorder level, then department top-ups.
            if ($dow === 1 || $d === 0) {
                foreach ($plan as $pid => $p) {
                    $srcLoc = $p['cold'] ? $coldCentral : $central;
                    $usable = $this->usable($pid, $srcLoc);
                    if ($usable <= (float) $p['reorder_level'] || $d === 0) {
                        $qty = max((float) $p['max_level'] - $usable, $p['daily'] * 30);
                        $months = $p['slow'] ? mt_rand(5, 9) : mt_rand(14, 30);
                        $expiry = date('Y-m-d', strtotime("$date +$months months"));
                        $this->receive($pid, $srcLoc, round($qty), $expiry, $supplier ? (int) $supplier : null, $date, $userId);
                    }
                }
                foreach ($deptLoc as $dept => $locId) {
                    $lines = [];
                    $coldLines = [];
                    foreach ($plan as $pid => $p) {
                        if (!isset($p['users'][$dept])) {
                            continue;
                        }
                        $target = $p['daily'] * $p['users'][$dept] * 14 * ($p['slow'] ? 2.5 : 1);
                        $dest = $p['cold'] ? ($deptColdLoc[$dept] ?? null) : $locId;
                        if (!$dest) {
                            continue;
                        }
                        $have = $this->usable($pid, $dest);
                        $need = round($target - $have);
                        $avail = $this->usable($pid, $p['cold'] ? $coldCentral : $central);
                        $need = min($need, floor($avail * 0.8));
                        if ($need > 0) {
                            if ($p['cold']) {
                                $coldLines[] = ['product_id' => $pid, 'quantity' => $need];
                            } else {
                                $lines[] = ['product_id' => $pid, 'quantity' => $need];
                            }
                        }
                    }
                    if ($lines) {
                        $this->issueAndReceive($central, $locId, $lines, $date, $userId);
                    }
                    if ($coldLines && isset($deptColdLoc[$dept])) {
                        $this->issueAndReceive($coldCentral, $deptColdLoc[$dept], $coldLines, $date, $userId);
                    }
                }
            }

            // Daily consumption per department.
            $monthFactor = in_array((int) date('n', $ts), [4, 5, 6], true) ? 1.6 : 1.0;
            foreach ($deptLoc as $dept => $locId) {
                $lines = [];
                foreach ($plan as $pid => $p) {
                    if (!isset($p['users'][$dept])) {
                        continue;
                    }
                    $src = $p['cold'] ? ($deptColdLoc[$dept] ?? null) : $locId;
                    if (!$src) {
                        continue;
                    }
                    $mean = $p['daily'] * $p['users'][$dept] * ($dow >= 6 ? 0.6 : 1.1) * ($p['malaria'] ? $monthFactor : 1) * ($p['slow'] ? 0.25 : 1);
                    $qty = round($mean * (0.6 + mt_rand(0, 80) / 100));
                    if ($d === $days - 3 && mt_rand(1, 12) === 1) {
                        $qty *= 6; // occasional spike for anomaly detection
                    }
                    $have = $this->usable($pid, $src);
                    $qty = min($qty, floor($have));
                    if ($qty >= 1) {
                        $lines[] = ['product_id' => $pid, 'quantity' => $qty, 'src' => $src];
                    }
                }
                foreach ($this->groupBy($lines, 'src') as $src => $group) {
                    $this->consume((int) $src, $group, $date, $userId);
                }
            }
            if ($d % 30 === 0) {
                $progress($date, $d, $days);
            }
        }
        $this->backdate();
        return $this->log;
    }

    private function usable(int $productId, int $locationId): float
    {
        return array_sum(array_column($this->fefo->candidates($productId, $locationId), 'available'));
    }

    private function receive(int $pid, int $locId, float $qty, string $expiry, ?int $supplier, string $date, int $userId): void
    {
        if ($qty < 1) {
            return;
        }
        $cost = (float) ($this->db->fetchValue('SELECT quoted_unit_price FROM supplier_products WHERE product_id = :p LIMIT 1', ['p' => $pid]) ?: (mt_rand(5, 300) / 100));
        [$grn] = $this->receiving->receive(
            ['receipt_type' => 'direct', 'receiving_location_id' => $locId, 'supplier_id' => $supplier, 'submission_token' => bin2hex(random_bytes(16)), 'notes' => 'Demo history'],
            [['product_id' => $pid, 'lot_number' => 'DEMO-' . strtoupper(substr(md5($pid . $date . $locId), 0, 6)), 'quantity' => $qty, 'unit_id' => null,
              'expiry_date' => max($expiry, date('Y-m-d', strtotime('+1 day'))), 'manufacture_date' => null, 'unit_cost' => $cost]],
            $userId
        );
        $this->markers[] = ['grn', $grn, $date . ' 09:' . sprintf('%02d', mt_rand(0, 59)) . ':00'];
        if ($expiry <= date('Y-m-d', strtotime('+1 day'))) {
            // Receiving refuses already-expired stock, so the real (past) expiry is applied after the simulation.
            $this->markers[] = ['expiry', $grn, $expiry];
        }
        $this->log['receipts'] = ($this->log['receipts'] ?? 0) + 1;
    }

    private function issueAndReceive(int $from, int $to, array $lines, string $date, int $userId): void
    {
        try {
            $tid = $this->issues->dispatch(['source_location_id' => $from, 'destination_location_id' => $to, 'reason' => 'Weekly top-up (demo)'], $lines, $userId);
        } catch (\App\Exceptions\BusinessRuleException) {
            return;
        }
        $received = [];
        foreach ($this->db->fetchAll('SELECT id, dispatched_qty FROM stock_transfer_items WHERE stock_transfer_id = :t', ['t' => $tid]) as $r) {
            $received[(int) $r['id']] = (float) $r['dispatched_qty'];
        }
        $this->issues->confirmReceipt($tid, $received, null, $userId);
        $this->markers[] = ['transfer', $tid, $date . ' 10:30:00'];
        $this->log['issues'] = ($this->log['issues'] ?? 0) + 1;
    }

    private function consume(int $locId, array $lines, string $date, int $userId): void
    {
        try {
            $txn = $this->consumption->record(['location_id' => $locId, 'purpose' => 'patient_care', 'notes' => 'Demo history'],
                array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $lines), $userId);
        } catch (\App\Exceptions\BusinessRuleException) {
            return;
        }
        $this->markers[] = ['txn', $txn, $date . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 19), mt_rand(0, 59))];
        $this->log['consumption'] = ($this->log['consumption'] ?? 0) + 1;
    }

    /** Move timestamps of everything created back to the simulated dates. */
    private function backdate(): void
    {
        // The ledger trigger (migration 0013) only allows created_at changes with this session flag.
        $this->db->execute('SET @medistock_allow_backdate = 1');
        try {
            $this->applyBackdates();
        } finally {
            $this->db->execute('SET @medistock_allow_backdate = 0');
        }
    }

    private function applyBackdates(): void
    {
        foreach ($this->markers as [$kind, $id, $at]) {
            if ($kind === 'expiry') {
                $this->db->execute("UPDATE product_batches b JOIN goods_receipt_items i ON i.batch_id = b.id SET b.expiry_date = :e,
                    b.status = IF(b.status = 'active', 'expired', b.status) WHERE i.goods_receipt_id = :id", ['e' => $at, 'id' => $id]);
                continue;
            }
            if ($kind === 'txn') {
                $this->db->execute('UPDATE stock_transactions SET created_at = :a WHERE id = :id', ['a' => $at, 'id' => $id]);
                $this->db->execute('UPDATE stock_movements SET created_at = :a WHERE transaction_id = :id', ['a' => $at, 'id' => $id]);
            } elseif ($kind === 'grn') {
                $this->db->execute('UPDATE goods_receipts SET received_at = :a, posted_at = :a2, created_at = :a3 WHERE id = :id', ['a' => $at, 'a2' => $at, 'a3' => $at, 'id' => $id]);
                $this->db->execute('UPDATE product_batches b JOIN goods_receipt_items i ON i.batch_id = b.id SET b.received_at = :a WHERE i.goods_receipt_id = :id AND b.received_at > :a2', ['a' => $at, 'a2' => $at, 'id' => $id]);
                $this->db->execute("UPDATE stock_transactions SET created_at = :a WHERE reference_type = 'goods_receipt' AND reference_id = :id", ['a' => $at, 'id' => $id]);
                $this->db->execute("UPDATE stock_movements m JOIN stock_transactions t ON t.id = m.transaction_id SET m.created_at = :a WHERE t.reference_type = 'goods_receipt' AND t.reference_id = :id", ['a' => $at, 'id' => $id]);
            } else {
                $this->db->execute('UPDATE stock_transfers SET created_at = :a, approved_at = :a2, dispatched_at = :a3, received_at = :a4 WHERE id = :id', ['a' => $at, 'a2' => $at, 'a3' => $at, 'a4' => $at, 'id' => $id]);
                $this->db->execute("UPDATE stock_transactions SET created_at = :a WHERE reference_type = 'stock_transfer' AND reference_id = :id", ['a' => $at, 'id' => $id]);
                $this->db->execute("UPDATE stock_movements m JOIN stock_transactions t ON t.id = m.transaction_id SET m.created_at = :a WHERE t.reference_type = 'stock_transfer' AND t.reference_id = :id", ['a' => $at, 'id' => $id]);
            }
        }
        // Remove the "incoming stock" notifications generated by the simulated issues.
        $ids = array_map(fn ($m) => (int) $m[1], array_filter($this->markers, fn ($m) => $m[0] === 'transfer'));
        foreach (array_chunk($ids, 500) as $chunk) {
            [$in, $params] = Database::inClause(array_map(fn ($i) => 'transfer_dispatched:' . $i, $chunk), 'dk');
            $this->db->execute("DELETE FROM notifications WHERE dedupe_key IN $in", $params);
        }
    }

    private function groupBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[$key]][] = $r;
        }
        return $out;
    }
}
