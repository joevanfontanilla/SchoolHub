<?php
/** Teacher dashboard (docs/12). */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Your assigned classes, attendance, and grading tasks.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <section class="card" aria-labelledby="classes-heading">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 id="classes-heading" class="text-base font-semibold text-brand-navy">My classes</h2>
                </div>
                <?= component('empty-state', [
                    'title'   => 'No classes assigned',
                    'message' => 'The registrar assigns classes when the term is set up.',
                ]) ?>
            </section>
        </div>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
