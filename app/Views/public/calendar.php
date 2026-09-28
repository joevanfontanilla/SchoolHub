<?php
/**
 * Public academic calendar (docs/09). The read-only calendar for prospective students
 * and the public, using the shared partial that the portal page also renders.
 *
 * @var array<int,string> $years
 * @var string            $year
 * @var array             $groups
 */
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Academic Calendar</h1>
            <p class="mt-2 text-slate-600">Semester dates, examination periods, and no-class days.</p>
        </div>
        <?= partial('print-button') ?>
    </div>

    <div class="mt-8">
        <?= partial('calendar-view', ['years' => $years, 'year' => $year, 'groups' => $groups, 'calendarPath' => '/calendar']) ?>
    </div>
</div>
