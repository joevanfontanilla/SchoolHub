<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Services\AccountNameService;
use App\Services\AvatarService;
use App\Services\SignatureService;

/**
 * "My Account", shared by every role (docs/08): the user's own name, a profile photo, and a
 * link to change the password.
 */
final class AccountController extends Controller
{
    public function show(Request $request): void
    {
        $user = Auth::user();
        $this->appView('account/index', [
            'title'    => 'My Account',
            'user'     => $user,
            'photoUrl' => AvatarService::currentUrl($user),
            'maxBytes' => AvatarService::MAX_BYTES,
            'nameLocked' => AccountNameService::lockedReason($user),
            'signatureUrl' => SignatureService::currentUrl($user),
            'signatureMaxBytes' => SignatureService::MAX_BYTES,
        ]);
    }

    public function saveName(Request $request): void
    {
        try {
            $changed = AccountNameService::update(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/account#name');
            return;
        }
        $this->success($changed ? 'Your name was updated.' : 'Your name is already saved that way.', '/account');
    }

    public function savePhoto(Request $request): void
    {
        try {
            AvatarService::save(Auth::user(), UploadedFile::fromRequest($_FILES['photo'] ?? []), (bool) $request->input('remove_photo', false));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/account');
            return;
        }
        $this->success('Your profile photo was updated.', '/account');
    }

    public function saveSignature(Request $request): void
    {
        try {
            SignatureService::save(Auth::user(), UploadedFile::fromRequest($_FILES['signature'] ?? []), (string) $request->input('drawn', ''), (string) $request->input('agree', '') === '1');
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/account#signature');
            return;
        }
        $this->success('Your signature was saved. It will appear on the documents you prepare.', '/account#signature');
    }

    public function removeSignature(Request $request): void
    {
        SignatureService::remove(Auth::user(), Auth::user()->id);
        $this->success('Your signature was removed.', '/account#signature');
    }

    /** Stream the signed-in user's own signature (inline). */
    public function signature(Request $request): void
    {
        SignatureService::stream(Auth::user());
    }

    /** Stream the signed-in user's own photo (inline). */
    public function photo(Request $request): void
    {
        AvatarService::stream(Auth::user());
    }
}
