<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\InsufficientStockException;
use App\Repositories\LocationRepository;
use App\Repositories\ProductRepository;
use App\Services\Stock\FefoAllocator;

/** JSON lookups used by forms (product picker, units, FEFO preview). */
final class LookupController extends Controller
{
    public function __construct(
        private ProductRepository $products,
        private LocationRepository $locations,
        private FefoAllocator $fefo,
    ) {
    }

    /** GET /api/products/search?q=para[&location_id=5] */
    public function products(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if (mb_strlen($q) < 2) {
            return json([]);
        }
        $locationId = (int) $request->query('location_id', 0);
        $rows = $this->products->lookup($q, 15);
        $out = [];
        foreach ($rows as $r) {
            $item = [
                'id'            => (int) $r['id'],
                'code'          => $r['product_code'],
                'name'          => $r['name'],
                'detail'        => trim(implode(' · ', array_filter([$r['generic_name'], $r['strength'], $r['dosage_form']]))),
                'unit'          => $r['unit_code'],
                'expiry'        => (bool) $r['is_expiry_tracked'],
                'batch_tracked' => (bool) $r['is_batch_tracked'],
                'storage'       => $r['storage_condition'],
                'units'         => $this->products->unitChoices((int) $r['id']),
            ];
            $supplierId = (int) $request->query('supplier_id', 0);
            if ($supplierId > 0) {
                $quote = app(\App\Repositories\SupplierRepository::class)->quote($supplierId, (int) $r['id']);
                $item['quote'] = $quote && $quote['quoted_unit_price'] !== null ? (float) $quote['quoted_unit_price'] : null;
            }
            if ($locationId > 0 && $this->canSeeLocation($locationId)) {
                $item['available'] = array_sum(array_column($this->fefo->candidates((int) $r['id'], $locationId), 'available'));
            }
            $out[] = $item;
        }
        return json($out);
    }

    /** GET /api/stock/allocation?product_id=&location_id=&quantity=&unit_id= — FEFO preview (no locks). */
    public function allocation(Request $request): Response
    {
        $productId = (int) $request->query('product_id');
        $locationId = (int) $request->query('location_id');
        if (!$this->canSeeLocation($locationId)) {
            return json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'Location not available to you.']], 403);
        }
        $candidates = $this->fefo->candidates($productId, $locationId);
        $qty = (float) $request->query('quantity', 0);
        $unitId = (int) $request->query('unit_id', 0);
        foreach ($this->products->unitChoices($productId) as $u) {
            if ($u['unit_id'] === $unitId) {
                $qty *= $u['factor'];
            }
        }
        $allocation = [];
        $error = null;
        if ($qty > 0) {
            try {
                $allocation = $this->fefo->allocate($productId, $locationId, $qty, null, false);
            } catch (InsufficientStockException $e) {
                $error = $e->getMessage();
            }
        }
        return json([
            'available'  => array_sum(array_column($candidates, 'available')),
            'batches'    => $candidates,
            'allocation' => $allocation,
            'base_qty'   => $qty,
            'error'      => $error,
            'critical_days' => (int) setting('expiry_critical_days', 30),
        ]);
    }

    private function canSeeLocation(int $locationId): bool
    {
        $loc = $this->locations->find($locationId);
        return $loc !== null && $this->gate()->can('stock.view', (int) $loc['department_id']);
    }

    /** GET /api/stock/batches?location_id= — every batch with stock at a location (incl. expired), for adjustments. */
    public function batchesAt(Request $request): Response
    {
        $locationId = (int) $request->query('location_id');
        if (!$this->canSeeLocation($locationId)) {
            return json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'Location not available to you.']], 403);
        }
        $rows = app(\App\Core\Database::class)->fetchAll(
            "SELECT b.id, b.batch_code, b.lot_number, b.expiry_date, b.status, p.name AS product_name, u.code AS unit, sb.quantity
               FROM stock_balances sb JOIN product_batches b ON b.id = sb.batch_id
               JOIN products p ON p.id = b.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE sb.location_id = :l AND sb.quantity > 0 ORDER BY p.name, b.expiry_date",
            ['l' => $locationId]
        );
        $today = date('Y-m-d');
        return json(array_map(fn ($r) => [
            'id' => (int) $r['id'], 'label' => "{$r['product_name']} · {$r['batch_code']} (lot {$r['lot_number']})",
            'expiry' => $r['expiry_date'], 'expired' => $r['expiry_date'] !== null && $r['expiry_date'] < $today,
            'status' => $r['status'], 'unit' => $r['unit'], 'quantity' => (float) $r['quantity'],
        ], $rows));
    }

    /** GET /api/batches/search?q= — find a batch by code, lot or product (for recalls). */
    public function batchSearch(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if (mb_strlen($q) < 2) {
            return json([]);
        }
        $like = \App\Core\Database::like($q);
        $rows = app(\App\Core\Database::class)->fetchAll(
            'SELECT b.id, b.batch_code, b.lot_number, b.expiry_date, b.status, p.name AS product_name,
                    (SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE batch_id = b.id) AS on_hand
               FROM product_batches b JOIN products p ON p.id = b.product_id
              WHERE b.batch_code LIKE :q1 OR b.lot_number LIKE :q2 OR p.name LIKE :q3
              ORDER BY b.id DESC LIMIT 15',
            ['q1' => $like, 'q2' => $like, 'q3' => $like]
        );
        return json(array_map(fn ($r) => [
            'id' => (int) $r['id'], 'label' => "{$r['batch_code']} · {$r['product_name']} · lot {$r['lot_number']}",
            'expiry' => $r['expiry_date'], 'status' => $r['status'], 'on_hand' => (float) $r['on_hand'],
        ], $rows));
    }
}
