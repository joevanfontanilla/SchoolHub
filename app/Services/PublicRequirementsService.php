<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The admission requirements shown on the public Requirements page (docs/06), edited by the Super Admin in System Settings.
 * Information for the public only: it does not change what an applicant is asked to upload (docs/10).
 */
final class PublicRequirementsService
{
    public const KEY = 'public_requirements';
    public const MAX_GROUPS = 20;
    public const MAX_ITEMS = 40;
    public const MAX_TITLE = 100;
    public const MAX_ITEM = 200;

    /** @return array<int,array{title:string,items:array<int,string>}> */
    public static function groups(): array
    {
        $stored = SystemSetting::get(self::KEY, []);
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $group) {
            if (!is_array($group) || trim((string) ($group['title'] ?? '')) === '') {
                continue;
            }
            $items = array_values(array_filter(array_map(static fn ($i) => trim((string) $i), (array) ($group['items'] ?? [])), static fn ($i) => $i !== ''));
            $out[] = ['title' => trim((string) $group['title']), 'items' => $items];
        }

        return $out;
    }

    /**
     * Save the list from the editor's form: groups[n][title] and groups[n][items] (one requirement per line). A group left blank is dropped.
     *
     * @param array<string,mixed> $input
     * @return int the number of groups now published
     */
    public static function save(User $admin, array $input): int
    {
        if (!$admin->isSuperAdmin()) {
            throw new \App\Core\HttpException(403, 'Only the Super Admin can edit the admission requirements.');
        }

        $groups = [];
        $errors = [];
        foreach ((array) ($input['groups'] ?? []) as $index => $row) {
            $title = trim(preg_replace('/\s+/', ' ', (string) ($row['title'] ?? '')) ?? '');
            $lines = preg_split('/\R/', (string) ($row['items'] ?? '')) ?: [];
            $items = array_values(array_filter(array_map(static fn ($l) => trim(preg_replace('/\s+/', ' ', $l) ?? ''), $lines), static fn ($l) => $l !== ''));
            if ($title === '' && $items === []) {
                continue;
            }
            $n = count($groups) + 1;
            if ($title === '') {
                $errors["groups.{$index}.title"] = ["Group {$n} needs a heading."];
            } elseif (mb_strlen($title) > self::MAX_TITLE) {
                $errors["groups.{$index}.title"] = ['A heading can be at most ' . self::MAX_TITLE . ' characters.'];
            }
            if (count($items) > self::MAX_ITEMS) {
                $errors["groups.{$index}.items"] = ['A group can hold at most ' . self::MAX_ITEMS . ' requirements.'];
            }
            foreach ($items as $item) {
                if (mb_strlen($item) > self::MAX_ITEM) {
                    $errors["groups.{$index}.items"] = ['Each requirement can be at most ' . self::MAX_ITEM . ' characters.'];
                    break;
                }
            }
            $groups[] = ['title' => $title, 'items' => $items];
        }

        if (count($groups) > self::MAX_GROUPS) {
            $errors['groups'] = ['There can be at most ' . self::MAX_GROUPS . ' groups.'];
        }
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $before = count(self::groups());
        SystemSetting::set(self::KEY, json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $admin->id);
        AuditService::log('requirements.published', 'administration', 'SystemSetting', null, ['groups' => $before], ['groups' => count($groups)], 'SUCCESS', null, $admin->id, $admin->role_name);

        return count($groups);
    }
}
