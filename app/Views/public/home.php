<?php
/**
 * Public home page (docs/06), laid out on the Lyceum of the East Aurora website
 * template: seal + name + spaced tagline, a short blue rule, a two-tone display
 * headline, the campus photo blended into a pale-blue backdrop with a blue
 * swoosh, swatch-style cards, the brand gradient band, and the stripe band.
 */
$schoolName = $school['school_name'] ?? 'Lyceum of the East Aurora';
$tagline    = $school['school_tagline'] ?? 'Discipline • Integrity • Service';
$swatches   = ['swatch-primary', 'swatch-navy', 'swatch-teal', 'swatch-sky'];
?>

<!-- ======================================================================
     Hero
     ====================================================================== -->
<section class="hero-backdrop relative overflow-hidden lg:min-h-[640px]" aria-labelledby="school-name">
    <div class="relative z-20 mx-auto max-w-7xl px-4 pb-10 pt-10 sm:px-6 sm:pt-14 lg:px-8 lg:pb-44 lg:pt-20">
        <div class="max-w-2xl">
            <div class="flex items-center gap-4 sm:gap-5">
                <img src="<?= e(school_logo_url()) ?>"
                     alt=""
                     width="96" height="96"
                     class="school-seal h-16 w-16 sm:h-24 sm:w-24">
                <div>
                    <h1 id="school-name" class="font-display text-xl font-extrabold uppercase leading-tight tracking-tight text-brand-navy sm:text-3xl lg:whitespace-nowrap">
                        <?= e($schoolName) ?>
                    </h1>
                    <p class="tagline mt-1.5"><?= e($tagline) ?></p>
                </div>
            </div>

            <span class="brand-rule mt-6 sm:ml-[7.25rem]" aria-hidden="true"></span>

            <p class="display-heading mt-8">
                Enrollment made <span class="text-brand-primary">simple</span>
            </p>
            <p class="mt-4 font-display text-base font-medium text-brand-gray sm:text-lg">
                Inspired by our school. Built for your future.
            </p>

            <p class="mt-6 max-w-lg text-base text-slate-600 sm:text-lg">
                Apply online, submit your requirements, follow every step of your enrollment,
                and keep your school records in one place.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="<?= e(url('/enrollment')) ?>" class="btn-primary btn-lg">Start enrollment</a>
                <a href="<?= e(url('/programs')) ?>" class="btn-secondary btn-lg">Browse programs</a>
            </div>
        </div>
    </div>

    <!-- One image element for every breakpoint: in the flow under the text on
         small screens, pinned to the right half on large ones. -->
    <img src="<?= e(asset('assets/images/campus.jpg')) ?>"
         alt="The <?= e($schoolName) ?> campus: blue and green school buildings behind the front gate under a clear sky"
         width="1920" height="1280"
         fetchpriority="high"
         class="photo-fade relative z-0 block h-64 w-full object-cover sm:h-96
                lg:absolute lg:inset-y-0 lg:right-0 lg:h-full lg:w-[60%]">

    <!-- The template's blue swoosh, ending in white so it flows into the next section. -->
    <svg class="pointer-events-none absolute inset-x-0 bottom-0 z-10 h-16 w-full sm:h-24 lg:h-36"
         viewBox="0 0 1440 200" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="hero-swoosh" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#C4D9EC"/>
                <stop offset="0.55" stop-color="#1F6FA8"/>
                <stop offset="1" stop-color="#173F63"/>
            </linearGradient>
        </defs>
        <path d="M0 200 V150 C 380 196 920 172 1440 30 V200 Z" fill="url(#hero-swoosh)" opacity="0.35"/>
        <path d="M0 200 V168 C 430 204 990 178 1440 78 V200 Z" fill="url(#hero-swoosh)"/>
        <path d="M0 200 V186 C 490 214 1050 194 1440 128 V200 Z" fill="#FFFFFF"/>
    </svg>
</section>

<!-- ======================================================================
     How enrollment works
     ====================================================================== -->
