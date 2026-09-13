<?php
/** Registrar dashboard (docs/11). */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Enrollment applications, student records, and academic setup.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <section class="card" aria-labelledby="queue-heading">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 id="queue-heading" class="text-base font-semibold text-brand-navy">Application queue</h2>
                </div>
                <?= component('empty-state', [
                    'title'   => 'No applications yet',
                    'message' => 'Submitted enrollment applications will appear here for review.',
                ]) ?>
            </section>
        </div>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
