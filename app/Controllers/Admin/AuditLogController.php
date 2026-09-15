<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\CsvExport;

/**
 * The audit log and sign-in activity (docs/19). Read-only: there is no route that
 * changes or removes an entry, for any role.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 50;
    private const EXPORT_LIMIT = 10000;

    public function index(Request $request): void
    {
        $filters = $this->filters($request, ['from', 'to', 'actor', 'action', 'module', 'result', 'target_type', 'target_id']);
        $total = AuditLog::count($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('admin/audit/index', [
            'title'   => 'Audit Logs',
            'filters' => $filters,
            'entries' => AuditLog::search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'modules' => AuditLog::modules(),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
        ]);
    }

    public function show(Request $request): void
    {
        $entry = AuditLog::find((int) $request->param('id'));
        if ($entry === null) {
            throw HttpException::notFound('That audit entry was not found.');
        }
        $this->appView('admin/audit/show', ['title' => 'Audit entry #' . $entry['id'], 'entry' => $entry]);
    }

    public function export(Request $request): void
    {
        $filters = $this->filters($request, ['from', 'to', 'actor', 'action', 'module', 'result', 'target_type', 'target_id']);
        $entries = AuditLog::search($filters, self::EXPORT_LIMIT);
        AuditService::log('audit.exported', 'administration', null, null, null, ['filters' => array_filter($filters), 'rows' => count($entries)], 'SUCCESS', null, Auth::id(), Auth::roleName());

        CsvExport::send('audit-log-' . date('Ymd-His') . '.csv',
            ['ID', 'Time', 'Actor', 'Username', 'Role', 'Action', 'Module', 'Target type', 'Target ID', 'Result', 'Remarks', 'Old values', 'New values', 'IP address', 'User agent'],
            array_map(static fn (array $e): array => [
                $e['id'], $e['created_at'], $e['actor_name'] ?? '', $e['username'] ?? '', $e['actor_role'], $e['action'], $e['module'],
                $e['target_type'] ?? '', $e['target_id'] ?? '', $e['result'], $e['remarks'] ?? '', $e['old_values'] ?? '', $e['new_values'] ?? '',
                $e['ip_address'] ?? '', $e['user_agent'] ?? '',
            ], $entries)
        );
    }

    public function logins(Request $request): void
    {
        $filters = $this->filters($request, ['from', 'to', 'login', 'ip', 'result']);
        $total = AuditLog::loginAttemptCount($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('admin/audit/logins', [
            'title'    => 'Sign-in Activity',
            'filters'  => $filters,
            'attempts' => AuditLog::loginAttempts($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
        ]);
    }

    /** @return array<string,string> */
    private function filters(Request $request, array $keys): array
    {
        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = mb_substr(trim((string) $request->input($key, '')), 0, 100);
        }
        return $filters;
    }
}
