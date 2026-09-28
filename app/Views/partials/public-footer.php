<?php
/**
 * Public site footer: navy, opened by the template's diagonal stripe band.
 *
 * Link color is brand-sky on navy (6.7:1) and body text white/80 — both clear
 * WCAG AA on the navy ground.
 *
 * @var array $school
 */
$schoolName = $school['school_name'] ?? 'Lyceum of the East Aurora';
$linkClass  = 'text-white/80 hover:text-brand-sky hover:underline';
?>
<footer class="no-print bg-brand-navy text-white">
    <!-- Stripe band on the white-to-navy seam, so every stripe stays visible. -->
    <div class="brand-stripes" aria-hidden="true"></div>
    <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="flex items-center gap-3">
                    <img src="<?= e(school_logo_url()) ?>" alt="" width="56" height="56"
                         class="school-seal h-14 w-14 ring-white/80">
                    <p class="font-display text-sm font-extrabold uppercase leading-tight tracking-tight">
                        <?= e($schoolName) ?>
                    </p>
                </div>
                <p class="mt-4 font-display text-[11px] font-semibold uppercase tracking-brand text-brand-sky">
                    <?= e($school['school_tagline'] ?? '') ?>
                </p>
            </div>

            <nav aria-labelledby="footer-enrollment">
                <h2 id="footer-enrollment" class="font-display text-xs font-bold uppercase tracking-brand text-white">Enrollment</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/enrollment')) ?>">How to enroll</a></li>
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/requirements')) ?>">Requirements</a></li>
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/programs')) ?>">Programs</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="footer-school">
                <h2 id="footer-school" class="font-display text-xs font-bold uppercase tracking-brand text-white">School</h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/about')) ?>">About</a></li>
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/announcements')) ?>">Announcements</a></li>
                    <li><a class="<?= $linkClass ?>" href="<?= e(url('/downloads')) ?>">Downloads</a></li>
                </ul>
            </nav>

            <div>
                <h2 class="font-display text-xs font-bold uppercase tracking-brand text-white">Contact</h2>
                <address class="mt-4 space-y-2.5 text-sm not-italic text-white/80">
                    <p><?= e($school['school_address'] ?? '') ?></p>
                    <?php if (!empty($school['school_phone'])): ?>
                        <p><?= e($school['school_phone']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($school['school_email'])): ?>
                        <p><a class="<?= $linkClass ?>" href="mailto:<?= e($school['school_email']) ?>"><?= e($school['school_email']) ?></a></p>
                    <?php endif; ?>
                </address>
            </div>
        </div>

        <div class="mt-12 border-t border-white/15 pt-6 text-sm text-white/70">
            &copy; <?= date('Y') ?> <?= e($schoolName) ?>. All rights reserved.
        </div>
    </div>
</footer>
