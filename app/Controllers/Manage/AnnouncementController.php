<?php

declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\Announcement;
use App\Models\Program;
use App\Services\AnnouncementService;

/**
 * Writing and publishing announcements (docs/16). Open to any role holding
 * `announcements.manage` (the Registrar and Super Admin); publishing and archiving
 * also need `announcements.publish`, checked again in AnnouncementService.
 */
final class AnnouncementController extends Controller
{
    public function index(Request $request): void
    {
        $state = (string) $request->input('state', '');
        $q = trim((string) $request->input('q', ''));
        $this->appView('manage/announcements/index', [
            'title'         => 'Announcements',
            'state'         => $state,
            'q'             => $q,
            'announcements' => Announcement::manageList($state, $q),
            'canPublish'    => Auth::can('announcements.publish'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->form(null);
    }

    public function edit(Request $request): void
    {
        $this->form($this->announcement($request));
    }

    public function store(Request $request): void
    {
        $this->persist($request, null);
    }

    public function update(Request $request): void
    {
        $this->persist($request, (int) $this->announcement($request)['id']);
    }

    public function archive(Request $request): void
    {
        $id = (int) $this->announcement($request)['id'];
        try {
            AnnouncementService::archive(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/manage/announcements/{$id}/edit");
            return;
        }
        $this->success('Announcement archived. It no longer appears anywhere.', '/manage/announcements');
    }

    public function restore(Request $request): void
    {
        $id = (int) $this->announcement($request)['id'];
        try {
            AnnouncementService::restore(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/manage/announcements/{$id}/edit");
            return;
        }
        $this->success('Announcement restored as a draft.', "/manage/announcements/{$id}/edit");
    }

    public function destroy(Request $request): void
    {
        $id = (int) $this->announcement($request)['id'];
        try {
            AnnouncementService::delete(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/manage/announcements/{$id}/edit");
            return;
        }
        $this->success('Draft deleted.', '/manage/announcements');
    }

    private function persist(Request $request, ?int $id): void
    {
        $action = (string) $request->input('action', 'draft');
        $action = in_array($action, ['draft', 'publish', 'save'], true) ? $action : 'draft';
        $back = $id === null ? '/manage/announcements/create' : "/manage/announcements/{$id}/edit";

        try {
            $savedId = AnnouncementService::save(Auth::user(), $id, $request->all(), UploadedFile::fromRequest($_FILES['image'] ?? []), $action);
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $saved = Announcement::find($savedId);
        $message = match (Announcement::state($saved)) {
            'SCHEDULED' => 'Announcement scheduled for ' . dt($saved['published_at']) . '. Its audience will be notified then.',
            'PUBLISHED' => $saved['recipient_count'] !== null && $action === 'publish'
                ? 'Announcement published. ' . number_format((int) $saved['recipient_count']) . ' ' . ((int) $saved['recipient_count'] === 1 ? 'person was' : 'people were') . ' notified.'
                : 'Announcement saved.',
            default     => 'Draft saved.',
        };
        $this->success($message, "/manage/announcements/{$savedId}/edit");
    }

    /** @param array<string,mixed>|null $announcement */
    private function form(?array $announcement): void
    {
        $this->appView('manage/announcements/form', [
            'title'        => $announcement === null ? 'New announcement' : 'Edit announcement',
            'announcement' => $announcement,
            'state'        => $announcement === null ? 'NEW' : Announcement::state($announcement),
            'targets'      => $announcement === null ? ['roles' => [], 'programs' => [], 'year_levels' => []] : Announcement::targets((int) $announcement['id']),
            'audienceLabel' => $announcement === null ? '' : Announcement::audienceLabel($announcement),
            'roles'        => Announcement::roleOptions(),
            'programs'     => Program::active(),
            'yearLevels'   => Announcement::yearLevelOptions(),
            'canPublish'   => Auth::can('announcements.publish'),
        ]);
    }

    /** @return array<string,mixed> */
    private function announcement(Request $request): array
    {
        $announcement = Announcement::find((int) $request->param('id'));
        if ($announcement === null) {
            throw HttpException::notFound('That announcement was not found.');
        }
        return $announcement;
    }
}
