<?php
/**
 * A section's classes, its weekly timetable, and the add-class form (docs/13).
 *
 * @var array $section
 * @var array $classes
 * @var array $blocks
 * @var array $subjects  subjects that can still be added
 * @var array $teachers
 * @var array $rooms
 */
use App\Services\ClassScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$active = array_filter($classes, static fn ($c) => $c['status'] === 'ACTIVE');
$units = array_sum(array_map(static fn ($c) => (float) $c['units'], $active));
$failed = errors() !== [];
$subjectError = error_for('subject_id');
$teacherError = error_for('teacher_user_id');
?>
<div class="space-y-6">
    <a href="<?= e(url('/registrar/sections')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Sections</a>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($section['program_code']) ?> · <?= e($section['year_level_name']) ?> · <?= e($section['term_name']) ?> <?= e($section['academic_year_code']) ?></p>
            <h2 class="mt-1 text-2xl">Classes of <?= e($section['code']) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= count($active) ?> active class<?= count($active) === 1 ? '' : 'es' ?> · <?= e($number($units)) ?> units.
                Students enrolled in this section are enlisted in every class, including ones added later.
            </p>
        </div>
        <a href="#add-class" class="btn-primary">Add a class</a>
    </div>

    <section class="card p-4 sm:p-5" aria-labelledby="timetable-heading">
        <h3 id="timetable-heading" class="mb-3 text-base">Weekly timetable</h3>
        <?= partial('schedule-week', ['blocks' => $blocks, 'highlightToday' => false]) ?>
    </section>

    <section class="card" aria-labelledby="classes-heading">
        <h3 id="classes-heading" class="border-b border-slate-200 px-5 py-4 text-base">Classes</h3>
        <?php if ($classes === []): ?>
            <?= component('empty-state', ['title' => 'No classes yet', 'message' => 'Add this section\'s subjects below, with a teacher and meeting times.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Subject</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Units</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Meetings</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Teacher</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Enlisted</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($classes as $class): ?>
                            <?php $isActive = $class['status'] === 'ACTIVE'; ?>
                            <tr class="<?= $isActive ? '' : 'text-brand-gray' ?>">
                                <td class="px-4 py-3">
                                    <span class="font-display font-bold <?= $isActive ? 'text-brand-navy' : '' ?>"><?= e($class['code']) ?></span>
                                    <span class="block text-xs"><?= e($class['title']) ?></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($number($class['units'])) ?></td>
                                <td class="px-4 py-3">
                                    <?php foreach ($class['meetings'] as $meeting): ?>
                                        <span class="block whitespace-nowrap">
                                            <?= e(substr(ClassScheduleService::DAYS[(int) $meeting['day_of_week']], 0, 3)) ?>
                                            <?= e(ClassScheduleService::timeRange($meeting['start_time'], $meeting['end_time'])) ?>
                                            <?= $meeting['room_code'] ? '· ' . e($meeting['room_code']) : '' ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3"><?= $class['teacher_name'] ? e($class['teacher_name']) : '<span class="text-warning-text">Not assigned</span>' ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($class['enlisted_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $isActive ? 'OPEN' : 'CLOSED', 'label' => $isActive ? 'Active' : 'Closed']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <?php if ($isActive): ?>
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="<?= e(url('/registrar/classes/' . $class['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($class['code']) ?></span></a>
                                            <form method="POST" action="<?= e(url('/registrar/classes/' . $class['id'] . '/close')) ?>"
                                                  data-confirm="Close <?= e($class['code']) ?>? Its <?= e($class['enlisted_count']) ?> enlisted student(s) are dropped from it. A class that already has grades or attendance cannot be closed.">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="font-semibold text-danger-text hover:underline">Close<span class="sr-only"> <?= e($class['code']) ?></span></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section id="add-class" class="card" aria-labelledby="add-heading">
        <h3 id="add-heading" class="border-b border-slate-200 px-5 py-4 text-base">Add a class</h3>
        <?php if ($subjects === []): ?>
            <?= component('empty-state', ['title' => 'Every available subject already has a class in this section']) ?>
        <?php else: ?>
            <form method="POST" action="<?= e(url('/registrar/sections/' . $section['id'] . '/classes')) ?>" class="space-y-5 p-5" novalidate>
                <?= csrf_field() ?>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="field-subject_id" class="field-label">Subject <span class="text-brand-alert" aria-hidden="true">*</span></label>
                        <select id="field-subject_id" name="subject_id" class="field-input <?= $subjectError ? 'field-input-error' : '' ?>" required>
                            <option value="">Choose a subject</option>
                            <?php $group = null; ?>
                            <?php foreach ($subjects as $subject): ?>
                                <?php $label = (int) $subject['matches_term'] === 1 ? 'This year level and term' : ($subject['curriculum_code'] ?? 'General subjects'); ?>
                                <?php if ($label !== $group): ?>
                                    <?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($label) ?>">
                                    <?php $group = $label; ?>
                                <?php endif; ?>
                                <option value="<?= e($subject['id']) ?>" <?= $failed && (string) old('subject_id') === (string) $subject['id'] ? 'selected' : '' ?>>
                                    <?= e($subject['code'] . ' · ' . $subject['title'] . ' (' . $number($subject['units']) . ' u' . ($subject['curriculum_code'] ? ', ' . $subject['curriculum_code'] : '') . ')') ?>
                                </option>
                            <?php endforeach; ?>
                            <?= $group !== null ? '</optgroup>' : '' ?>
                        </select>
                        <?php if ($subjectError): ?><p class="field-error"><span><?= e($subjectError) ?></span></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="field-teacher_user_id" class="field-label">Teacher <span class="text-brand-alert" aria-hidden="true">*</span></label>
                        <select id="field-teacher_user_id" name="teacher_user_id" class="field-input <?= $teacherError ? 'field-input-error' : '' ?>">
                            <option value="">Choose a teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= e($teacher['id']) ?>" <?= $failed && (string) old('teacher_user_id') === (string) $teacher['id'] ? 'selected' : '' ?>><?= e($teacher['last_name'] . ', ' . $teacher['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($teacherError): ?><p class="field-error"><span><?= e($teacherError) ?></span></p><?php endif; ?>
                    </div>
                </div>

                <?= partial('class-meetings', ['meetings' => [], 'rooms' => $rooms]) ?>

                <button type="submit" class="btn-primary">Add class</button>
            </form>
        <?php endif; ?>
    </section>
</div>
