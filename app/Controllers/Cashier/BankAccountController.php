<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\BankAccountService;

/**
 * The school's bank accounts that students pay into (docs/14). The cashier keeps them at /cashier/bank-accounts, and the Super Admin at
 * /admin/bank-accounts; the same page, under each one's own menu.
 */
final class BankAccountController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('cashier/bank-accounts/index', ['title' => 'Payment Accounts', 'accounts' => BankAccountService::all(), 'base' => $this->base($request)]);
    }

    public function create(Request $request): void
    {
        $this->appView('cashier/bank-accounts/form', ['title' => 'New bank account', 'account' => null, 'base' => $this->base($request)]);
    }

    public function edit(Request $request): void
    {
        $account = BankAccountService::find((int) $request->param('id'));
        if ($account === null) {
            throw HttpException::notFound('That bank account was not found.');
        }
        $this->appView('cashier/bank-accounts/form', ['title' => 'Edit ' . $account['bank_name'], 'account' => $account, 'base' => $this->base($request)]);
    }

    public function save(Request $request): void
    {
        $base = $this->base($request);
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        try {
            BankAccountService::save(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $id === null ? "{$base}/create" : "{$base}/{$id}/edit");
            return;
        }
        $this->success($id === null ? 'Bank account added. Students can pay into it now.' : 'Bank account saved.', $base);
    }

    public function toggle(Request $request): void
    {
        $base = $this->base($request);
        $account = BankAccountService::find((int) $request->param('id'));
        if ($account === null) {
            throw HttpException::notFound('That bank account was not found.');
        }
        $activate = (int) $account['is_active'] !== 1;
        BankAccountService::setActive(Auth::user(), (int) $account['id'], $activate);
        $this->success($activate
            ? $account['bank_name'] . ' is on again. Students can choose it.'
            : $account['bank_name'] . ' is switched off. Students no longer see it; payments already made to it keep it.', $base);
    }

    private function base(Request $request): string
    {
        return str_starts_with($request->path(), '/admin/') ? '/admin/bank-accounts' : '/cashier/bank-accounts';
    }
}