<section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="steps-heading">
    <p class="eyebrow">Enrollment in four steps</p>
    <h2 id="steps-heading" class="mt-2 text-2xl sm:text-3xl">How enrollment works</h2>

    <ol class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $steps = [
            ['Create an account', 'Register with your email and complete your student profile.'],
            ['Apply online', 'Choose your program, year level, and term, then submit your application.'],
            ['Submit requirements', 'Upload your documents and track their verification status.'],
            ['Pay and enroll', 'Settle your assessment, then receive your schedule and section.'],
        ];
        foreach ($steps as $index => [$stepTitle, $stepBody]):
        ?>
            <li class="card p-6">
                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 font-display text-lg font-extrabold text-brand-600 ring-1 ring-brand-200">
                    <?= $index + 1 ?>
                </span>
                <h3 class="mt-5 text-base"><?= e($stepTitle) ?></h3>
                <p class="mt-2 text-sm leading-relaxed text-brand-gray"><?= e($stepBody) ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<!-- ======================================================================
     Programs — the template's swatch cards
     ====================================================================== -->
<section class="bg-brand-mint/60" aria-labelledby="programs-heading">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Programs offered</p>
                <h2 id="programs-heading" class="mt-2 text-2xl sm:text-3xl">Find your program</h2>
            </div>
            <a href="<?= e(url('/programs')) ?>" class="font-display text-sm font-semibold text-brand-600 hover:underline">
                View all programs
            </a>
        </div>

        <?php if ($programs === []): ?>
            <div class="card mt-10">
                <?= component('empty-state', ['title' => 'No programs published yet']) ?>
            </div>
        <?php else: ?>
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($programs as $index => $program): ?>
                    <article class="flex flex-col">
                        <!-- Large bold text only on the swatch, so the light swatches keep contrast. -->
                        <div class="flex h-32 items-end rounded-2xl p-5 shadow-sm <?= $swatches[$index % count($swatches)] ?>">
                            <p class="font-display text-3xl font-extrabold tracking-tight"><?= e($program['code']) ?></p>
                        </div>
                        <h3 class="mt-4 text-base leading-snug"><?= e($program['name']) ?></h3>
                        <p class="mt-1 font-display text-sm font-semibold text-brand-600">
                            <?= e($program['years_duration']) ?> years · 2 semesters a year
                        </p>
                        <p class="mt-2 text-sm leading-relaxed text-brand-gray">
                            <?= e(str_limit((string) $program['description'], 110)) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ======================================================================
     Enrollment call to action — the template's brand gradient
     ====================================================================== -->
<section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="cta-heading">
    <div class="brand-gradient-band relative overflow-hidden rounded-2xl">
        <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 1200 300"
             preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M520 300 C 760 230 980 120 1200 0 V300 Z" fill="#FFFFFF" opacity="0.08"/>
            <path d="M700 300 C 900 250 1060 160 1200 70 V300 Z" fill="#FFFFFF" opacity="0.08"/>
        </svg>

        <div class="relative flex flex-col gap-10 px-6 py-10 sm:px-10 lg:flex-row lg:items-center lg:justify-between lg:py-14">
            <div class="max-w-lg">
                <p class="eyebrow">Now accepting applications</p>
                <h2 id="cta-heading" class="mt-2 text-2xl sm:text-3xl"><?= e($school['enrollment_open_message'] ?? 'Enrollment is open.') ?></h2>
                <p class="mt-3 text-sm text-brand-navy sm:text-base">
                    Check the requirements, prepare your documents, and apply in minutes.
                </p>
            </div>
            <div class="flex flex-wrap gap-3 lg:justify-end">
                <a href="<?= e(url('/enrollment')) ?>"
                   class="btn btn-lg bg-white text-brand-navy shadow-sm hover:bg-brand-50 focus-visible:ring-white focus-visible:ring-offset-brand-navy">
                    Start your application
                </a>
                <a href="<?= e(url('/requirements')) ?>"
                   class="btn btn-lg border border-white/40 bg-brand-navy text-white hover:bg-brand-800 focus-visible:ring-white focus-visible:ring-offset-brand-navy">
                    See requirements
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ======================================================================
     Announcements
     ====================================================================== -->
