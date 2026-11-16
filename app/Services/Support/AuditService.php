<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Core\App;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use Throwable;

/**
 * Writes the append-only audit trail (audit_logs).
 * Sensitive fields are stripped before old/new values are stored.
 */
final class AuditService
{
    private const SENSITIVE = ['password', 'password_hash', 'password_confirmation', 'current_password', '_csrf', 'api_key'];

    public function __construct(private Database $db, private Logger $logger)
    {
    }

    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): void {
        try {
            $request = App::instance()->request ?? null;
            $ip = $request ? (@inet_pton($request->ip()) ?: null) : null;
            $this->db->insert('audit_logs', [
                'user_id'     => $userId ?? (Session::get('user_id') !== null ? (int) Session::get('user_id') : null),
                'action'      => mb_substr($action, 0, 60),
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'description' => $description !== null ? mb_substr($description, 0, 255) : null,
                'old_values'  => $old !== null ? $this->encode($old) : null,
                'new_values'  => $new !== null ? $this->encode($new) : null,
                'ip_address'  => $ip,
                'user_agent'  => $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the user's operation, but failures are logged.
            $this->logger->error('Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /** Only the keys whose values changed (for compact update entries). */
    public static function diff(array $old, array $new): array
    {
        $changedOld = [];
        $changedNew = [];
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old) || (string) $old[$key] !== (string) $value) {
                $changedOld[$key] = $old[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }
        return [$changedOld, $changedNew];
    }

    private function encode(array $values): string
    {
        foreach (self::SENSITIVE as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
