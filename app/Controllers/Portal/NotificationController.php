<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Notification;
use App\Services\NotificationService;

/**
 * The signed-in user's notifications inbox (docs/16). Every action is on the user's
 * own notifications; anyone else's is a 404.
 */
final class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): void
    {
        $user = Auth::user();
        $filter = (string) $request->input('filter', 'all');
        $filter = in_array($filter, ['all', 'unread', 'archived'], true) ? $filter : 'all';
        $total = Notification::count($user->id, $filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('portal/notifications/index', [
            'title'         => 'Notifications',
            'filter'        => $filter,
            'notifications' => Notification::inbox($user->id, $filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'counts'        => ['all' => Notification::count($user->id, 'all'), 'unread' => Notification::count($user->id, 'unread'), 'archived' => Notification::count($user->id, 'archived')],
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
        ]);
    }

    /** Mark read, then go where it points (or back to the inbox when it points nowhere safe). */
    public function open(Request $request): void
    {
        $link = NotificationService::open(Auth::user(), (int) $request->param('id'));
        $this->redirect($link ?? '/portal/notifications');
    }

    public function markAllRead(Request $request): void
    {
        $count = NotificationService::markAllRead(Auth::user());
        $this->success($count === 0 ? 'You have no unread notifications.' : "Marked {$count} notification" . ($count === 1 ? '' : 's') . ' as read.', '/portal/notifications');
    }

    public function archive(Request $request): void
    {
        NotificationService::archive(Auth::user(), (int) $request->param('id'));
        $this->success('Notification archived.', '/portal/notifications?filter=' . $this->returnFilter($request));
    }

    public function restore(Request $request): void
    {
        NotificationService::restore(Auth::user(), (int) $request->param('id'));
        $this->success('Notification moved back to your inbox.', '/portal/notifications?filter=archived');
    }

    private function returnFilter(Request $request): string
    {
        $filter = (string) $request->input('filter', 'all');
        return in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
    }
}
