<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class StockRequestRepository extends Repository
{
    private const BASE = "SELECT r.*, rd.name AS requesting_department, sd.name AS supplying_department, l.name AS destination_location,
                   ru.full_name AS requested_by_name, rv.full_name AS reviewed_by_name,
                   (SELECT COUNT(*) FROM stock_request_items i WHERE i.stock_request_id = r.id) AS item_count
              FROM stock_requests r
              JOIN departments rd ON rd.id = r.requesting_department_id
              JOIN departments sd ON sd.id = r.supplying_department_id
              JOIN inventory_locations l ON l.id = r.destination_location_id
              JOIN users ru ON ru.id = r.requested_by
              LEFT JOIN users rv ON rv.id = r.reviewed_by";

    /**
     * @param ?array $requestScope departments whose OWN requests the user may see (requests.create scope)
     * @param ?array $supplyScope  departments the user reviews/supplies for (requests.approve / stock.issue scope)
     */
    public function search(array $f, int $page, ?array $requestScope, ?array $supplyScope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($requestScope !== null || $supplyScope !== null) {
            $parts = [];
            if ($requestScope === null || $supplyScope === null) {
                $parts[] = '1=1';
            } else {
                [$in1, $p1] = Database::inClause($requestScope ?: [0], 'rq');
                [$in2, $p2] = Database::inClause($supplyScope ?: [0], 'sp');
                $parts[] = "r.requesting_department_id IN $in1";
                $parts[] = "r.supplying_department_id IN $in2";
                $params += $p1 + $p2;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (!empty($f['status'])) {
            $map = ['open' => "r.status IN ('submitted','approved','partially_approved','partially_issued','issued')", 'to_review' => "r.status = 'submitted'", 'to_issue' => "r.status IN ('approved','partially_approved','partially_issued')"];
            if (isset($map[$f['status']])) {
                $where[] = $map[$f['status']];
            } else {
                $where[] = 'r.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['department_id'])) {
            $where[] = 'r.requesting_department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        if (!empty($f['priority'])) {
            $where[] = 'r.priority = :prio';
            $params['prio'] = $f['priority'];
        }
        if (!empty($f['q'])) {
            $where[] = 'r.request_number LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'date' => 'r.id', 'number' => 'r.request_number', 'priority' => "FIELD(r.priority, 'emergency','urgent','routine')", 'needed' => 'r.needed_by',
        ], 'date', 'desc');
        return $this->paginate(self::BASE . " WHERE $w $order", "SELECT COUNT(*) FROM stock_requests r WHERE $w", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE r.id = :id', ['id' => $id]);
    }

    public function items(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS product_name, p.product_code, u.code AS unit_code
               FROM stock_request_items i JOIN products p ON p.id = i.product_id JOIN units_of_measure u ON u.id = p.base_unit_id
              WHERE i.stock_request_id = :id ORDER BY p.name',
            ['id' => $id]
        );
    }

    public function issues(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT t.id, t.transfer_number, t.status, t.dispatched_at, t.received_at, u.full_name AS dispatched_by_name
               FROM stock_transfers t LEFT JOIN users u ON u.id = t.dispatched_by
              WHERE t.stock_request_id = :id ORDER BY t.id',
            ['id' => $id]
        );
    }
}
