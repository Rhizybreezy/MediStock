<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Support\AuditService;

final class RoleService
{
    public function __construct(
        private Database $db,
        private RoleRepository $roles,
        private UserRepository $users,
        private AuditService $audit,
    ) {
    }

    public function create(array $data, array $permissionIds): int
    {
        return $this->db->transaction(function () use ($data, $permissionIds) {
            $code = $this->uniqueCode($data['name']);
            $id = $this->roles->create([
                'code' => $code, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'scope' => $data['scope'], 'is_system' => 0,
            ]);
            $this->roles->syncPermissions($id, $permissionIds);
            $this->audit->log('create', 'role', $id, "Created role {$data['name']}", null, [
                'name' => $data['name'], 'scope' => $data['scope'], 'permissions' => $this->roles->permissionCodes($id),
            ]);
            return $id;
        });
    }

    public function update(int $id, array $data, array $permissionIds): void
    {
        $role = $this->roles->find($id) ?? throw new BusinessRuleException('Role not found.');
        $this->db->transaction(function () use ($id, $role, $data, $permissionIds) {
            $oldPerms = $this->roles->permissionCodes($id);
            $update = ['description' => $data['description'] ?? null, 'scope' => $data['scope'], 'name' => $data['name']];
            $this->roles->update($id, $update);
            $this->roles->syncPermissions($id, $permissionIds);
            $newPerms = $this->roles->permissionCodes($id);

            $canUsers = $this->users->activeUserIdsWithPermission('users.manage');
            $canRoles = $this->users->activeUserIdsWithPermission('roles.manage');
            if (!array_intersect($canUsers, $canRoles)) {
                throw new BusinessRuleException('This change would leave no active user able to manage users and roles. It was not saved.');
            }

            [$old, $new] = AuditService::diff($role, $update);
            $removed = array_values(array_diff($oldPerms, $newPerms));
            $added = array_values(array_diff($newPerms, $oldPerms));
            if ($removed || $added) {
                $old['permissions_removed'] = $removed;
                $new['permissions_added'] = $added;
            }
            if ($new || $old) {
                $this->audit->log('update', 'role', $id, "Updated role {$data['name']}", $old, $new);
            }
        });
    }

    public function delete(int $id): void
    {
        $role = $this->roles->find($id) ?? throw new BusinessRuleException('Role not found.');
        if ((int) $role['is_system'] === 1) {
            throw new BusinessRuleException('System roles cannot be deleted.');
        }
        if ($this->roles->userCount($id) > 0) {
            throw new BusinessRuleException('This role is assigned to users. Remove it from all users first.');
        }
        $this->roles->delete($id);
        $this->audit->log('delete', 'role', $id, "Deleted role {$role['name']}", ['name' => $role['name']]);
    }

    private function uniqueCode(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_') ?: 'role';
        $base = substr($base, 0, 40);
        $code = $base;
        $i = 2;
        while ($this->db->fetchValue('SELECT 1 FROM roles WHERE code = :c', ['c' => $code])) {
            $code = $base . '_' . $i++;
        }
        return $code;
    }
}
