<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notifications of the signed-in admin: list, unread count, mark read.
 *
 * Every query is scoped to the caller's own notifications.
 */
class NotificationController extends AdminController
{
    /**
     * Paginated own notifications, newest first; `unread=1` keeps unread rows only.
     *
     * @param Request $request Incoming request.
     * @return mixed Admin API response.
     */
    public function index(Request $request): mixed
    {
        $user = $request->user();
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $query = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $page = $query->paginate($perPage)->through(fn (DatabaseNotification $row): array => $this->present($row));

        // Same envelope as fromPaginator(), plus unread_count in meta.
        return ires()->data([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Number of own unread notifications.
     *
     * @param Request $request Incoming request.
     * @return mixed Admin API response.
     */
    public function unreadCount(Request $request): mixed
    {
        return ires()->data(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * Mark one own notification as read; another user's id is a 404.
     *
     * @param Request $request Incoming request.
     * @param string $id Notification uuid.
     * @return mixed Admin API response.
     */
    public function read(Request $request, string $id): mixed
    {
        $row = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $row->markAsRead();

        return ires()->data($this->present($row->refresh()));
    }

    /**
     * Mark all own unread notifications as read.
     *
     * @param Request $request Incoming request.
     * @return mixed Admin API response.
     */
    public function readAll(Request $request): mixed
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ires()->data(['updated' => $updated]);
    }

    /**
     * Shape one notification row for the API.
     *
     * @param DatabaseNotification $row Notification row.
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->data['type'] ?? $row->type,
            'payload' => $row->data['payload'] ?? [],
            'read_at' => $row->read_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
