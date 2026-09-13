<?php
/** Public contact page (docs/06). */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Contact</h1>
    <p class="mt-2 text-slate-600">Reach the school through any of the details below.</p>

    <div class="mt-8 grid gap-6 sm:grid-cols-2">
        <section class="card p-6">
            <h2 class="text-base font-semibold text-brand-navy">School office</h2>
            <address class="mt-3 space-y-2 text-sm not-italic text-slate-600">
                <p><?= e($schoolName) ?></p>
                <p><?= e($school['school_address'] ?? '') ?></p>
                <?php if (!empty($school['school_phone'])): ?>
                    <p>Phone: <?= e($school['school_phone']) ?></p>
                <?php endif; ?>
                <?php if (!empty($school['school_email'])): ?>
                    <p>
                        Email:
                        <a class="text-brand-600 hover:underline" href="mailto:<?= e($school['school_email']) ?>">
                            <?= e($school['school_email']) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </address>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-semibold text-brand-navy">Office hours</h2>
            <p class="mt-3 text-sm text-slate-600"><?= e($school['office_hours'] ?? '') ?></p>
            <p class="mt-4 text-sm text-slate-600">
                For enrollment questions, please have your student number or application number ready.
            </p>
        </section>
    </div>

    <section class="mt-8" aria-labelledby="where-heading">
        <h2 id="where-heading" class="text-xl font-semibold text-brand-navy">Where to go</h2>
        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
            <?php
            $offices = [
                ['Registrar', 'Applications, requirements, records, and certifications.'],
                ['Cashier', 'Assessments, payments, receipts, and balances.'],
                ['Faculty Office', 'Grades, attendance, and class concerns.'],
            ];
            foreach ($offices as [$office, $description]):
            ?>
                <div class="card p-5">
                    <dt class="text-sm font-semibold text-brand-navy"><?= e($office) ?></dt>
                    <dd class="mt-1 text-sm text-slate-600"><?= e($description) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
</div>
