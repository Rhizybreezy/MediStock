<?php
declare(strict_types=1);

namespace App\Services\Procurement;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Services\Support\AuditService;
use App\Services\Support\DocumentNumberService;

/** Supplier register and the products/prices each supplier offers. */
final class SupplierService
{
    public function __construct(private Database $db, private DocumentNumberService $numbers, private AuditService $audit)
    {
    }

    public function save(?int $id, array $data, int $userId): int
    {
        if ($id === null) {
            return $this->db->transaction(function () use ($data, $userId) {
                $data['supplier_code'] = $this->numbers->next('SUP', 'SUP', false, 4);
                $data['created_by'] = $userId;
                $id = $this->db->insert('suppliers', $data);
                $this->audit->log('create', 'supplier', $id, "Registered supplier {$data['name']}", null, $data);
                return $id;
            });
        }
        $old = $this->db->fetchOne('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]) ?? throw new BusinessRuleException('Supplier not found.');
        $this->db->update('suppliers', $data, 'id = :id', ['id' => $id]);
        [$o, $n] = AuditService::diff($old, $data);
        if ($n) {
            $this->audit->log('update', 'supplier', $id, "Updated supplier {$data['name']}", $o, $n);
        }
        return $id;
    }

    /** Add or update a product quote for a supplier. Only one preferred supplier per product. */
    public function saveProduct(int $supplierId, array $data): void
    {
        $this->db->transaction(function (Database $db) use ($supplierId, $data) {
            if (!empty($data['is_preferred'])) {
                $db->execute('UPDATE supplier_products SET is_preferred = 0 WHERE product_id = :p', ['p' => $data['product_id']]);
            }
            $old = $db->fetchOne('SELECT * FROM supplier_products WHERE supplier_id = :s AND product_id = :p', ['s' => $supplierId, 'p' => $data['product_id']]);
            $db->execute(
                'INSERT INTO supplier_products (supplier_id, product_id, supplier_sku, quoted_unit_price, quote_valid_until, lead_time_days, is_preferred)
                 VALUES (:s, :p, :sku, :price, :valid, :lead, :pref)
                 ON DUPLICATE KEY UPDATE supplier_sku = VALUES(supplier_sku), quoted_unit_price = VALUES(quoted_unit_price),
                     quote_valid_until = VALUES(quote_valid_until), lead_time_days = VALUES(lead_time_days), is_preferred = VALUES(is_preferred)',
                ['s' => $supplierId, 'p' => $data['product_id'], 'sku' => $data['supplier_sku'], 'price' => $data['quoted_unit_price'],
                 'valid' => $data['quote_valid_until'], 'lead' => $data['lead_time_days'], 'pref' => (int) !empty($data['is_preferred'])]
            );
            // Quote changes are kept in the audit trail (price history of quotes).
            $this->audit->log($old ? 'update' : 'create', 'supplier_product', $supplierId, 'Supplier quote for product #' . $data['product_id'],
                $old ? ['quoted_unit_price' => $old['quoted_unit_price'], 'lead_time_days' => $old['lead_time_days']] : null,
                ['product_id' => $data['product_id'], 'quoted_unit_price' => $data['quoted_unit_price'], 'lead_time_days' => $data['lead_time_days']]);
        });
    }

    public function removeProduct(int $supplierId, int $productId): void
    {
        $this->db->execute('DELETE FROM supplier_products WHERE supplier_id = :s AND product_id = :p', ['s' => $supplierId, 'p' => $productId]);
        $this->audit->log('delete', 'supplier_product', $supplierId, "Removed product #$productId from supplier");
    }
}
