<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

/** Issues, transfers and returns (stock_transfers). */
final class TransferRepository extends Repository
{
    private const BASE = "SELECT t.*, sd.name AS source_department, sl.name AS source_location,
                   dd.name AS destination_department, dl.name AS destination_location,
                   ru.full_name AS requested_by_name, du.full_name AS dispatched_by_name, rcv.full_name AS received_by_name,
                   (SELECT COUNT(DISTINCT i.product_id) FROM stock_transfer_items i WHERE i.stock_transfer_id = t.id) AS product_count,
                   (SELECT COALESCE(SUM(i.dispatched_qty - i.received_qty - i.returned_qty), 0)
                      FROM stock_transfer_items i WHERE i.stock_transfer_id = t.id) AS outstanding_qty
              FROM stock_transfers t
              JOIN departments sd ON sd.id = t.source_department_id
              JOIN inventory_locations sl ON sl.id = t.source_location_id
              JOIN departments dd ON dd.id = t.destination_department_id
              JOIN inventory_locations dl ON dl.id = t.destination_location_id
              JOIN users ru ON ru.id = t.requested_by
              LEFT JOIN users du ON du.id = t.dispatched_by
              LEFT JOIN users rcv ON rcv.id = t.received_by";

    /**
     * @param string $direction 'outgoing' (source in scope), 'incoming' (destination in scope) or 'any'
     */
    public function search(array $f, int $page, ?array $scope, string $direction = 'any'): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (($f['type'] ?? '') === 'transfer_or_return') {
            $where[] = "t.type IN ('transfer','return_to_store','redistribution')";
        } elseif (!empty($f['type'])) {
            $where[] = 't.type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['status']) && $f['status'] !== 'all') {
            if ($f['status'] === 'open') {
                $where[] = "t.status IN ('dispatched','partially_received')";
            } elseif ($f['status'] === 'pending') {
                $where[] = "t.status IN ('pending_approval','approved')";
            } else {
                $where[] = 't.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['q'])) {
            $where[] = 't.transfer_number LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        if ($scope !== null) {
            [$in1, $p1] = Database::inClause($scope, 'so');
            [$in2, $p2] = Database::inClause($scope, 'de');
            $where[] = match ($direction) {
                'outgoing' => "t.source_department_id IN $in1",
                'incoming' => "t.destination_department_id IN $in2",
                default    => "(t.source_department_id IN $in1 OR t.destination_department_id IN $in2)",
            };
            $params += match ($direction) {
                'outgoing' => $p1,
                'incoming' => $p2,
                default    => $p1 + $p2,
            };
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, ['date' => 't.id', 'number' => 't.transfer_number'], 'date', 'desc');
        return $this->paginate(self::BASE . " WHERE $w $order", "SELECT COUNT(*) FROM stock_transfers t WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE t.id = :id', ['id' => $id]);
    }

    public function lockForUpdate(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name, p.product_code, u.code AS unit_code,
                    b.batch_code, b.lot_number, b.expiry_date
               FROM stock_transfer_items i
               JOIN products p ON p.id = i.product_id
               JOIN units_of_measure u ON u.id = p.base_unit_id
               LEFT JOIN product_batches b ON b.id = i.batch_id
              WHERE i.stock_transfer_id = :id ORDER BY p.name, b.expiry_date',
            ['id' => $id]
        );
    }

    public function countIncoming(?array $scope): int
    {
        $sql = "SELECT COUNT(*) FROM stock_transfers WHERE status IN ('dispatched','partially_received')";
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope, 'sc');
            $sql .= " AND destination_department_id IN $in";
        }
        return (int) $this->db->fetchValue($sql, $params);
    }
}
