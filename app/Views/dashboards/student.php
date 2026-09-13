<?php
/** Student dashboard (docs/08). */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Your enrollment, requirements, and account at a glance.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <?= partial('announcement-list', ['announcements' => $announcements]) ?>
        </div>

        <section class="card" aria-labelledby="next-steps-heading">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 id="next-steps-heading" class="text-base font-semibold text-brand-navy">Next steps</h2>
            </div>
            <ol class="space-y-3 px-5 py-4 text-sm text-slate-600">
                <li>1. Complete your student profile.</li>
                <li>2. Submit an enrollment application for the open term.</li>
                <li>3. Upload the required documents.</li>
                <li>4. Wait for the registrar to review your application.</li>
                <li>5. Settle your assessment with the cashier.</li>
            </ol>
            <p class="border-t border-slate-200 px-5 py-3 text-xs text-brand-gray">
                Enrollment opens in Phase 2 of development.
            </p>
        </section>
    </div>
</div>
