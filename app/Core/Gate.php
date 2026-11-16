<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\AuthorizationException;
use App\Services\Support\AuthService;

/**
 * Authorization = permission × department scope.
 *
 *   can('stock.issue')        → does the user hold the permission at all?
 *   can('stock.issue', 7)     → may the user do it FOR department 7?
 *                               - yes if a GLOBAL-scope role grants it
 *                               - otherwise only if department 7 is assigned to the user
 *   departmentScope('stock.view') → null (all departments) or the list of allowed IDs,
 *                               used by repositories to filter SQL queries.
 *
 * Permissions are loaded from the database on each request, so changes made in
 * Admin → Roles apply immediately.
 */
final class Gate
{
    /** @var array<string, bool>|null permission code => granted by a global role */
    private ?array $permissions = null;

    /** @var int[]|null */
    private ?array $departments = null;

    private ?int $loadedFor = null;

    public function __construct(private Database $db, private AuthService $auth)
    {
    }

    public function can(string $permission, ?int $departmentId = null): bool
    {
        $this->load();
        if (!isset($this->permissions[$permission])) {
            return false;
        }
        if ($departmentId === null || $this->permissions[$permission]) {
            return true;
        }
        return in_array($departmentId, $this->departments, true);
    }

    /** True if the user holds at least one of the given permissions. */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }
        return false;
    }

    /** @throws AuthorizationException */
    public function authorize(string $permission, ?int $departmentId = null): void
    {
        if (!$this->can($permission, $departmentId)) {
            throw new AuthorizationException(
                $departmentId !== null && $this->can($permission)
                    ? 'You are not assigned to this department.'
                    : 'You do not have permission to perform this action.',
                $permission
            );
        }
    }

    /**
     * Departments the user may access for a permission.
     * @return int[]|null null = all departments; [] = none
     */
    public function departmentScope(string $permission): ?array
    {
        $this->load();
        if (!isset($this->permissions[$permission])) {
            return [];
        }
        return $this->permissions[$permission] ? null : $this->departments;
    }

    public function isGlobal(string $permission): bool
    {
        $this->load();
        return ($this->permissions[$permission] ?? false) === true;
    }

    /** @return int[] departments assigned to the user */
    public function userDepartments(): array
    {
        $this->load();
        return $this->departments;
    }

    /** @return string[] */
    public function permissions(): array
    {
        $this->load();
        return array_keys($this->permissions);
    }

    /** Forget cached permissions (after the user's roles change). */
    public function flush(): void
    {
        $this->permissions = null;
        $this->departments = null;
        $this->loadedFor = null;
    }

    private function load(): void
    {
        $userId = $this->auth->id();
        if ($this->permissions !== null && $this->loadedFor === $userId) {
            return;
        }
        $this->loadedFor = $userId;
        $this->permissions = [];
        $this->departments = [];
        if ($userId === null) {
            return;
        }

        $rows = $this->db->fetchAll(
            "SELECT p.code, MAX(r.scope = 'global') AS is_global
               FROM user_roles ur
               JOIN roles r             ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE ur.user_id = :uid
              GROUP BY p.code",
            ['uid' => $userId]
        );
        foreach ($rows as $row) {
            $this->permissions[$row['code']] = (bool) $row['is_global'];
        }

        $this->departments = array_map('intval', $this->db->fetchColumn(
            'SELECT ud.department_id FROM user_departments ud
               JOIN departments d ON d.id = ud.department_id
              WHERE ud.user_id = :uid AND d.is_active = 1',
            ['uid' => $userId]
        ));
    }
}
