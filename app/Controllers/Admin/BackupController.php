<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BackupService;

/**
 * Backups (docs/20): create, verify, download, delete. Restoring is only possible
 * from the server's command line (database/restore.php).
 */
final class BackupController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('admin/backups/index', [
            'title'   => 'Backups',
            'backups' => BackupService::history(),
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $id = BackupService::create(Auth::user(), BackupService::MANUAL, trim((string) $request->input('notes', '')) ?: null);
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/admin/backups');
            return;
        } catch (\Throwable $e) {
            $this->backWithError('The backup failed: ' . $e->getMessage(), [], '/admin/backups');
            return;
        }
        $backup = BackupService::find($id);
        $this->success("Backup {$backup['filename']} created and checksummed.", '/admin/backups');
    }

    public function verify(Request $request): void
    {
        $result = BackupService::verify(Auth::user(), (int) $request->param('id'));
        $result['ok']
            ? $this->success('Backup verified: ' . $result['message'], '/admin/backups')
            : $this->backWithError('Verification failed: ' . $result['message'], [], '/admin/backups');
    }

    public function download(Request $request): void
    {
        try {
            BackupService::download(Auth::user(), (int) $request->param('id'), (string) $request->raw('password', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/admin/backups');
        }
    }

    public function destroy(Request $request): void
    {
        try {
            BackupService::delete(Auth::user(), (int) $request->param('id'), (string) $request->raw('password', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/admin/backups');
            return;
        }
        $this->success('Backup file deleted. Its record stays in the history.', '/admin/backups');
    }
}
