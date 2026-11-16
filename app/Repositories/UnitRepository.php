<?php
declare(strict_types=1);

namespace App\Repositories;

final class UnitRepository extends Repository
{
    public function allWithCounts(): array
    {
        return $this->db->fetchAll(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM products p WHERE p.base_unit_id = u.id) AS base_count,
                    (SELECT COUNT(*) FROM product_units pu WHERE pu.unit_id = u.id) AS pack_count
               FROM units_of_measure u ORDER BY u.name'
        );
    }

    public function options(bool $activeOnly = true): array
    {
        return $this->db->fetchAll('SELECT id, code, name, allows_decimal FROM units_of_measure'
            . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM units_of_measure WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->db->insert('units_of_measure', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('units_of_measure', $data, 'id = :id', ['id' => $id]);
    }
}