<section class="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8" aria-labelledby="news-heading">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="eyebrow">School news</p>
            <h2 id="news-heading" class="mt-2 text-2xl sm:text-3xl">Latest announcements</h2>
        </div>
        <a href="<?= e(url('/announcements')) ?>" class="font-display text-sm font-semibold text-brand-600 hover:underline">
            All announcements
        </a>
    </div>

    <?php if ($announcements === []): ?>
        <div class="card mt-10">
            <?= component('empty-state', [
                'title'   => 'No announcements yet',
                'message' => 'School announcements will be published here.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($announcements as $announcement): ?>
                <article class="card overflow-hidden">
                    <div class="h-1.5 bg-gradient-to-r from-brand-sky via-brand-primary to-brand-navy" aria-hidden="true"></div>
                    <div class="p-6">
                        <time class="font-display text-xs font-semibold uppercase tracking-wider text-brand-gray"
                              datetime="<?= e($announcement['published_at'] ?? '') ?>">
                            <?= e(d($announcement['published_at'] ?? null)) ?>
                        </time>
                        <h3 class="mt-2 text-base">
                            <a class="hover:text-brand-600 hover:underline" href="<?= e(url('/announcements/' . $announcement['slug'])) ?>">
                                <?= e($announcement['title']) ?>
                            </a>
                        </h3>
                        <p class="mt-2 text-sm leading-relaxed text-brand-gray">
                            <?= e(str_limit(strip_tags((string) $announcement['content']), 130)) ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- ======================================================================
     Verify an ID
     ====================================================================== -->
<section class="bg-white" aria-labelledby="verify-home-heading">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-4 py-12 sm:px-6 lg:px-8">
        <div class="max-w-xl">
            <p class="eyebrow">Verify an ID</p>
            <h2 id="verify-home-heading" class="mt-2 text-2xl sm:text-3xl">Is this a real school ID?</h2>
            <p class="mt-2 text-slate-600">Scan the QR code on the card with your phone camera, or type its code here.</p>
        </div>
        <form method="GET" role="search" aria-label="Verify an ID" action="<?= e(url('/verify')) ?>" class="flex w-full max-w-md gap-2" novalidate>
            <label for="verify-home-code" class="sr-only">ID code or address</label>
            <input id="verify-home-code" name="code" class="field-input" autocomplete="off" placeholder="ID code or address">
            <button type="submit" class="btn-primary shrink-0">Check ID</button>
        </form>
    </div>
</section>

<!-- ======================================================================
     Visit us
     ====================================================================== -->
<section class="bg-brand-pale" aria-labelledby="contact-heading">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-5 lg:items-center lg:px-8">
        <div class="lg:col-span-2">
            <p class="eyebrow">Visit or contact us</p>
            <h2 id="contact-heading" class="mt-2 text-2xl sm:text-3xl">We're here to help</h2>
            <p class="mt-3 text-slate-600">
                Questions about enrollment, requirements, or fees? Reach the office that handles them.
            </p>
        </div>

        <dl class="grid gap-4 sm:grid-cols-3 lg:col-span-3">
            <div class="card p-5">
                <dt class="font-display text-sm font-bold text-brand-navy">Address</dt>
                <dd class="mt-1.5 text-sm text-slate-600"><?= e($school['school_address'] ?? '') ?></dd>
            </div>
            <div class="card p-5">
                <dt class="font-display text-sm font-bold text-brand-navy">Office hours</dt>
                <dd class="mt-1.5 text-sm text-slate-600"><?= e($school['office_hours'] ?? '') ?></dd>
            </div>
            <div class="card p-5">
                <dt class="font-display text-sm font-bold text-brand-navy">Contact</dt>
                <dd class="mt-1.5 break-words text-sm text-slate-600">
                    <?= e($school['school_phone'] ?? '') ?><br>
                    <?php if (!empty($school['school_email'])): ?>
                        <a class="text-brand-600 hover:underline" href="mailto:<?= e($school['school_email']) ?>"><?= e($school['school_email']) ?></a>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </div>
</section>
