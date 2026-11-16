<?php
declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Database;
use App\Services\Support\AuthService;

/**
 * Decides which role dashboard a user sees.
 *
 * Each system role has its own dashboard. A user with several roles gets the first
 * match in ROLE_DASHBOARDS order (administrator first). Users who hold only custom
 * roles get the general, permission-filtered overview.
 */
final class DashboardResolver
{
    /** role code => dashboard type, in priority order */
    public const ROLE_DASHBOARDS = [
        'admin'             => 'admin',
        'management'        => 'manager',
        'inventory_manager' => 'inventory',
        'procurement'       => 'procurement',
        'pharmacist'        => 'pharmacist',
        'storekeeper'       => 'storekeeper',
    ];

    public const TITLES = [
        'admin'       => 'Administrator dashboard',
        'manager'     => 'Management dashboard',
        'inventory'   => 'Inventory operations',
        'procurement' => 'Procurement dashboard',
        'pharmacist'  => 'Pharmacy dashboard',
        'storekeeper' => 'Department store dashboard',
        'general'     => 'Dashboard',
    ];

    public function __construct(private Database $db, private AuthService $auth)
    {
    }

    public function type(?int $userId = null): string
    {
        $userId ??= $this->auth->id();
        if (!$userId) {
            return 'general';
        }
        $codes = $this->db->fetchColumn(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :u',
            ['u' => $userId]
        );
        foreach (self::ROLE_DASHBOARDS as $role => $type) {
            if (in_array($role, $codes, true)) {
                return $type;
            }
        }
        return 'general';
    }
}
