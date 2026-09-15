<?php
/** Public about page (docs/06). */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">About <?= e($schoolName) ?></h1>

    <p class="mt-4 text-slate-600">
        <?= e($schoolName) ?> serves students through a single platform for enrollment, academic records,
        fees, and school communication. Students apply and track their status online; the registrar,
        teaching staff, and cashier work from the same records, so information stays consistent from
        application through graduation.
    </p>

    <div class="mt-10 grid gap-6 sm:grid-cols-2">
        <section class="card p-6">
            <h2 class="text-base font-semibold text-brand-navy">Mission</h2>
            <p class="mt-2 text-sm text-slate-600">
                To educate, train and provide our graduates with appropriate and excellent intellect,
                knowledge and courageous enough to expeditiously respond with manifest and inherit
                capabilities to face ever-changing challenges in man's search for peace, prosperity
                and world harmony.
            </p>
        </section>
        <section class="card p-6">
            <h2 class="text-base font-semibold text-brand-navy">Vision</h2>
            <p class="mt-2 text-sm text-slate-600">
                The premiere institution furthering the excellent transformation of human intellect
                pillared on discipline, integrity and service to empower its graduates with provident
                knowledge totally responsive to our hopes and aspirations as Filipinos and as prominent
                citizens of the global community.
            </p>
        </section>
    </div>

    <section class="mt-10" aria-labelledby="offices-heading">
        <h2 id="offices-heading" class="text-xl font-semibold text-brand-navy">Offices</h2>
        <dl class="mt-4 space-y-4">
            <?php
            $offices = [
                ['Registrar', 'Enrollment applications, requirements, student records, and academic reports.'],
                ['Cashier', 'Fee assessments, payments, receipts, and account statements.'],
                ['Academic Affairs', 'Programs, subjects, sections, schedules, and faculty assignment.'],
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
