<?php
/** Public home page (docs/06). */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<section class="border-b border-brand-200 bg-gradient-to-br from-brand-50 via-brand-100 to-white">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
        <div class="max-w-3xl">
            <p class="text-sm font-semibold uppercase tracking-wider text-brand-600">
                <?= e($school['school_tagline'] ?? 'Welcome') ?>
            </p>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-brand-navy sm:text-4xl lg:text-5xl">
                <?= e($schoolName) ?>
            </h1>
            <p class="mt-5 text-lg text-slate-600">
                Apply, submit your requirements, track your enrollment status, and manage your
                school records in one place.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="<?= e(url('/enrollment')) ?>" class="btn-primary">Start enrollment</a>
                <a href="<?= e(url('/programs')) ?>" class="btn-secondary">Browse programs</a>
            </div>
        </div>
    </div>
</section>

<section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="steps-heading">
    <h2 id="steps-heading" class="text-2xl font-bold text-brand-navy">How enrollment works</h2>
    <ol class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $steps = [
            ['Create an account', 'Register with your email and complete your student profile.'],
            ['Apply online', 'Choose your program, year level, and term, then submit your application.'],
            ['Submit requirements', 'Upload your documents and track their verification status.'],
            ['Pay and enroll', 'Settle your assessment, then receive your schedule and section.'],
        ];
        foreach ($steps as $index => [$stepTitle, $stepBody]):
        ?>
            <li class="card p-5">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">
                    <?= $index + 1 ?>
                </span>
                <h3 class="mt-4 text-base font-semibold text-brand-navy"><?= e($stepTitle) ?></h3>
                <p class="mt-1.5 text-sm text-slate-600"><?= e($stepBody) ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="bg-brand-mint" aria-labelledby="programs-heading">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 id="programs-heading" class="text-2xl font-bold text-brand-navy">Programs offered</h2>
            <a href="<?= e(url('/programs')) ?>" class="text-sm font-medium text-brand-600 hover:underline">
                View all programs
            </a>
        </div>

        <?php if ($programs === []): ?>
            <div class="card mt-8">
                <?= component('empty-state', ['title' => 'No programs published yet']) ?>
            </div>
        <?php else: ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($programs as $program): ?>
                    <article class="card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-600"><?= e($program['code']) ?></p>
                        <h3 class="mt-2 text-base font-semibold text-brand-navy"><?= e($program['name']) ?></h3>
                        <p class="mt-2 text-sm text-slate-600"><?= e(str_limit((string) $program['description'], 110)) ?></p>
                        <p class="mt-3 text-xs text-brand-gray"><?= e($program['years_duration']) ?> years</p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="news-heading">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <h2 id="news-heading" class="text-2xl font-bold text-brand-navy">Latest announcements</h2>
        <a href="<?= e(url('/announcements')) ?>" class="text-sm font-medium text-brand-600 hover:underline">
            All announcements
        </a>
    </div>

    <?php if ($announcements === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', [
                'title'   => 'No announcements yet',
                'message' => 'School announcements will be published here.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($announcements as $announcement): ?>
                <article class="card p-5">
                    <time class="text-xs text-brand-gray" datetime="<?= e($announcement['published_at'] ?? '') ?>">
                        <?= e(d($announcement['published_at'] ?? null)) ?>
                    </time>
                    <h3 class="mt-2 text-base font-semibold text-brand-navy">
                        <a class="hover:text-brand-600 hover:underline" href="<?= e(url('/announcements/' . $announcement['slug'])) ?>">
                            <?= e($announcement['title']) ?>
                        </a>
                    </h3>
                    <p class="mt-2 text-sm text-slate-600">
                        <?= e(str_limit(strip_tags((string) $announcement['content']), 130)) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="border-t border-brand-200 bg-brand-50" aria-labelledby="contact-heading">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 id="contact-heading" class="text-lg font-semibold text-brand-navy">Visit or contact us</h2>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium text-brand-navy">Address</dt>
                <dd class="mt-1 text-slate-600"><?= e($school['school_address'] ?? '') ?></dd>
            </div>
            <div>
                <dt class="font-medium text-brand-navy">Office hours</dt>
                <dd class="mt-1 text-slate-600"><?= e($school['office_hours'] ?? '') ?></dd>
            </div>
            <div>
                <dt class="font-medium text-brand-navy">Contact</dt>
                <dd class="mt-1 text-slate-600">
                    <?= e($school['school_phone'] ?? '') ?><br>
                    <?= e($school['school_email'] ?? '') ?>
                </dd>
            </div>
        </dl>
    </div>
</section>
