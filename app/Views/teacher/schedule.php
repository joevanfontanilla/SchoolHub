<?php
/**
 * The teacher's weekly teaching schedule (docs/12).
 *
 * @var array $classes
 * @var array $blocks
 */
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Teaching</p>
        <h2 class="mt-1 text-2xl">Weekly schedule</h2>
        <p class="mt-1 text-sm text-slate-600">Every meeting of your assigned classes, with room and section.</p>
    </div>

    <?php if ($blocks === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'Nothing scheduled', 'message' => 'Classes appear here once the registrar assigns them to you.']) ?></section>
    <?php else: ?>
        <section class="card p-4 sm:p-5">
            <?= partial('schedule-week', ['blocks' => $blocks]) ?>
        </section>
    <?php endif; ?>
</div>
