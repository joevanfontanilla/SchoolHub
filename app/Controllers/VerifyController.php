<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Services\EIdService;
use App\Services\FileStreamer;

/**
 * Public e-ID verification (docs/29): the page a scanned QR opens. No sign-in, minimal data, never cached
 * or indexed. An unknown code, a disabled account, and an expired ID all answer the same way.
 */
final class VerifyController extends Controller
{
    /** The "Verify an ID" box. A code, or the whole address from a scanned QR, goes straight to the result. */
    public function form(Request $request): void
    {
        $this->noIndex();
        $typed = trim((string) $request->input('code', ''));
        if ($typed !== '') {
            if (preg_match('/([a-f0-9]{24})\s*$/i', $typed, $match) === 1) {
                $this->redirect('/verify/' . strtolower($match[1]));
                return;
            }
            $this->publicView('public/verify', ['title' => 'Verify an ID', 'notice' => 'That does not look like an ID code. Paste the address under the QR, or the 24-character code.']);
            return;
        }

        $this->publicView('public/verify', ['title' => 'Verify an ID', 'notice' => null]);
    }

    public function show(Request $request): void
    {
        $this->noIndex();
        $code = (string) $request->param('code');
        $result = EIdService::verify($code, $request->ip());

        // The same 404 page for every reason an ID is not valid, so a scan reveals nothing about why.
        $this->publicView('public/verify-result', [
            'title'  => $result !== null ? 'Valid ID' : 'ID not valid',
            'result' => $result,
            'code'   => $code,
        ], $result !== null ? 200 : 404);
    }

    public function photo(Request $request): void
    {
        $this->noIndex();
        $photo = EIdService::photoFor((string) $request->param('code'), $request->ip());
        if ($photo === null) {
            throw HttpException::notFound('No photo.');
        }
        FileStreamer::send($photo['path'], 'photo.' . pathinfo($photo['path'], PATHINFO_EXTENSION), $photo['mime'], true);
    }

    private function noIndex(): void
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
}
