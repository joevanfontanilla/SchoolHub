<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\EIdService;

/**
 * E-ID cards (docs/29). Everyone opens their own; the registrar opens a student's and the Super Admin a staff
 * member's, and each may reissue it. The card holds a QR that the public Verify page reads.
 */
final class EIdController extends Controller
{
    // -- The holder's own ---------------------------------------------------------------------

    public function show(Request $request): void
    {
        $this->screen(Auth::user(), true, '/e-id/print', null, null);
    }

    public function print(Request $request): void
    {
        $this->printout(Auth::user(), '/e-id');
    }

    // -- The registrar, for a student ---------------------------------------------------------

    public function student(Request $request): void
    {
        $holder = EIdService::studentUser((int) $request->param('id'));
        $id = (int) $request->param('id');
        $this->screen($holder, false, "/registrar/students/{$id}/e-id/print", "/registrar/students/{$id}/e-id/reissue", "/registrar/students/{$id}");
    }

    public function studentPrint(Request $request): void
    {
        $id = (int) $request->param('id');
        $this->printout(EIdService::studentUser($id), "/registrar/students/{$id}/e-id");
    }

    public function studentReissue(Request $request): void
    {
        $id = (int) $request->param('id');
        EIdService::reissue(Auth::user(), EIdService::studentUser($id)->id);
        $this->success('A new e-ID code was issued. The old card no longer verifies. Print the new card.', "/registrar/students/{$id}/e-id");
    }

    // -- The Super Admin, for staff -----------------------------------------------------------

    public function staff(Request $request): void
    {
        $id = (int) $request->param('id');
        $this->screen(EIdService::staffUser($id), false, "/admin/users/{$id}/e-id/print", "/admin/users/{$id}/e-id/reissue", "/admin/users/{$id}");
    }

    public function staffPrint(Request $request): void
    {
        $id = (int) $request->param('id');
        $this->printout(EIdService::staffUser($id), "/admin/users/{$id}/e-id");
    }

    public function staffReissue(Request $request): void
    {
        $id = (int) $request->param('id');
        EIdService::reissue(Auth::user(), EIdService::staffUser($id)->id);
        $this->success('A new e-ID code was issued. The old card no longer verifies. Print the new card.', "/admin/users/{$id}/e-id");
    }

    // -- Rendering ----------------------------------------------------------------------------

    private function screen(User $holder, bool $own, string $printPath, ?string $reissuePath, ?string $backPath): void
    {
        $reason = EIdService::ineligibleReason($holder);
        if ($reason !== null) {
            $this->appView('e-id/unavailable', ['title' => 'E-ID', 'holder' => $holder, 'own' => $own, 'reason' => $reason, 'backPath' => $backPath]);
            return;
        }

        $this->appView('e-id/show', [
            'title'        => $own ? 'My e-ID' : $holder->fullName() . ' · e-ID',
            'card'         => EIdService::card($holder, Auth::user()),
            'school'       => SystemSetting::publicSettings(),
            'holder'       => $holder,
            'own'          => $own,
            'printPath'    => $printPath,
            'reissuePath'  => $reissuePath,
            'backPath'     => $backPath,
        ]);
    }

    private function printout(User $holder, string $back): void
    {
        $card = EIdService::card($holder, Auth::user());
        Response::html(View::render('e-id/print', [
            'title'  => 'E-ID · ' . $holder->fullName(),
            'card'   => $card,
            'school' => SystemSetting::publicSettings(),
            'back'   => $back,
        ], 'layouts/card'));
    }
}
