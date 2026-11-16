<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class SupplierRepository extends Repository
{
    public function search(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(s.name LIKE :q1 OR s.supplier_code LIKE :q2 OR s.contact_person LIKE :q3)';
            $like = Database::like($f['q']);
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (!empty($f['status'])) {
            $where[] = 's.status = :status';
            $params['status'] = $f['status'];
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, ['name' => 's.name', 'code' => 's.supplier_code', 'rating' => 's.rating_cache'], 'name');
        $sql = "SELECT s.*,
                       (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id) AS product_count,
                       (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.status IN ('approved','sent','partially_received')) AS open_pos
                  FROM suppliers s WHERE $w $order";
        return $this->paginate($sql, "SELECT COUNT(*) FROM suppliers s WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT s.*, u.full_name AS created_by_name FROM suppliers s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = :id', ['id' => $id]);
    }

    public function options(bool $activeOnly = true): array
    {
        return $this->db->fetchAll('SELECT id, supplier_code, name, default_lead_time_days FROM suppliers' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY name');
    }

    public function products(int $supplierId): array
    {
        return $this->db->fetchAll(
            'SELECT sp.*, p.name AS product_name, p.product_code, u.code AS unit_code
               FROM supplier_products sp JOIN products p ON p.id = sp.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE sp.supplier_id = :s ORDER BY p.name',
            ['s' => $supplierId]
        );
    }

    /** Quote of a supplier for a product (for price defaults). */
    public function quote(int $supplierId, int $productId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM supplier_products WHERE supplier_id = :s AND product_id = :p', ['s' => $supplierId, 'p' => $productId]);
    }

    /** All supplier quotes per product, keyed by product id (for PR → PO conversion). */
    public function quotesFor(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        [$in, $params] = Database::inClause($productIds);
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT sp.product_id, sp.supplier_id, sp.quoted_unit_price, sp.is_preferred, s.name
               FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id AND s.status = 'active'
              WHERE sp.product_id IN $in ORDER BY sp.is_preferred DESC, sp.quoted_unit_price",
            $params
        ) as $r) {
            $out[(int) $r['product_id']][] = $r;
        }
        return $out;
    }
}
