<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Services\Admin\RoleService;
use App\Services\Admin\UserService;

final class PermissionTest extends DatabaseTestCase
{
    private function deptId(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM departments WHERE code = :c', ['c' => $code]);
    }

    public function testGlobalRoleCanActForAnyDepartment(): void
    {
        $this->actingAs('inventory');
        $gate = app(Gate::class);
        $this->assertTrue($gate->can('stock.issue'));
        $this->assertTrue($gate->can('stock.issue', $this->deptId('MAT')));
        $this->assertNull($gate->departmentScope('stock.view'));
    }

    public function testDepartmentRoleIsLimitedToAssignedDepartments(): void
    {
        $this->actingAs('pharmacist');
        $gate = app(Gate::class);
        $this->assertTrue($gate->can('consumption.record', $this->deptId('PH')));
        $this->assertFalse($gate->can('consumption.record', $this->deptId('MAT')));
        $this->assertSame([$this->deptId('PH')], $gate->departmentScope('stock.view'));
        $this->assertSame([], $gate->departmentScope('users.manage'));
    }

    public function testMissingPermissionIsDenied(): void
    {
        $this->actingAs('maternity');
        $this->expectException(AuthorizationException::class);
        app(Gate::class)->authorize('users.manage');
    }

    public function testDepartmentDenialExplainsAssignment(): void
    {
        $this->actingAs('maternity');
        try {
            app(Gate::class)->authorize('consumption.record', $this->deptId('ED'));
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('not assigned', $e->getMessage());
        }
    }

    public function testCannotRemoveLastUserAndRoleManager(): void
    {
        $this->actingAs('admin');
        $adminRole = (int) $this->db->fetchValue("SELECT id FROM roles WHERE code = 'admin'");
        $keep = array_map('intval', $this->db->fetchColumn(
            "SELECT rp.permission_id FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :r AND p.code <> 'roles.manage'", ['r' => $adminRole]
        ));
        $this->expectException(BusinessRuleException::class);
        app(RoleService::class)->update($adminRole, ['name' => 'System Administrator', 'scope' => 'global', 'description' => null], $keep);
    }

    public function testDepartmentRoleRequiresDepartment(): void
    {
        $this->actingAs('admin');
        $storekeeper = (int) $this->db->fetchValue("SELECT id FROM roles WHERE code = 'storekeeper'");
        $this->expectException(BusinessRuleException::class);
        app(UserService::class)->create(
            ['full_name' => 'No Dept', 'username' => 'nodept_' . bin2hex(random_bytes(3)), 'email' => 'nodept@test.local', 'phone' => null],
            [$storekeeper], [], null, 'Password123', true, 1
        );
    }

    public function testCannotDeactivateSelf(): void
    {
        $id = $this->actingAs('admin');
        $this->expectException(BusinessRuleException::class);
        app(UserService::class)->setStatus($id, 'inactive', $id);
    }
}
