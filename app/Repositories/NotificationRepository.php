<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Paginator;

final class NotificationRepository extends Repository
{
    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    public function recent(int $userId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->fetchAll(
            "SELECT id, type, severity, title, message, link_url, is_read, created_at
               FROM notifications WHERE user_id = :u ORDER BY is_read ASC, id DESC LIMIT $limit",
            ['u' => $userId]
        );
    }

    public function search(int $userId, array $f, int $page): Paginator
    {
        $where = ['user_id = :u'];
        $params = ['u' => $userId];
        if (($f['status'] ?? '') === 'unread') {
            $where[] = 'is_read = 0';
        } elseif (($f['status'] ?? '') === 'read') {
            $where[] = 'is_read = 1';
        }
        if (!empty($f['severity'])) {
            $where[] = 'severity = :sev';
            $params['sev'] = $f['severity'];
        }
        if (!empty($f['type'])) {
            $where[] = 'type = :type';
            $params['type'] = $f['type'];
        }
        $w = implode(' AND ', $where);
        return $this->paginate(
            "SELECT * FROM notifications WHERE $w ORDER BY id DESC",
            "SELECT COUNT(*) FROM notifications WHERE $w",
            $params,
            $page,
            25
        );
    }

    public function markRead(int $userId, int $id): bool
    {
        return $this->db->execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :u AND is_read = 0',
            ['id' => $id, 'u' => $userId]
        ) > 0;
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->execute('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = :u AND is_read = 0', ['u' => $userId]);
    }

    /** Insert unless the same dedupe_key already exists for the user. Returns true if created. */
    public function createIfNew(int $userId, array $n): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO notifications (user_id, type, severity, title, message, link_url, entity_type, entity_id, dedupe_key)
             VALUES (:u, :type, :sev, :title, :msg, :link, :etype, :eid, :dk)',
            [
                'u' => $userId, 'type' => $n['type'], 'sev' => $n['severity'] ?? 'info',
                'title' => mb_substr($n['title'], 0, 150), 'msg' => mb_substr($n['message'], 0, 500),
                'link' => $n['link_url'] ?? null, 'etype' => $n['entity_type'] ?? null,
                'eid' => $n['entity_id'] ?? null, 'dk' => mb_substr($n['dedupe_key'], 0, 150),
            ]
        ) > 0;
    }

    public function types(int $userId): array
    {
        return $this->db->fetchColumn('SELECT DISTINCT type FROM notifications WHERE user_id = :u ORDER BY type', ['u' => $userId]);
    }
}
