<?php
declare(strict_types=1);

namespace App\Services\Codes;

use App\Core\Database;
use App\Core\Gate;
use App\Repositories\BatchRepository;
use App\Repositories\ProductRepository;

/**
 * Turns a scanned code into everything a screen needs: the product (in the same
 * shape as the product picker), the batch with clear warnings for expired or
 * recalled stock, and stock levels limited to the user's departments.
 */
final class ScanService
{
    public function __construct(
        private Database $db,
        private Gate $gate,
        private CodeService $codes,
        private ProductRepository $products,
        private BatchRepository $batches,
    ) {
    }

    public function lookup(string $raw): array
    {
        $r = $this->codes->resolve($raw);
        if ($r['type'] === 'unknown') {
            return ['type' => 'unknown', 'code' => $r['code'], 'message' => 'This code is not recognised. Check the label or search manually.'];
        }
        $batch = null;
        if ($r['type'] === 'batch') {
            $b = $this->batches->find($r['batch_id']);
            $productId = (int) $b['product_id'];
            $expired = $b['expiry_date'] !== null && $b['expiry_date'] < date('Y-m-d');
            $blocked = $expired || in_array($b['status'], ['recalled', 'quarantined', 'expired'], true);
            $batch = [
                'id' => (int) $b['id'], 'code' => $b['batch_code'], 'lot' => $b['lot_number'], 'expiry' => $b['expiry_date'],
                'days_to_expiry' => $b['days_to_expiry'] !== null ? (int) $b['days_to_expiry'] : null, 'status' => $b['status'],
                'blocked' => $blocked,
                'warning' => match (true) {
                    $b['status'] === 'recalled' => 'RECALLED — do not use. Keep aside and follow the recall instructions.',
                    $expired || $b['status'] === 'expired' => 'EXPIRED — do not use. Record disposal according to hospital policy.',
                    $b['status'] === 'quarantined' => 'QUARANTINED — not available for use.',
                    $b['days_to_expiry'] !== null && (int) $b['days_to_expiry'] <= (int) setting('expiry_critical_days', 30) => 'Expires in ' . (int) $b['days_to_expiry'] . ' day(s) — use first.',
                    default => null,
                },
                'url' => route('inventory.batches.show', ['id' => $b['id']]),
            ];
        } else {
            $productId = $r['product_id'];
        }
        $p = $this->products->find($productId);
        $result = [
            'type' => $r['type'], 'code' => $r['code'], 'matched_by' => $r['matched_by'],
            'product' => [
                'id' => (int) $p['id'], 'code' => $p['product_code'], 'name' => $p['name'],
                'detail' => trim(implode(' · ', array_filter([$p['generic_name'], $p['strength'], $p['dosage_form']]))),
                'unit' => $p['unit_code'], 'expiry' => (bool) $p['is_expiry_tracked'], 'batch_tracked' => (bool) $p['is_batch_tracked'],
                'storage' => $p['storage_condition'], 'is_active' => (bool) $p['is_active'],
                'units' => $this->products->unitChoices((int) $p['id']), 'url' => route('products.show', ['id' => $p['id']]),
            ],
            'batch' => $batch,
            'stock' => null,
        ];
        if ($this->gate->can('stock.view')) {
            $scope = $this->gate->departmentScope('stock.view');
            [$sql, $params] = ['', []];
            if ($scope !== null) {
                [$in, $params] = Database::inClause($scope ?: [0], 'sc');
                $sql = " AND l.department_id IN $in";
            }
            $filter = $batch ? 'sb.batch_id = :id' : 'sb.product_id = :id';
            $result['stock'] = array_map(fn ($s) => [
                'location' => $s['location_name'], 'department' => $s['department_name'], 'type' => $s['type'],
                'quantity' => (float) $s['qty'], 'usable' => (float) $s['usable'], 'location_id' => (int) $s['location_id'],
            ], $this->db->fetchAll(
                "SELECT l.id AS location_id, l.name AS location_name, l.type, d.name AS department_name, SUM(sb.quantity) AS qty,
                        SUM(CASE WHEN b.status = 'active' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) AND l.type NOT IN ('quarantine','transit') THEN sb.quantity ELSE 0 END) AS usable
                   FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id JOIN departments d ON d.id = l.department_id
                   JOIN product_batches b ON b.id = sb.batch_id
                  WHERE $filter AND sb.quantity > 0 $sql GROUP BY l.id ORDER BY d.is_central_store DESC, d.name, l.name",
                array_merge(['id' => $batch ? $batch['id'] : $productId], $params)
            ));
        }
        return $result;
    }
}
