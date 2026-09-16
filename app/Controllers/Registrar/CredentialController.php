<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\CredentialRequest;
use App\Services\CredentialRequestService;

/**
 * The registrar processes student document requests (docs/08).
 */
final class CredentialController extends Controller
{
    public function index(Request $request): void
    {
        $status = (string) $request->input('status', 'OPEN');
        $this->appView('registrar/credentials/index', [
            'title'    => 'Credential Requests',
            'requests' => CredentialRequest::queue($status),
            'status'   => $status,
        ]);
    }

    public function process(Request $request): void
    {
        try {
            CredentialRequestService::process(Auth::user(), (int) $request->param('id'), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/registrar/credentials');
            return;
        }
        $this->success('The request was updated and the student notified.', '/registrar/credentials');
    }
}
