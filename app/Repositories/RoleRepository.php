<?php
declare(strict_types=1);

namespace App\Repositories;

final class RoleRepository extends Repository
{
    public function allWithCounts(): array
    {
        return $this->db->fetchAll(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r ORDER BY r.is_system DESC, r.name'
        );
    }

    public function options(): array
    {
        return $this->db->fetchAll('SELECT id, code, name, scope FROM roles ORDER BY name');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, array> module => permission rows */
    public function permissionsByModule(): array
    {
        $grouped = [];
        foreach ($this->db->fetchAll('SELECT id, code, module, description FROM permissions ORDER BY module, id') as $p) {
            $grouped[$p['module']][] = $p;
        }
        return $grouped;
    }

    /** @return int[] */
    public function permissionIds(int $roleId): array
    {
        return array_map('intval', $this->db->fetchColumn('SELECT permission_id FROM role_permissions WHERE role_id = :id', ['id' => $roleId]));
    }

    /** @return string[] */
    public function permissionCodes(int $roleId): array
    {
        return $this->db->fetchColumn(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :id ORDER BY p.code',
            ['id' => $roleId]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert('roles', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('roles', $data, 'id = :id', ['id' => $id]);
    }

    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->execute('DELETE FROM role_permissions WHERE role_id = :id', ['id' => $roleId]);
        foreach (array_unique(array_map('intval', $permissionIds)) as $pid) {
            $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $pid]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM roles WHERE id = :id AND is_system = 0', ['id' => $id]);
    }

    public function userCount(int $id): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_roles WHERE role_id = :id', ['id' => $id]);
    }

    /** @return array<int, string> role_id => scope for the given IDs */
    public function scopes(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        [$in, $params] = \App\Core\Database::inClause(array_map('intval', $ids));
        $out = [];
        foreach ($this->db->fetchAll("SELECT id, scope FROM roles WHERE id IN $in", $params) as $r) {
            $out[(int) $r['id']] = $r['scope'];
        }
        return $out;
    }
}
