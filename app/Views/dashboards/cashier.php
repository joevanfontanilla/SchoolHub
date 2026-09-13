<?php
/** Cashier dashboard (docs/14). */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Student accounts, payments, and collections.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <section class="card" aria-labelledby="payments-heading">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 id="payments-heading" class="text-base font-semibold text-brand-navy">Recent payments</h2>
                </div>
                <?= component('empty-state', [
                    'title'   => 'No payments recorded',
                    'message' => 'Payments you record will appear here with their receipts.',
                ]) ?>
            </section>
        </div>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
