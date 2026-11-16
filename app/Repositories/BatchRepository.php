<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class BatchRepository extends Repository
{
    /**
     * Batches with their on-hand quantity (within scope).
     * Filters: q, product_id, department_id, expiry (expired|30|90|180|none), status, show (in_stock|all)
     */
    public function search(array $f, int $page, ?array $scope, int $perPage = 25): Paginator
    {
        $params = [];
        $balanceScope = '';
        if ($scope !== null) {
            [$in, $sp] = Database::inClause($scope, 'sc');
            $balanceScope .= " AND l.department_id IN $in";
            $params += $sp;
        }
        if (!empty($f['department_id'])) {
            $balanceScope .= ' AND l.department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        $where = ['1=1'];
        if (!empty($f['q'])) {
            $where[] = '(b.batch_code LIKE :q1 OR b.lot_number LIKE :q2 OR p.name LIKE :q3 OR p.product_code LIKE :q4)';
            $like = Database::like($f['q']);
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if (!empty($f['product_id'])) {
            $where[] = 'b.product_id = :pid';
            $params['pid'] = (int) $f['product_id'];
        }
        if (!empty($f['status'])) {
            $where[] = 'b.status = :status';
            $params['status'] = $f['status'];
        }
        switch ($f['expiry'] ?? '') {
            case 'expired':
                $where[] = 'b.expiry_date < CURDATE()';
                break;
            case '30': case '90': case '180':
                $where[] = 'b.expiry_date >= CURDATE() AND b.expiry_date <= CURDATE() + INTERVAL ' . (int) $f['expiry'] . ' DAY';
                break;
            case 'none':
                $where[] = 'b.expiry_date IS NULL';
                break;
        }
        $having = ($f['show'] ?? 'in_stock') === 'all' && $scope === null && empty($f['department_id']) ? '' : 'HAVING on_hand > 0';
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'expiry' => 'b.expiry_date IS NULL, b.expiry_date', 'product' => 'p.name', 'batch' => 'b.batch_code',
            'received' => 'b.received_at', 'qty' => 'on_hand',
        ], 'expiry');

        $base = "SELECT b.id, b.batch_code, b.lot_number, b.expiry_date, b.manufacture_date, b.status, b.unit_cost,
                        b.received_at, b.quantity_received, DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
                        p.id AS product_id, p.name AS product_name, p.product_code, u.code AS unit_code,
                        s.name AS supplier_name,
                        COALESCE(SUM(x.quantity), 0) AS on_hand,
                        COUNT(DISTINCT CASE WHEN x.quantity > 0 THEN x.location_id END) AS location_count
                   FROM product_batches b
                   JOIN products p         ON p.id = b.product_id
                   JOIN units_of_measure u ON u.id = p.base_unit_id
                   LEFT JOIN suppliers s   ON s.id = b.supplier_id
                   LEFT JOIN (
                        SELECT sb.batch_id, sb.location_id, sb.quantity
                          FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id
                         WHERE sb.quantity > 0 $balanceScope
                   ) x ON x.batch_id = b.id
                  WHERE $w
                  GROUP BY b.id $having";
        return $this->paginate("$base $order", "SELECT COUNT(*) FROM ($base) t", $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT b.*, DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
                    p.name AS product_name, p.product_code, p.storage_condition, u.code AS unit_code,
                    s.name AS supplier_name, cu.full_name AS created_by_name
               FROM product_batches b
               JOIN products p         ON p.id = b.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN suppliers s   ON s.id = b.supplier_id
               LEFT JOIN users cu      ON cu.id = b.created_by
              WHERE b.id = :id',
            ['id' => $id]
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->fetchOne('SELECT id FROM product_batches WHERE batch_code = :c', ['c' => $code]);
    }

    /** Where the batch is now (balance per location), within scope. */
    public function locations(int $batchId, ?array $scope): array
    {
        $sql = '';
        $params = ['b' => $batchId];
        if ($scope !== null) {
            [$in, $sp] = Database::inClause($scope, 'sc');
            $sql = " AND l.department_id IN $in";
            $params += $sp;
        }
        return $this->db->fetchAll(
            "SELECT l.id, l.name AS location_name, l.type, d.name AS department_name, sb.quantity, sb.updated_at
               FROM stock_balances sb
               JOIN inventory_locations l ON l.id = sb.location_id
               JOIN departments d ON d.id = l.department_id
              WHERE sb.batch_id = :b AND sb.quantity > 0 $sql
              ORDER BY d.is_central_store DESC, d.name, l.name",
            $params
        );
    }

    /** Batches of a product with stock (any location within scope), FEFO order. */
    public function forProduct(int $productId, ?array $scope): array
    {
        $sql = '';
        $params = ['p' => $productId];
        if ($scope !== null) {
            [$in, $sp] = Database::inClause($scope, 'sc');
            $sql = " AND l.department_id IN $in";
            $params += $sp;
        }
        return $this->db->fetchAll(
            "SELECT b.id, b.batch_code, b.lot_number, b.expiry_date, b.status, b.unit_cost, b.received_at,
                    DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
                    SUM(sb.quantity) AS on_hand
               FROM product_batches b
               JOIN stock_balances sb     ON sb.batch_id = b.id AND sb.quantity > 0
               JOIN inventory_locations l ON l.id = sb.location_id
              WHERE b.product_id = :p $sql
              GROUP BY b.id
              ORDER BY b.expiry_date IS NULL, b.expiry_date, b.received_at",
            $params
        );
    }

    /** Existing batch for the same product + lot + supplier (NULL-safe). */
    public function findLot(int $productId, string $lot, ?int $supplierId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM product_batches WHERE product_id = :p AND lot_number = :l AND supplier_id <=> :s FOR UPDATE',
            ['p' => $productId, 'l' => $lot, 's' => $supplierId]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert('product_batches', $data);
    }
}
