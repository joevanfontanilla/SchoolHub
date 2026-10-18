<?php
/**
 * Public requirements page (docs/06): the school's admission requirements, as the registrar's office prints them. The Super Admin edits the list in
 * System Settings > Admission requirements.
 *
 * @var array $requirementGroups [title, items[]] in order
 */
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <p class="eyebrow">Enrollment</p>
    <h1 class="mt-2 text-2xl sm:text-3xl">Requirements for admission</h1>
    <p class="mt-2 text-slate-600">
        Prepare the documents listed for your level. When you apply online, you upload clear scans or photographs (PDF, JPG, or PNG, up to 5 MB each);
        the checklist in your application shows exactly what is asked of you.
    </p>

    <?php if ($requirementGroups === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', ['title' => 'The requirement list has not been published yet']) ?>
        </div>
    <?php else: ?>
        <div class="mt-8 grid gap-5 md:grid-cols-2">
            <?php foreach ($requirementGroups as $index => $group): ?>
                <section class="card p-5" aria-labelledby="req-<?= e((string) $index) ?>">
                    <h2 id="req-<?= e((string) $index) ?>" class="text-base uppercase"><?= e($group['title']) ?></h2>
                    <?php if ($group['items'] !== []): ?>
                        <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-slate-700">
                            <?php foreach ($group['items'] as $item): ?>
                                <li><?= e($item) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-8 rounded-xl border border-slate-200 bg-slate-50 p-5">
        <h2 class="text-sm">A note on your documents</h2>
        <p class="mt-1.5 text-sm text-slate-600">
            Uploaded documents are stored privately and are visible only to you and the registrar's office.
            They are never published on this website.
        </p>
    </div>
</div>
