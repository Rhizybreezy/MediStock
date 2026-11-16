<?php
declare(strict_types=1);

namespace App\Repositories;

final class CategoryRepository extends Repository
{
    public function allWithCounts(): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM products x WHERE x.category_id = c.id) AS product_count
               FROM inventory_categories c LEFT JOIN inventory_categories p ON p.id = c.parent_id
              ORDER BY c.name'
        );
    }

    public function options(bool $activeOnly = true): array
    {
        return $this->db->fetchAll('SELECT id, code, name, requires_expiry FROM inventory_categories'
            . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM inventory_categories WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->db->insert('inventory_categories', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('inventory_categories', $data, 'id = :id', ['id' => $id]);
    }
}
