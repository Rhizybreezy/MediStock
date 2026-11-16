<?php
declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;
use App\Services\Support\UploadService;

/**
 * Product registration rules:
 * - product codes are generated per category (MED-000001) and never reused
 * - min ≤ reorder ≤ max (max 0 = no maximum)
 * - pack units: factor > 1, no duplicates, not the base unit, unique GTIN barcodes
 * - the base unit cannot change once stock has been recorded (quantities are stored in it)
 */
final class ProductService
{
    public function __construct(
        private Database $db,
        private ProductRepository $products,
        private CategoryRepository $categories,
        private DocumentNumberService $numbers,
        private UploadService $uploads,
        private AuditService $audit,
    ) {
    }

    public function create(array $data, array $packUnits, ?array $image, int $userId): int
    {
        $this->assertLevels($data);
        $this->assertPackUnits($packUnits, (int) $data['base_unit_id'], null);
        $imagePath = $image ? $this->uploads->storeImage($image, 'products') : null;
        try {
            return $this->db->transaction(function () use ($data, $packUnits, $imagePath, $userId) {
                $category = $this->categories->find((int) $data['category_id']) ?? throw new BusinessRuleException('Category not found.');
                $data['product_code'] = $this->numbers->next('P-' . $category['code'], $category['code'], false);
                $data['created_by'] = $userId;
                $data['image_path'] = $imagePath;
                $id = $this->products->create($data);
                $this->products->syncPackUnits($id, $packUnits);
                cache_bump();
                $this->audit->log('create', 'product', $id, "Registered product {$data['product_code']} {$data['name']}", null, $data + ['pack_units' => $packUnits]);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->uploads->delete($imagePath);
            throw $e;
        }
    }

    public function update(int $id, array $data, array $packUnits, ?array $image, bool $removeImage): void
    {
        $old = $this->products->find($id) ?? throw new BusinessRuleException('Product not found.');
        $this->assertLevels($data);
        $this->assertPackUnits($packUnits, (int) $data['base_unit_id'], $id);
        if ((int) $old['base_unit_id'] !== (int) $data['base_unit_id'] && $this->hasStockHistory($id)) {
            throw new BusinessRuleException('The base unit cannot be changed after stock has been recorded, because all quantities are stored in it.');
        }
        $newImage = $image ? $this->uploads->storeImage($image, 'products') : null;
        if ($newImage || $removeImage) {
            $data['image_path'] = $newImage;
        }
        try {
            $this->db->transaction(function () use ($id, $old, $data, $packUnits) {
                $oldUnits = $this->products->packUnits($id);
                $this->products->update($id, $data);
                $this->products->syncPackUnits($id, $packUnits);
                $compareOld = array_intersect_key($old, $data);
                [$o, $n] = AuditService::diff($compareOld, $data);
                $simplify = fn ($rows) => array_map(fn ($r) => [(int) $r['unit_id'], (float) $r['factor_to_base'], $r['gtin_barcode'] ?: null], $rows);
                if ($simplify($oldUnits) != $simplify($packUnits)) {
                    $o['pack_units'] = $simplify($oldUnits);
                    $n['pack_units'] = $simplify($packUnits);
                }
                if ($n) {
                    $this->audit->log('update', 'product', $id, "Updated product {$old['product_code']}", $o, $n);
                }
            });
        } catch (\Throwable $e) {
            $this->uploads->delete($newImage);
            throw $e;
        }
        if ($newImage || $removeImage) {
            $this->uploads->delete($old['image_path']);
        }
        cache_bump();
    }

    public function toggle(int $id): bool
    {
        $product = $this->products->find($id) ?? throw new BusinessRuleException('Product not found.');
        $activate = (int) $product['is_active'] === 0;
        $this->products->update($id, ['is_active' => $activate ? 1 : 0]);
        cache_bump();
        $this->audit->log($activate ? 'activate' : 'deactivate', 'product', $id, ($activate ? 'Activated' : 'Deactivated') . " product {$product['product_code']}");
        return $activate;
    }

    private function hasStockHistory(int $id): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM product_batches WHERE product_id = :id LIMIT 1', ['id' => $id]);
    }

    private function assertLevels(array $d): void
    {
        $min = (float) $d['min_level'];
        $reorder = (float) $d['reorder_level'];
        $max = (float) $d['max_level'];
        if ($reorder < $min) {
            throw new BusinessRuleException('Reorder level must be greater than or equal to the minimum level.');
        }
        if ($max > 0 && $max < $reorder) {
            throw new BusinessRuleException('Maximum level must be greater than or equal to the reorder level (or 0 for no maximum).');
        }
    }

    private function assertPackUnits(array $units, int $baseUnitId, ?int $productId): void
    {
        $seen = [];
        foreach ($units as $u) {
            if ((int) $u['unit_id'] === $baseUnitId) {
                throw new BusinessRuleException('A pack unit cannot be the same as the base unit.');
            }
            if (isset($seen[(int) $u['unit_id']])) {
                throw new BusinessRuleException('Each pack unit can only be listed once.');
            }
            $seen[(int) $u['unit_id']] = true;
            if ((float) $u['factor_to_base'] <= 1) {
                throw new BusinessRuleException('Pack unit conversion must be greater than 1 base unit.');
            }
            if (!empty($u['gtin_barcode'])) {
                $dup = $this->db->fetchValue(
                    'SELECT p.name FROM product_units pu JOIN products p ON p.id = pu.product_id
                      WHERE pu.gtin_barcode = :g AND pu.product_id <> :p',
                    ['g' => $u['gtin_barcode'], 'p' => $productId ?? 0]
                );
                if ($dup) {
                    throw new BusinessRuleException("Barcode {$u['gtin_barcode']} is already used by $dup.");
                }
            }
        }
    }
}
