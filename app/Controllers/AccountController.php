<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Services\AvatarService;

/**
 * "My Account", shared by every role (docs/08): a profile photo and a link to change the
 * password. Any signed-in user can set their own photo here.
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
        ]);
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

    /** Stream the signed-in user's own photo (inline). */
    public function photo(Request $request): void
    {
        AvatarService::stream(Auth::user());
    }
}
