<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\SystemSetting;
use App\Services\FileStreamer;
use App\Services\SettingsService;

/** System settings (docs/20), and the school logo they set. */
final class SettingController extends Controller
{
    public function index(Request $request): void
    {
        $group = (string) $request->input('group', 'school');
        $group = isset(SettingsService::GROUPS[$group]) ? $group : 'school';
        $this->appView('admin/settings/index', [
            'title'    => 'System Settings',
            'group'    => $group,
            'groups'   => SettingsService::GROUPS,
            'values'   => array_map(static fn ($row) => $row['value'], SystemSetting::all()),
            'hasLogo'  => SettingsService::logoPath() !== null,
            'presidentSignatureUri' => SettingsService::presidentSignatureUri(),
        ]);
    }

    public function update(Request $request): void
    {
        $group = (string) $request->param('group');
        try {
            $changed = SettingsService::update(Auth::user(), $group, $request->all(), $group === 'school' ? UploadedFile::fromRequest($_FILES['logo'] ?? []) : null, $group === 'school' ? UploadedFile::fromRequest($_FILES['president_signature'] ?? []) : null);
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/admin/settings?group=' . rawurlencode($group));
            return;
        }
        $this->success($changed === [] ? 'Nothing changed.' : count($changed) . ' setting' . (count($changed) === 1 ? '' : 's') . ' saved.', '/admin/settings?group=' . rawurlencode($group));
    }

    /** The uploaded school logo, for every page (public). The built-in seal otherwise. */
    public function logo(Request $request): void
    {
        $path = SettingsService::logoPath();
        if ($path === null) {
            $this->redirect('/assets/images/lyceum-logo.jpg');
            return;
        }
        FileStreamer::send($path, 'school-logo.' . pathinfo($path, PATHINFO_EXTENSION), str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg', true);
    }
}
