<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Paginator;

final class AuditLogRepository extends Repository
{
    public function search(array $f, int $page, int $perPage = 50): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['user_id'])) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = (int) $f['user_id'];
        }
        if (!empty($f['action'])) {
            $where[] = 'a.action = :action';
            $params['action'] = $f['action'];
        }
        if (!empty($f['entity_type'])) {
            $where[] = 'a.entity_type = :etype';
            $params['etype'] = $f['entity_type'];
        }
        if (!empty($f['from'])) {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'a.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['q'])) {
            $where[] = 'a.description LIKE :q';
            $params['q'] = Database::like($f['q']);
        }
        $w = implode(' AND ', $where);
        $order = self::orderBy($f['sort'] ?? null, $f['dir'] ?? null, [
            'date' => 'a.id', 'user' => 'u.full_name', 'action' => 'a.action',
        ], 'date', 'desc');
        $sql = "SELECT a.id, a.action, a.entity_type, a.entity_id, a.description, a.created_at,
                       INET6_NTOA(a.ip_address) AS ip, u.full_name AS user_name, u.username
                  FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                 WHERE $w $order";
        return $this->paginate($sql, "SELECT COUNT(*) FROM audit_logs a WHERE $w", $params, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT a.*, INET6_NTOA(a.ip_address) AS ip, u.full_name AS user_name, u.username
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = :id',
            ['id' => $id]
        );
    }

    public function distinctActions(): array
    {
        return $this->db->fetchColumn('SELECT DISTINCT action FROM audit_logs ORDER BY action');
    }

    public function distinctEntityTypes(): array
    {
        return $this->db->fetchColumn('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type');
    }

    public function userOptions(): array
    {
        return $this->db->fetchAll('SELECT id, full_name, username FROM users ORDER BY full_name');
    }
}
