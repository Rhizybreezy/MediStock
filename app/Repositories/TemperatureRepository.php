<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class TemperatureRepository extends Repository
{
    /** Cold-chain locations with their latest reading. */
    public function locations(?array $scope): array
    {
        $sql = '';
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $sql = " AND l.department_id IN $in";
        }
        return $this->db->fetchAll(
            "SELECT l.id, l.code, l.name, l.min_temp_c, l.max_temp_c, d.name AS department_name, l.department_id,
                    t.temperature_c AS last_temp, t.recorded_at AS last_at, t.is_out_of_range AS last_out,
                    (SELECT COUNT(*) FROM temperature_logs x WHERE x.location_id = l.id AND x.is_out_of_range = 1 AND x.recorded_at >= NOW() - INTERVAL 30 DAY) AS excursions_30d,
                    (SELECT COUNT(*) FROM stock_balances sb WHERE sb.location_id = l.id AND sb.quantity > 0) AS batches_held
               FROM inventory_locations l JOIN departments d ON d.id = l.department_id
               LEFT JOIN temperature_logs t ON t.id = (SELECT id FROM temperature_logs WHERE location_id = l.id ORDER BY recorded_at DESC, id DESC LIMIT 1)
              WHERE l.is_cold_chain = 1 AND l.is_active = 1 $sql ORDER BY d.name, l.name",
            $params
        );
    }

    public function search(array $f, int $page, ?array $scope): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if ($scope !== null) {
            [$in, $params] = Database::inClause($scope ?: [0], 'sc');
            $where[] = "l.department_id IN $in";
        }
        if (!empty($f['location_id'])) {
            $where[] = 't.location_id = :loc';
            $params['loc'] = (int) $f['location_id'];
        }
        if (($f['range'] ?? '') === 'out') {
            $where[] = 't.is_out_of_range = 1';
        }
        $w = implode(' AND ', $where);
        $from = "FROM temperature_logs t JOIN inventory_locations l ON l.id = t.location_id JOIN users u ON u.id = t.recorded_by WHERE $w";
        return $this->paginate("SELECT t.*, l.name AS location_name, l.min_temp_c, l.max_temp_c, u.full_name AS recorded_by_name $from ORDER BY t.recorded_at DESC, t.id DESC", "SELECT COUNT(*) $from", $params, $page, 30);
    }

    /** Readings for a chart (last N days). */
    public function series(int $locationId, int $days = 14): array
    {
        return $this->db->fetchAll(
            'SELECT recorded_at, temperature_c, is_out_of_range FROM temperature_logs
              WHERE location_id = :l AND recorded_at >= NOW() - INTERVAL :d DAY ORDER BY recorded_at',
            ['l' => $locationId, 'd' => $days]
        );
    }
}
