<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

/** Read access to the stock ledger (stock_movements + stock_transactions). */
final class MovementRepository extends Repository
{
    private const SELECT = "SELECT m.id, m.movement_type, m.quantity, m.unit_cost, m.created_at, m.reason,
                   m.from_location_id, m.to_location_id,
                   t.txn_number, t.type AS txn_type, t.reference_type, t.reference_id, t.approval_reference,
                   p.id AS product_id, p.name AS product_name, p.product_code, u.code AS unit_code,
                   b.id AS batch_id, b.batch_code, b.lot_number, b.expiry_date,
                   fl.name AS from_location, fd.name AS from_department,
                   tl.name AS to_location, td.name AS to_department,
                   us.full_name AS user_name";

    private const FROM = " FROM stock_movements m
              JOIN stock_transactions t       ON t.id = m.transaction_id
              JOIN products p                 ON p.id = m.product_id
              JOIN units_of_measure u         ON u.id = p.base_unit_id
              JOIN product_batches b          ON b.id = m.batch_id
              LEFT JOIN inventory_locations fl ON fl.id = m.from_location_id
              LEFT JOIN departments fd         ON fd.id = fl.department_id
              LEFT JOIN inventory_locations tl ON tl.id = m.to_location_id
              LEFT JOIN departments td         ON td.id = tl.department_id
              JOIN users us                    ON us.id = m.performed_by";

    public function search(array $f, int $page, ?array $scope, int $perPage = 50): Paginator
    {
        [$where, $params] = $this->where($f, $scope);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'date' => 'm.id', 'product' => 'p.name', 'type' => 'm.movement_type', 'qty' => 'm.quantity',
        ], 'date', 'desc');
        return $this->paginate(
            self::SELECT . self::FROM . " WHERE $where $order",
            'SELECT COUNT(*) ' . self::FROM . " WHERE $where",
            $params,
            $page,
            $perPage
        );
    }

    public function forBatch(int $batchId, ?array $scope, int $limit = 100): array
    {
        [$where, $params] = $this->where(['batch_id' => $batchId], $scope);
        return $this->db->fetchAll(self::SELECT . self::FROM . " WHERE $where ORDER BY m.id DESC LIMIT " . (int) $limit, $params);
    }

    public function forProduct(int $productId, ?array $scope, int $limit = 15): array
    {
        [$where, $params] = $this->where(['product_id' => $productId], $scope);
        return $this->db->fetchAll(self::SELECT . self::FROM . " WHERE $where ORDER BY m.id DESC LIMIT " . (int) $limit, $params);
    }

    public function forTransaction(string $referenceType, int $referenceId): array
    {
        return $this->db->fetchAll(
            self::SELECT . self::FROM . ' WHERE t.reference_type = :rt AND t.reference_id = :rid ORDER BY m.id',
            ['rt' => $referenceType, 'rid' => $referenceId]
        );
    }

    private function where(array $f, ?array $scope): array
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in1, $p1] = Database::inClause($scope, 'sf');
            [$in2, $p2] = Database::inClause($scope, 'st');
            $where[] = "(fl.department_id IN $in1 OR tl.department_id IN $in2)";
            $params += $p1 + $p2;
        }
        if (!empty($f['q'])) {
            $where[] = '(p.name LIKE :q1 OR p.product_code LIKE :q2 OR b.batch_code LIKE :q3 OR t.txn_number LIKE :q4)';
            $like = Database::like($f['q']);
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        foreach (['product_id' => 'm.product_id', 'batch_id' => 'm.batch_id', 'user_id' => 'm.performed_by'] as $key => $col) {
            if (!empty($f[$key])) {
                $where[] = "$col = :$key";
                $params[$key] = (int) $f[$key];
            }
        }
        if (!empty($f['type'])) {
            $where[] = 'm.movement_type = :mtype';
            $params['mtype'] = $f['type'];
        }
        if (!empty($f['department_id'])) {
            $where[] = '(fl.department_id = :d1 OR tl.department_id = :d2)';
            $params['d1'] = $params['d2'] = (int) $f['department_id'];
        }
        if (!empty($f['location_id'])) {
            $where[] = '(m.from_location_id = :l1 OR m.to_location_id = :l2)';
            $params['l1'] = $params['l2'] = (int) $f['location_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'm.created_at >= :dfrom';
            $params['dfrom'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'm.created_at <= :dto';
            $params['dto'] = $f['to'] . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }
}
