<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\NotificationRepository;

final class NotificationController extends Controller
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status', 'severity', 'type']);
        return view('notifications/index', [
            'title'   => 'Notifications',
            'page'    => $this->notifications->search($this->userId(), $filters, $this->page($request)),
            'filters' => $filters,
            'types'   => $this->notifications->types($this->userId()),
        ]);
    }

    /** GET /api/notifications — unread count + latest items for the top-bar bell. */
    public function summary(Request $request): Response
    {
        $items = array_map(fn ($n) => [
            'id'       => (int) $n['id'],
            'type'     => $n['type'],
            'severity' => $n['severity'],
            'title'    => $n['title'],
            'message'  => $n['message'],
            'link'     => $n['link_url'] ? url($n['link_url']) : null,
            'is_read'  => (bool) $n['is_read'],
            'ago'      => time_ago($n['created_at']),
        ], $this->notifications->recent($this->userId(), 8));
        return json(['unread' => $this->notifications->unreadCount($this->userId()), 'items' => $items]);
    }

    /** POST /api/notifications/{id}/read */
    public function markRead(Request $request, int $id): Response
    {
        $this->notifications->markRead($this->userId(), $id);
        return json(['unread' => $this->notifications->unreadCount($this->userId())]);
    }

    /** POST /api/notifications/read-all (JSON or form) */
    public function markAllRead(Request $request): Response
    {
        $count = $this->notifications->markAllRead($this->userId());
        if ($request->wantsJson()) {
            return json(['unread' => 0, 'marked' => $count]);
        }
        flash('success', "$count notification(s) marked as read.");
        return redirect($request->previousUrl('/notifications'));
    }
}
