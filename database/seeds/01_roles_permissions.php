<?php
/**
 * Seeds the permission catalogue and the six system roles from config/permissions.php.
 * Idempotent: existing roles keep any extra permissions an administrator added.
 */
use App\Core\Database;

return function (Database $db): void {
    $catalogue = require BASE_PATH . '/config/permissions.php';

    $existingCodes = $db->fetchColumn("SELECT code FROM permissions");
    $newCodes = array_diff(array_keys($catalogue["permissions"]), $existingCodes);

    foreach ($catalogue['permissions'] as $code => [$module, $description]) {
        $db->execute(
            'INSERT INTO permissions (code, module, description) VALUES (:c, :m, :d)
             ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description)',
            ['c' => $code, 'm' => $module, 'd' => $description]
        );
    }

    foreach ($catalogue['roles'] as $code => $role) {
        $existing = $db->fetchValue('SELECT id FROM roles WHERE code = :c', ['c' => $code]);
        if ($existing) {
            $roleId = (int) $existing;
            // Permissions added to the catalogue after the role was created are granted to
            // system roles that list them by default. Admin-made changes are never removed.
            foreach (array_intersect($role['permissions'], $newCodes) as $permCode) {
                $db->execute(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT :r, id FROM permissions WHERE code = :p',
                    ['r' => $roleId, 'p' => $permCode]
                );
            }
        } else {
            $roleId = $db->insert('roles', [
                'code' => $code, 'name' => $role['name'], 'description' => $role['description'],
                'scope' => $role['scope'], 'is_system' => 1,
            ]);
            // Default permissions are only applied when the role is first created.
            foreach ($role['permissions'] as $permCode) {
                $db->execute(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT :r, id FROM permissions WHERE code = :p',
                    ['r' => $roleId, 'p' => $permCode]
                );
            }
        }
    }
    // The administrator role must always be able to manage roles and users (lockout prevention).
    $db->execute(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('roles.manage','users.manage')
          WHERE r.code = 'admin'"
    );
};
