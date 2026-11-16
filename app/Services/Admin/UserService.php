<?php
declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\BusinessRuleException;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\Support\AuditService;

/**
 * User administration business rules:
 * - users with a department-scoped role must be assigned at least one department
 * - administrators cannot deactivate or lock themselves
 * - the system must always keep at least one active user who can manage users and roles
 */
final class UserService
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private RoleRepository $roles,
        private AuditService $audit,
    ) {
    }

    public function create(array $data, array $roleIds, array $departmentIds, ?int $primaryDept, string $password, bool $mustChange, int $actorId): int
    {
        $this->assertDepartmentsForRoles($roleIds, $departmentIds);
        return $this->db->transaction(function () use ($data, $roleIds, $departmentIds, $primaryDept, $password, $mustChange, $actorId) {
            $id = $this->users->create([
                'full_name'            => $data['full_name'],
                'username'             => $data['username'],
                'email'                => mb_strtolower($data['email']),
                'phone'                => $data['phone'] ?? null,
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'status'               => 'active',
                'must_change_password' => $mustChange ? 1 : 0,
                'created_by'           => $actorId,
            ]);
            $this->users->syncRoles($id, $roleIds);
            $this->users->syncDepartments($id, $departmentIds, $primaryDept);
            $this->audit->log('create', 'user', $id, "Created user {$data['username']}", null, [
                'full_name' => $data['full_name'], 'username' => $data['username'], 'email' => $data['email'],
                'roles' => $roleIds, 'departments' => $departmentIds,
            ]);
            return $id;
        });
    }

    public function update(int $id, array $data, array $roleIds, array $departmentIds, ?int $primaryDept): void
    {
        $existing = $this->users->find($id) ?? throw new BusinessRuleException('User not found.');
        $this->assertDepartmentsForRoles($roleIds, $departmentIds);

        $this->db->transaction(function () use ($id, $existing, $data, $roleIds, $departmentIds, $primaryDept) {
            $oldRoles = $this->users->roleIds($id);
            $oldDepts = array_keys($this->users->departmentAssignments($id));
            $update = [
                'full_name' => $data['full_name'],
                'username'  => $data['username'],
                'email'     => mb_strtolower($data['email']),
                'phone'     => $data['phone'] ?? null,
            ];
            $this->users->update($id, $update);
            $this->users->syncRoles($id, $roleIds);
            $this->users->syncDepartments($id, $departmentIds, $primaryDept);
            $this->assertAdministratorRemains();

            [$old, $new] = AuditService::diff($existing, $update);
            sort($oldRoles);
            $sortedRoles = array_map('intval', $roleIds);
            sort($sortedRoles);
            if ($oldRoles !== $sortedRoles) {
                $old['roles'] = $oldRoles;
                $new['roles'] = $sortedRoles;
            }
            sort($oldDepts);
            $sortedDepts = array_map('intval', $departmentIds);
            sort($sortedDepts);
            if ($oldDepts !== $sortedDepts) {
                $old['departments'] = $oldDepts;
                $new['departments'] = $sortedDepts;
            }
            if ($new) {
                $this->audit->log('update', 'user', $id, "Updated user {$data['username']}", $old, $new);
            }
        });
    }

    /** Activate, deactivate or unlock an account. */
    public function setStatus(int $id, string $status, int $actorId): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new BusinessRuleException('Invalid status.');
        }
        if ($id === $actorId && $status !== 'active') {
            throw new BusinessRuleException('You cannot deactivate your own account.');
        }
        $user = $this->users->find($id) ?? throw new BusinessRuleException('User not found.');
        $this->db->transaction(function () use ($id, $status, $user) {
            $this->users->update($id, ['status' => $status, 'failed_login_count' => 0, 'locked_until' => null]);
            $this->assertAdministratorRemains();
            $action = $user['status'] === 'locked' && $status === 'active' ? 'unlock' : ($status === 'active' ? 'activate' : 'deactivate');
            $this->audit->log($action, 'user', $id, ucfirst($action) . "d user {$user['username']}", ['status' => $user['status']], ['status' => $status]);
        });
    }

    /** Generate a temporary password; the user must change it at next sign-in. */
    public function resetPassword(int $id): string
    {
        $user = $this->users->find($id) ?? throw new BusinessRuleException('User not found.');
        $temp = self::temporaryPassword();
        $this->users->update($id, [
            'password_hash'        => password_hash($temp, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'failed_login_count'   => 0,
        ]);
        $this->audit->log('password_reset', 'user', $id, "Reset password for {$user['username']}");
        return $temp;
    }

    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $pw = '';
        for ($i = 0; $i < 8; $i++) {
            $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        for ($i = 0; $i < 3; $i++) {
            $pw .= $digits[random_int(0, strlen($digits) - 1)];
        }
        return str_shuffle($pw) . '!';
    }

    private function assertDepartmentsForRoles(array $roleIds, array $departmentIds): void
    {
        if (!$roleIds) {
            throw new BusinessRuleException('Assign at least one role.');
        }
        $scopes = $this->roles->scopes($roleIds);
        if (count($scopes) !== count(array_unique($roleIds))) {
            throw new BusinessRuleException('One or more selected roles do not exist.');
        }
        if (in_array('department', $scopes, true) && !$departmentIds) {
            throw new BusinessRuleException('Department-level roles (e.g. Pharmacist, Storekeeper) require at least one assigned department.');
        }
    }

    /** Lockout prevention: someone active must still be able to manage users AND roles. */
    private function assertAdministratorRemains(): void
    {
        $canUsers = $this->users->activeUserIdsWithPermission('users.manage');
        $canRoles = $this->users->activeUserIdsWithPermission('roles.manage');
        if (!array_intersect($canUsers, $canRoles)) {
            throw new BusinessRuleException('This change would leave no active user able to manage users and roles. It was not saved.');
        }
    }
}
