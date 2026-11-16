<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class UserRepository extends Repository
{
    public function search(array $f, int $page, int $perPage = 25): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(u.full_name LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3)';
            $params += ['q1' => Database::like($f['q']), 'q2' => Database::like($f['q']), 'q3' => Database::like($f['q'])];
        }
        if (!empty($f['status'])) {
            $where[] = 'u.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['role_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles x WHERE x.user_id = u.id AND x.role_id = :role)';
            $params['role'] = (int) $f['role_id'];
        }
        if (!empty($f['department_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_departments y WHERE y.user_id = u.id AND y.department_id = :dept)';
            $params['dept'] = (int) $f['department_id'];
        }
        $whereSql = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'name' => 'u.full_name', 'username' => 'u.username', 'status' => 'u.status',
            'last_login' => 'u.last_login_at', 'created' => 'u.created_at',
        ], 'name');

        $sql = "SELECT u.id, u.full_name, u.username, u.email, u.status, u.last_login_at, u.created_at,
                       u.must_change_password, u.failed_login_count,
                       (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ')
                          FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                       (SELECT GROUP_CONCAT(d.name ORDER BY ud.is_primary DESC, d.name SEPARATOR ', ')
                          FROM user_departments ud JOIN departments d ON d.id = ud.department_id WHERE ud.user_id = u.id) AS departments
                  FROM users u WHERE $whereSql $order";
        return $this->paginate($sql, "SELECT COUNT(*) FROM users u WHERE $whereSql", $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, full_name, username, email, phone, status, must_change_password, failed_login_count,
                    last_login_at, created_at, updated_at, password_changed_at
               FROM users WHERE id = :id',
            ['id' => $id]
        );
    }

    /** @return int[] */
    public function roleIds(int $userId): array
    {
        return array_map('intval', $this->db->fetchColumn('SELECT role_id FROM user_roles WHERE user_id = :id', ['id' => $userId]));
    }

    /** @return array<int, int> department_id => is_primary */
    public function departmentAssignments(int $userId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT department_id, is_primary FROM user_departments WHERE user_id = :id', ['id' => $userId]) as $row) {
            $out[(int) $row['department_id']] = (int) $row['is_primary'];
        }
        return $out;
    }

    public function create(array $data): int
    {
        return $this->db->insert('users', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('users', $data, 'id = :id', ['id' => $id]);
    }

    public function syncRoles(int $userId, array $roleIds): void
    {
        $this->db->execute('DELETE FROM user_roles WHERE user_id = :id', ['id' => $userId]);
        foreach (array_unique($roleIds) as $roleId) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $roleId]);
        }
    }

    public function syncDepartments(int $userId, array $departmentIds, ?int $primaryId): void
    {
        $this->db->execute('DELETE FROM user_departments WHERE user_id = :id', ['id' => $userId]);
        $departmentIds = array_values(array_unique(array_map('intval', $departmentIds)));
        if ($primaryId === null || !in_array($primaryId, $departmentIds, true)) {
            $primaryId = $departmentIds[0] ?? null;
        }
        foreach ($departmentIds as $deptId) {
            $this->db->insert('user_departments', [
                'user_id' => $userId, 'department_id' => $deptId, 'is_primary' => $deptId === $primaryId ? 1 : 0,
            ]);
        }
    }

    /** Active users holding a permission (used for lockout prevention and notifications). */
    public function activeUserIdsWithPermission(string $permission): array
    {
        return array_map('intval', $this->db->fetchColumn(
            "SELECT DISTINCT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.code = :p AND u.status = 'active'",
            ['p' => $permission]
        ));
    }

    public function statusCounts(): array
    {
        $out = ['active' => 0, 'inactive' => 0, 'locked' => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) c FROM users GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }
}
