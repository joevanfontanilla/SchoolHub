<?php
/**
 * System settings > Admission requirements (docs/06): the list shown on the public Requirements page, as groups of lines.
 *
 * @var array $groups            SettingsService::GROUPS (for the side menu)
 * @var array $requirementGroups the saved groups: [title, items[]]
 */
$failed = errors() !== [];
$rows = $failed ? array_values((array) old('groups', [])) : array_map(static fn ($g) => ['title' => $g['title'], 'items' => implode("\n", $g['items'])], $requirementGroups);
// A blank group at the end to add to (two when nothing is saved yet).
$rows[] = ['title' => '', 'items' => ''];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Administration</p>
        <h2 class="mt-1 text-2xl">System Settings</h2>
        <p class="mt-1 text-sm text-slate-600">Every change is recorded in the audit log.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-4">
        <nav aria-label="Settings groups" class="card h-max p-2 lg:sticky lg:top-24">
            <?php foreach ($groups as $key => [$title]): ?>
                <a href="<?= e(url('/admin/settings?group=' . $key)) ?>" class="block rounded-lg px-3 py-2 text-sm font-semibold text-brand-navy hover:bg-brand-50"><?= e($title) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(url('/admin/settings/requirements')) ?>" aria-current="page" class="block rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white">Admission requirements</a>
        </nav>

        <form method="POST" action="<?= e(url('/admin/settings/requirements')) ?>" class="card space-y-5 p-5 sm:p-6 lg:col-span-3" novalidate
              data-confirm="The public Requirements page shows this list right away, and the change is recorded in the audit log." data-confirm-title="Publish the admission requirements?" data-confirm-tone="warning">
            <div>
                <h3 class="text-lg">Admission requirements</h3>
                <p class="text-sm text-slate-600">The list on the public Requirements page, as the school prints it. Each group has a heading and one requirement per line, in the order shown. Add a note by writing it in the line, for example &ldquo;(submit after 30 days)&rdquo;. Clear a group&rsquo;s heading and lines to remove it. This is information for visitors; it does not change which documents an applicant uploads.</p>
            </div>
            <?php if (error_for('groups')): ?><p class="field-error"><?= e(error_for('groups')) ?></p><?php endif; ?>

            <div class="space-y-4" data-requirement-groups>
                <?php foreach ($rows as $i => $row): ?>
                    <?= partial('requirement-group', ['n' => (string) $i, 'label' => 'Group ' . ($i + 1), 'row' => $row]) ?>
                <?php endforeach; ?>
            </div>
            <template data-requirement-template><?= partial('requirement-group', ['n' => '__N__', 'label' => 'New group', 'row' => [], 'template' => true]) ?></template>
            <button type="button" class="btn-secondary" data-add-requirement-group hidden><?= icon('plus', 'h-5 w-5') ?> Add another group</button>

            <div class="flex gap-3 border-t border-brand-100 pt-5">
                <button type="submit" class="btn-primary">Publish requirements</button>
                <a href="<?= e(url('/requirements')) ?>" class="btn-secondary" target="_blank" rel="noopener">View public page</a>
            </div>
            <?= csrf_field() ?>
        </form>
    </div>
</div>
