<?php
/**
 * Public enrollment page: dates, steps, requirements, eligibility, FAQ (docs/06).
 *
 * @var array $openTerms
 */
use App\Models\Term;
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Enrollment</h1>

    <?php if ($openTerms !== []): ?>
        <div class="mt-4 rounded-xl border border-brand-200 bg-brand-50 p-5">
            <p class="eyebrow">Now accepting applications</p>
            <?php foreach ($openTerms as $term): ?>
                <p class="mt-2 text-slate-700">
                    <span class="font-display font-bold text-brand-navy"><?= e(Term::label($term)) ?></span>:
                    applications open until <span class="font-semibold"><?= e(dt($term['enrollment_closes_at'], 'F j, Y')) ?></span>.
                    Classes start <?= e(d($term['start_date'])) ?>.
                </p>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="mt-2 text-slate-600">Enrollment is not open right now. Check the announcements for the next schedule.</p>
    <?php endif; ?>

    <section class="mt-10" aria-labelledby="steps-heading">
        <h2 id="steps-heading" class="text-xl font-semibold text-brand-navy">Steps</h2>
        <ol class="mt-4 space-y-3">
            <?php
            $steps = [
                'Create a student account and complete your profile.',
                'Select the academic year, semester, program, and year level.',
                'Complete the enrollment form.',
                'Upload the required documents.',
                'Review your information and submit the application.',
                'Wait for the registrar to review and verify your requirements.',
                'Settle your fee assessment with the cashier.',
                'Receive your section assignment and class schedule.',
            ];
            foreach ($steps as $index => $step):
            ?>
                <li class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">
                        <?= $index + 1 ?>
                    </span>
                    <span class="pt-0.5 text-sm text-slate-700"><?= e($step) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="mt-10" aria-labelledby="eligibility-heading">
        <h2 id="eligibility-heading" class="text-xl font-semibold text-brand-navy">Who may apply</h2>
        <ul class="mt-4 space-y-2 text-sm text-slate-700">
            <li>New students who have completed senior high school.</li>
            <li>Transferees from another recognized institution.</li>
            <li>Continuing students proceeding to the next term.</li>
            <li>Returning students resuming after a break.</li>
        </ul>
    </section>

    <section class="mt-10" aria-labelledby="faq-heading">
        <h2 id="faq-heading" class="text-xl font-semibold text-brand-navy">Frequently asked questions</h2>
        <dl class="mt-4 space-y-5">
            <?php
            $faqs = [
                ['Do I need to visit the campus to apply?', 'You can submit the application and your documents online. The registrar may ask you to present original documents for verification.'],
                ['How will I know my application status?', 'Sign in and open the Enrollment page. Every status change is shown with the date and the remarks from the registrar.'],
                ['What if a document is rejected?', 'Your application is returned to you with the reason. Upload a corrected copy and submit again.'],
                ['When is my enrollment final?', 'Once the registrar approves your application and your payment meets the school policy, your enrollment is confirmed and you are assigned a section.'],
            ];
            foreach ($faqs as [$question, $answer]):
            ?>
                <div class="card p-5">
                    <dt class="text-sm font-semibold text-brand-navy"><?= e($question) ?></dt>
                    <dd class="mt-1.5 text-sm text-slate-600"><?= e($answer) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>

    <div class="mt-10 rounded-xl border border-brand-200 bg-brand-50 p-6">
        <h2 class="text-base font-semibold text-brand-navy">Ready to apply?</h2>
        <p class="mt-1.5 text-sm text-brand-700">
            Create a student account to start your application online, or sign in if you already have one.
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
            <a href="<?= e(url('/register')) ?>" class="btn-primary">Create an account</a>
            <a href="<?= e(url('/login')) ?>" class="btn-secondary">Sign in</a>
        </div>
    </div>
</div>
