<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\Announcement;
use App\Models\ClassSchedule;
use App\Services\AnnouncementService;

/**
 * A teacher's announcements to the students of their own classes (docs/16).
 *
 * Only announcements this teacher wrote to classes are reachable here; any other id is a
 * 404. AnnouncementService checks `announcements.class` and class ownership again.
 */
final class AnnouncementController extends Controller
{
    public function index(Request $request): void
    {
        $announcements = Announcement::classListFor(Auth::id());
        $this->appView('teacher/announcements/index', [
            'title'         => 'Class Announcements',
            'announcements' => $announcements,
            'labels'        => Announcement::audienceLabels($announcements),
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
        $this->changeStatus($request, 'archive', 'Announcement archived. Your students no longer see it.');
    }

    public function restore(Request $request): void
    {
        $this->changeStatus($request, 'restore', 'Announcement restored as a draft.');
    }

    public function destroy(Request $request): void
    {
        $this->changeStatus($request, 'delete', 'Draft deleted.');
    }

    private function persist(Request $request, ?int $id): void
    {
        $action = (string) $request->input('action', 'draft');
        $action = in_array($action, ['draft', 'publish', 'save'], true) ? $action : 'draft';
        $back = $id === null ? '/teacher/announcements/create' : "/teacher/announcements/{$id}/edit";
        $input = ['audience' => Announcement::CLASSES, 'is_public' => ''] + $request->all();

        try {
            $savedId = AnnouncementService::save(Auth::user(), $id, $input, UploadedFile::fromRequest($_FILES['image'] ?? []), $action);
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $saved = Announcement::find($savedId);
        $message = match (Announcement::state($saved)) {
            'SCHEDULED' => 'Announcement scheduled for ' . dt($saved['published_at']) . '. Your students will be notified then.',
            'PUBLISHED' => $saved['recipient_count'] !== null && $action === 'publish'
                ? 'Announcement posted. ' . number_format((int) $saved['recipient_count']) . ' ' . ((int) $saved['recipient_count'] === 1 ? 'student was' : 'students were') . ' notified.'
                : 'Announcement saved.',
            default     => 'Draft saved.',
        };
        $this->success($message, "/teacher/announcements/{$savedId}/edit");
    }

    private function changeStatus(Request $request, string $operation, string $message): void
    {
        $id = (int) $this->announcement($request)['id'];
        try {
            match ($operation) {
                'archive' => AnnouncementService::archive(Auth::user(), $id),
                'restore' => AnnouncementService::restore(Auth::user(), $id),
                default   => AnnouncementService::delete(Auth::user(), $id),
            };
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/teacher/announcements/{$id}/edit");
            return;
        }
        $this->success($message, $operation === 'restore' ? "/teacher/announcements/{$id}/edit" : '/teacher/announcements');
    }

    /** @param array<string,mixed>|null $announcement */
    private function form(?array $announcement): void
    {
        $this->appView('teacher/announcements/form', [
            'title'        => $announcement === null ? 'New class announcement' : 'Edit class announcement',
            'announcement' => $announcement,
            'state'        => $announcement === null ? 'NEW' : Announcement::state($announcement),
            'chosen'       => $announcement === null ? [] : Announcement::classTargets((int) $announcement['id']),
            'classes'      => ClassSchedule::forTeacher(Auth::id()),
        ]);
    }

    /** @return array<string,mixed> one of this teacher's own class announcements */
    private function announcement(Request $request): array
    {
        $announcement = Announcement::find((int) $request->param('id'));
        if ($announcement === null || !Announcement::isClassAuthor($announcement, Auth::user())) {
            throw HttpException::notFound('That announcement was not found.');
        }
        return $announcement;
    }
}
