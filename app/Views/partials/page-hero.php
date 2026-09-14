<?php
/**
 * Portal page header: large title, subtitle, breadcrumb, the campus photo, and
 * optional tabs (docs/21). Tabs are ordinary links, one page each, so they work
 * without JavaScript and every tab has its own URL.
 *
 * @var string $heading
 * @var ?string $subtitle
 * @var array  $breadcrumbs [label, path|null]
 * @var array  $tabs        [path, label, icon, active]
 * @var ?string $tagline
 */
$tabs = $tabs ?? [];
$breadcrumbs = $breadcrumbs ?? [];
?>
<section class="relative -mx-4 -mt-6 mb-6 overflow-hidden border-b border-brand-100 bg-gradient-to-r from-white via-white to-brand-50 sm:-mx-6 lg:-mx-8">
    <img src="<?= e(asset('assets/images/campus.jpg')) ?>" alt=""
         class="photo-fade pointer-events-none absolute inset-y-0 right-0 hidden h-full w-[48%] object-cover lg:block">

    <div class="relative px-4 pt-6 sm:px-6 lg:px-8 lg:pt-8">
        <?php if ($breadcrumbs !== []): ?>
            <nav aria-label="Breadcrumb" class="mb-3 lg:absolute lg:right-8 lg:top-5 lg:mb-0">
                <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-600 lg:rounded-full lg:bg-white/85 lg:px-3 lg:py-1 lg:shadow-sm">
                    <?php foreach ($breadcrumbs as $index => [$label, $path]): ?>
                        <li class="flex items-center gap-1.5">
                            <?php if ($index > 0): ?><?= icon('chevron-right', 'h-3.5 w-3.5 text-brand-gray') ?><?php endif; ?>
                            <?php if ($path !== null): ?>
                                <a href="<?= e(url($path)) ?>" class="hover:text-brand-600 hover:underline"><?= e($label) ?></a>
                            <?php else: ?>
                                <span aria-current="page" class="font-medium text-brand-navy"><?= e($label) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <div class="max-w-2xl pb-6">
            <h2 class="font-display text-3xl font-extrabold tracking-tight text-brand-navy sm:text-4xl"><?= e($heading) ?></h2>
            <?php if (!empty($subtitle)): ?>
                <p class="mt-2 text-base text-slate-600 sm:text-lg"><?= e($subtitle) ?></p>
            <?php endif; ?>
            <?php if (!empty($tagline)): ?>
                <p class="tagline mt-3"><?= e($tagline) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($tabs !== []): ?>
            <nav aria-label="Sections" class="-mb-px flex overflow-x-auto">
                <ul class="flex rounded-t-2xl border border-b-0 border-brand-100 bg-white shadow-sm">
                    <?php foreach ($tabs as [$path, $label, $iconName, $active]): ?>
                        <li>
                            <a href="<?= e(url($path)) ?>"
                               class="flex items-center gap-2.5 whitespace-nowrap border-b-[3px] px-5 py-3.5 text-[15px] font-medium transition <?= $active ? 'border-brand-600 font-semibold text-brand-navy' : 'border-transparent text-slate-600 hover:border-brand-200 hover:text-brand-navy' ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <span class="<?= $active ? 'text-brand-600' : 'text-slate-500' ?>"><?= icon($iconName, 'h-5 w-5') ?></span>
                                <?= e($label) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</section>
