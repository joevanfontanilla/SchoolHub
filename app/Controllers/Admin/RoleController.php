<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\PermissionService;

/** Roles and their permissions (docs/04). */
final class RoleController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('admin/roles/index', ['title' => 'Roles & Permissions'] + PermissionService::matrix());
    }

    public function update(Request $request): void
    {
        try {
            $change = PermissionService::update(Auth::user(), (int) $request->param('id'), (array) $request->input('permissions', []));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/admin/roles');
            return;
        }
        $message = $change['added'] === [] && $change['removed'] === []
            ? 'No changes to save.'
            : 'Permissions saved: ' . count($change['added']) . ' added, ' . count($change['removed']) . ' removed.';
        $this->success($message, '/admin/roles#role-' . (int) $request->param('id'));
    }
}
