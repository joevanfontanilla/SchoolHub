<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\Download;
use App\Models\Program;
use App\Models\RequirementType;
use App\Models\Term;
use App\Services\FileStreamer;

/**
 * The public website (docs/06). No authentication, no personal data.
 */
final class PublicController extends Controller
{
    public function home(Request $request): void
    {
        $this->publicView('public/home', [
            'title'         => 'Home',
            'programs'      => Program::active(),
            'announcements' => Announcement::publicList(3),
        ]);
    }

    public function about(Request $request): void
    {
        $this->publicView('public/about', ['title' => 'About']);
    }

    public function programs(Request $request): void
    {
        $this->publicView('public/programs', [
            'title'    => 'Programs',
            'programs' => Program::active(),
        ]);
    }

    public function enrollment(Request $request): void
    {
        $this->publicView('public/enrollment', [
            'title'     => 'Enrollment',
            'openTerms' => Term::openForEnrollment(),
        ]);
    }

    public function requirements(Request $request): void
    {
        $this->publicView('public/requirements', [
            'title'        => 'Requirements',
            'requirementGroups' => \App\Services\PublicRequirementsService::groups(),
        ]);
    }

    public function announcements(Request $request): void
    {
        $perPage = 10;
        $page = max(1, (int) $request->input('page', 1));
        $total = Announcement::publicCount();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $this->publicView('public/announcements', [
            'title'         => 'Announcements',
            'announcements' => Announcement::publicList($perPage, ($page - 1) * $perPage),
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
        ]);
    }

    public function announcement(Request $request): void
    {
        $slug = (string) $request->param('slug');
        $announcement = Announcement::findPublicBySlug($slug);

        if ($announcement === null) {
            throw HttpException::notFound('That announcement is no longer available.');
        }

        $this->publicView('public/announcement-show', [
            'title'        => $announcement['title'],
            'announcement' => $announcement,
        ]);
    }

    public function downloads(Request $request): void
    {
        $this->publicView('public/downloads', [
            'title'     => 'Downloads',
            'downloads' => Download::publicList(),
        ]);
    }

    public function contact(Request $request): void
    {
        $this->publicView('public/contact', ['title' => 'Contact']);
    }

    /**
     * Stream a published document.
     *
     * The file lives under storage/ and is never linked directly — this route
     * checks that it is public, then streams it (docs/10).
     */
    public function download(Request $request): void
    {
        $id = (int) $request->param('id');
        $download = Download::find($id);

        if ($download === null || (int) $download['is_public'] !== 1) {
            throw HttpException::notFound('That document is no longer available.');
        }

        Download::recordHit($id);

        FileStreamer::send(
            'uploads/downloads/' . $download['stored_filename'],
            $download['original_filename'],
            $download['mime_type'] ?? null
        );
    }
}
