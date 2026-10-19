<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Services\DownloadService;
use App\Services\FileStreamer;

/** The files on the public Downloads page (docs/06), kept by the Super Admin. */
final class DownloadController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('admin/downloads/index', ['title' => 'Downloads', 'downloads' => DownloadService::all()]);
    }

    public function create(Request $request): void
    {
        $this->appView('admin/downloads/form', ['title' => 'New download', 'download' => null]);
    }

    public function edit(Request $request): void
    {
        $download = $this->download($request);
        $this->appView('admin/downloads/form', ['title' => 'Edit ' . $download['title'], 'download' => $download]);
    }

    public function store(Request $request): void
    {
        try {
            DownloadService::save(Auth::user(), null, $request->all(), UploadedFile::fromRequest($_FILES['file'] ?? []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/admin/downloads/create');
            return;
        }
        $this->success('The file was added to the Downloads page.', '/admin/downloads');
    }

    public function update(Request $request): void
    {
        $download = $this->download($request);
        try {
            DownloadService::save(Auth::user(), (int) $download['id'], $request->all(), UploadedFile::fromRequest($_FILES['file'] ?? []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/admin/downloads/' . (int) $download['id'] . '/edit');
            return;
        }
        $this->success('The download was saved.', '/admin/downloads');
    }

    public function toggle(Request $request): void
    {
        $download = $this->download($request);
        $show = (int) $download['is_public'] !== 1;
        DownloadService::setPublic(Auth::user(), (int) $download['id'], $show);
        $this->success($show ? $download['title'] . ' is shown on the Downloads page.' : $download['title'] . ' is hidden from the Downloads page.', '/admin/downloads');
    }

    public function destroy(Request $request): void
    {
        $download = $this->download($request);
        DownloadService::delete(Auth::user(), (int) $download['id']);
        $this->success($download['title'] . ' was deleted, with its file.', '/admin/downloads');
    }

    /** The admin opens any file, hidden or not, without counting it as a public download. */
    public function file(Request $request): void
    {
        $download = $this->download($request);
        FileStreamer::send(DownloadService::DIRECTORY . '/' . $download['stored_filename'], $download['original_filename'], $download['mime_type'] ?? null);
    }

    /** @return array<string,mixed> */
    private function download(Request $request): array
    {
        return DownloadService::find((int) $request->param('id')) ?? throw HttpException::notFound('That download was not found.');
    }
}
