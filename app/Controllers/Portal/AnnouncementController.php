<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use App\Services\FileStreamer;

/**
 * Announcements for signed-in users (docs/16): only what their audience includes.
 * Managers may also open drafts and scheduled ones, as a preview.
 */
final class AnnouncementController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): void
    {
        $user = Auth::user();
        $category = (string) $request->input('category', '');
        $category = in_array($category, Announcement::CATEGORIES, true) ? $category : '';
        $total = Announcement::countForUser($user, $category);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('portal/announcements/index', [
            'title'         => 'Announcements',
            'category'      => $category,
            'announcements' => Announcement::forUser($user, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $category),
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
            'canManage'     => Auth::can('announcements.manage'),
        ]);
    }

    public function show(Request $request): void
    {
        $user = Auth::user();
        $id = (int) $request->param('id');
        $announcement = Announcement::find($id);
        $visible = $announcement !== null && Announcement::isVisibleTo($id, $user);
        $preview = !$visible && $announcement !== null && Auth::can('announcements.manage');
        if (!$visible && !$preview) {
            throw HttpException::notFound('That announcement is not available.');
        }

        $this->appView('portal/announcements/show', [
            'title'        => $announcement['title'],
            'announcement' => $announcement,
            'preview'      => $preview ? Announcement::state($announcement) : null,
            'audience'     => Auth::can('announcements.manage') ? Announcement::audienceLabel($announcement) : null,
        ]);
    }

    public function dismiss(Request $request): void
    {
        AnnouncementService::dismiss(Auth::user(), (int) $request->param('id'));
        $this->redirect((string) $request->input('return', '') === 'announcement' ? '/portal/announcements/' . (int) $request->param('id') : Auth::homePath());
    }

    /**
     * The announcement's image, for anyone who may see the announcement. Public ones are
     * open to visitors, so this route has no sign-in gate; the check is here.
     */
    public function image(Request $request): void
    {
        $announcement = Announcement::find((int) $request->param('id'));
        if ($announcement === null || !AnnouncementService::canViewImage(Auth::user(), $announcement)) {
            throw HttpException::notFound('That image is not available.');
        }
        FileStreamer::send($announcement['image_path'], 'announcement-' . $announcement['id'] . '.' . pathinfo($announcement['image_path'], PATHINFO_EXTENSION), $announcement['image_mime'], true);
    }
}
