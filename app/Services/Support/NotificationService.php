<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;

/**
 * Creates de-duplicated in-system notifications.
 * Phase 5 adds the scheduled scanners (low stock, expiry, delays ...) that call notifyPermission().
 */
final class NotificationService
{
    public function __construct(private NotificationRepository $notifications, private UserRepository $users)
    {
    }

    /** Notify one user. Returns true if a new notification was created. */
    public function notifyUser(int $userId, array $notification): bool
    {
        return $this->notifications->createIfNew($userId, $notification);
    }

    /**
     * Notify every active user holding a permission (optionally only those who may
     * act for a given department). Returns the number of notifications created.
     */
    public function notifyPermission(string $permission, array $notification, ?int $departmentId = null): int
    {
        $created = 0;
        foreach ($this->users->activeUserIdsWithPermission($permission) as $userId) {
            if ($departmentId !== null && !$this->userCoversDepartment($userId, $permission, $departmentId)) {
                continue;
            }
            $created += $this->notifications->createIfNew($userId, $notification) ? 1 : 0;
        }
        return $created;
    }

    private function userCoversDepartment(int $userId, string $permission, int $departmentId): bool
    {
        $db = app(\App\Core\Database::class);
        return (bool) $db->fetchValue(
            "SELECT 1 FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id AND p.code = :perm
              WHERE ur.user_id = :u
                AND (r.scope = 'global' OR EXISTS (SELECT 1 FROM user_departments ud WHERE ud.user_id = :u2 AND ud.department_id = :d))
              LIMIT 1",
            ['perm' => $permission, 'u' => $userId, 'u2' => $userId, 'd' => $departmentId]
        );
    }
}
