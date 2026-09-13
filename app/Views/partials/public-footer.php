<?php
/** @var array $school */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<footer class="mt-20 border-t border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white">SH</span>
                    <span class="font-semibold text-brand-navy"><?= e($schoolName) ?></span>
                </div>
                <p class="mt-3 text-sm text-slate-600"><?= e($school['school_tagline'] ?? '') ?></p>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-brand-navy">Enrollment</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/enrollment')) ?>">How to enroll</a></li>
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/requirements')) ?>">Requirements</a></li>
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/programs')) ?>">Programs</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-brand-navy">School</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/about')) ?>">About</a></li>
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/announcements')) ?>">Announcements</a></li>
                    <li><a class="text-slate-600 hover:text-brand-600 hover:underline" href="<?= e(url('/downloads')) ?>">Downloads</a></li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-brand-navy">Contact</h2>
                <address class="mt-3 space-y-2 text-sm not-italic text-slate-600">
                    <p><?= e($school['school_address'] ?? '') ?></p>
                    <?php if (!empty($school['school_phone'])): ?>
                        <p><?= e($school['school_phone']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($school['school_email'])): ?>
                        <p><a class="hover:text-brand-600 hover:underline" href="mailto:<?= e($school['school_email']) ?>"><?= e($school['school_email']) ?></a></p>
                    <?php endif; ?>
                </address>
            </div>
        </div>

        <div class="mt-10 border-t border-slate-200 pt-6 text-sm text-brand-gray">
            &copy; <?= date('Y') ?> <?= e($schoolName) ?>. All rights reserved.
        </div>
    </div>
</footer>
