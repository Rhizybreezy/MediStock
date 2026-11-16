<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Paginator;

final class RecallRepository extends Repository
{
    private const BASE = "SELECT r.*, b.batch_code, b.lot_number, b.expiry_date, b.product_id, p.name AS product_name, p.product_code,
                   u.code AS unit_code, iu.full_name AS initiated_by_name, cu.full_name AS closed_by_name,
                   (SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb JOIN inventory_locations l ON l.id = sb.location_id
                     WHERE sb.batch_id = r.batch_id AND l.type NOT IN ('quarantine')) AS unquarantined_qty
              FROM batch_recalls r
              JOIN product_batches b ON b.id = r.batch_id
              JOIN products p ON p.id = b.product_id
              JOIN units_of_measure u ON u.id = p.base_unit_id
              JOIN users iu ON iu.id = r.initiated_by
              LEFT JOIN users cu ON cu.id = r.closed_by";

    public function search(array $f, int $page): Paginator
    {
        $where = '1=1';
        $params = [];
        if (!empty($f['status'])) {
            $where = 'r.status = :status';
            $params['status'] = $f['status'];
        }
        return $this->paginate(self::BASE . " WHERE $where ORDER BY r.status = 'closed', r.id DESC", "SELECT COUNT(*) FROM batch_recalls r WHERE $where", $params, $page, 25);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE r.id = :id', ['id' => $id]);
    }
}
