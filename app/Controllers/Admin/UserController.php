<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserAdminService;

/**
 * Accounts (docs/04, docs/07). Super Admin only; the rules live in UserAdminService.
 */
final class UserController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): void
    {
        $filters = [
            'q'      => trim((string) $request->input('q', '')),
            'role'   => (string) $request->input('role', ''),
            'status' => (string) $request->input('status', ''),
        ];
        $page = max(1, (int) $request->input('page', 1));
        $list = User::adminList($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->appView('admin/users/index', [
            'title'   => 'Users',
            'filters' => $filters,
            'users'   => $list['rows'],
            'total'   => $list['total'],
            'page'    => $page,
            'pages'   => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'counts'  => User::statusCounts(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->appView('admin/users/form', ['title' => 'New staff account', 'account' => null, 'roles' => UserAdminService::STAFF_ROLES]);
    }

    public function store(Request $request): void
    {
        try {
            $created = UserAdminService::createStaff(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/admin/users/create');
            return;
        }
        // The link is shown once, in this response, and never stored in plain form.
        $this->linkView(User::find($created['id']), $created['token'], $created['expires_at'], true);
    }

    public function show(Request $request): void
    {
        $account = $this->account($request);
        $this->appView('admin/users/show', [
            'title'       => $account->fullName(),
            'account'     => $account,
            'logins'      => AuditLog::loginAttempts(['user_id' => (string) $account->id], 10),
            'activity'    => AuditLog::search(['user_id' => (string) $account->id], 10),
            'aboutThem'   => AuditLog::search(['target_type' => 'User', 'target_id' => (string) $account->id], 10),
            'self'        => $account->id === Auth::id(),
        ]);
    }

    public function edit(Request $request): void
    {
        $account = $this->account($request);
        $this->appView('admin/users/form', ['title' => 'Edit ' . $account->fullName(), 'account' => $account, 'roles' => UserAdminService::STAFF_ROLES]);
    }

    public function update(Request $request): void
    {
        $account = $this->account($request);
        try {
            UserAdminService::update(Auth::user(), $account->id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/admin/users/{$account->id}/edit");
            return;
        }
        $this->success('Account saved.', "/admin/users/{$account->id}");
    }

    public function disable(Request $request): void
    {
        $account = $this->account($request);
        $this->act($account, static fn () => UserAdminService::disable(Auth::user(), $account->id, (string) $request->input('reason', '')), 'Account disabled. It can no longer sign in, and any open session has ended.');
    }

    public function enable(Request $request): void
    {
        $account = $this->account($request);
        $this->act($account, static fn () => UserAdminService::enable(Auth::user(), $account->id), 'Account enabled.');
    }

    public function unlock(Request $request): void
    {
        $account = $this->account($request);
        $this->act($account, static fn () => UserAdminService::unlock(Auth::user(), $account->id), 'Account unlocked. The user can sign in again.');
    }

    public function resetLink(Request $request): void
    {
        $account = $this->account($request);
        try {
            $link = UserAdminService::issueResetLink(Auth::user(), $account->id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/admin/users/{$account->id}");
            return;
        }
        $this->linkView(User::find($account->id), $link['token'], $link['expires_at'], false);
    }

    public function emailLink(Request $request): void
    {
        $account = $this->account($request);
        $token = (string) $request->input('token', '');
        try {
            $user = UserAdminService::emailLink(Auth::user(), $account->id, $token);
        } catch (ValidationException $e) {
            // Show the link again with the problem, so it can still be copied.
            $found = \App\Services\PasswordResetService::find($token);
            if ($found !== null && $found['user']->id === $account->id) {
                Session::flash('error', $e->getMessage());
                $this->linkView($account, $token, $found['expires_at'], $found['purpose'] === \App\Services\PasswordResetService::SETUP && $account->status === User::STATUS_PENDING);
                return;
            }
            $this->failWith($e, [], "/admin/users/{$account->id}");
            return;
        }
        $this->success("The link was emailed to {$user->email}.", "/admin/users/{$account->id}");
    }

    private function act(User $account, callable $action, string $message): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/admin/users/{$account->id}");
            return;
        }
        $this->success($message, "/admin/users/{$account->id}");
    }

    private function linkView(User $account, string $token, string $expiresAt, bool $newAccount): void
    {
        // Never cached: the page holds a working password link.
        header('Cache-Control: no-store');
        $this->appView('admin/users/link', [
            'title'      => $newAccount ? 'Account created' : 'Password link',
            'account'    => $account,
            'link'       => absolute_url('/password/reset/' . $token),
            'expiresAt'  => $expiresAt,
            'newAccount' => $newAccount,
        ]);
    }

    private function account(Request $request): User
    {
        $account = User::find((int) $request->param('id'));
        if ($account === null) {
            throw \App\Core\HttpException::notFound('That account was not found.');
        }
        return $account;
    }
}
