<?php
/**
 * Creates the initial administrator (must change password at first sign-in).
 * With --demo-users, also creates one demo account per role for testing.
 */
use App\Core\Database;

return function (Database $db, array $ctx): void {
    $console = $ctx['console'];
    $roleId = fn (string $code): int => (int) $db->fetchValue('SELECT id FROM roles WHERE code = :c', ['c' => $code]);
    $deptId = fn (string $code): int => (int) $db->fetchValue('SELECT id FROM departments WHERE code = :c', ['c' => $code]);

    $create = function (string $username, string $name, string $email, string $password, string $role, array $depts = []) use ($db, $roleId, $deptId, $console): void {
        if ($db->fetchValue('SELECT id FROM users WHERE username = :u', ['u' => $username])) {
            return;
        }
        $id = $db->insert('users', [
            'full_name' => $name, 'username' => $username, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active', 'must_change_password' => 1,
        ]);
        $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId($role)]);
        foreach ($depts as $i => $code) {
            $db->insert('user_departments', ['user_id' => $id, 'department_id' => $deptId($code), 'is_primary' => $i === 0 ? 1 : 0]);
        }
        $console->line("  created user '$username' ($role) password: $password  (must change at first sign-in)");
    };

    $create('admin', 'System Administrator', 'admin@medistock.local', 'Admin@12345', 'admin', ['CS']);

    if ($ctx['demo_users'] ?? false) {
        $create('manager',     'Demo Hospital Manager',   'management@medistock.local',  'Demo@12345', 'management', ['CS']);
        $create('inventory',   'Demo Inventory Manager',  'inventory@medistock.local',   'Demo@12345', 'inventory_manager', ['CS']);
        $create('pharmacist',  'Demo Pharmacist',         'pharmacist@medistock.local',  'Demo@12345', 'pharmacist', ['PH']);
        $create('maternity',   'Demo Maternity Storekeeper', 'maternity@medistock.local', 'Demo@12345', 'storekeeper', ['MAT']);
        $create('procurement', 'Demo Procurement Officer', 'procurement@medistock.local', 'Demo@12345', 'procurement', ['CS']);
    }
};
