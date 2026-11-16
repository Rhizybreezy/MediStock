<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class ReservationRepository extends Repository
{
    private const BASE = "SELECT r.*, l.name AS location_name, l.department_id, d.name AS department_name,
                   p.name AS product_name, p.product_code, u.code AS unit_code, b.batch_code,
                   ru.full_name AS reserved_by_name, au.full_name AS approved_by_name
              FROM stock_reservations r
              JOIN inventory_locations l ON l.id = r.location_id
              JOIN departments d ON d.id = l.department_id
              JOIN products p ON p.id = r.product_id
              JOIN units_of_measure u ON u.id = p.base_unit_id
              LEFT JOIN product_batches b ON b.id = r.batch_id
              JOIN users ru ON ru.id = r.reserved_by
              LEFT JOIN users au ON au.id = r.approved_by";

    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "l.department_id IN $in";
        }
        if (!empty($f['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $f['status'];
        }
        $w = implode(' AND ', $where);
        return $this->paginate(
            self::BASE . " WHERE $w ORDER BY FIELD(r.status, 'pending','active','released','consumed','rejected'), r.id DESC",
            "SELECT COUNT(*) FROM stock_reservations r JOIN inventory_locations l ON l.id = r.location_id WHERE $w",
            $params, $page, 25
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::BASE . ' WHERE r.id = :id', ['id' => $id]);
    }
}
