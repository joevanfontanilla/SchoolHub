<?php
/**
 * Edit a class's teacher and meetings. The subject is fixed once the class exists.
 *
 * @var array $class
 * @var array $section
 * @var array $teachers
 * @var array $rooms
 */
$back = '/registrar/sections/' . $section['id'] . '/classes';
$teacherError = error_for('teacher_user_id');
$currentTeacher = (string) old('teacher_user_id', (string) ($class['teacher_user_id'] ?? ''));
?>
<div class="mx-auto max-w-3xl space-y-6">
    <a href="<?= e(url($back)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Classes of <?= e($section['code']) ?></a>
    <div>
        <p class="eyebrow"><?= e($section['code']) ?> · <?= e($section['term_name']) ?> <?= e($section['academic_year_code']) ?></p>
        <h2 class="mt-1 text-2xl"><?= e($class['code']) ?> · <?= e($class['title']) ?></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($class['enlisted_count']) ?> enlisted. Changes are checked against teacher, room, section, and student timetables.</p>
    </div>

    <form method="POST" action="<?= e(url('/registrar/classes/' . $class['id'] . '/edit')) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="max-w-sm">
            <label for="field-teacher_user_id" class="field-label">Teacher <span class="text-brand-alert" aria-hidden="true">*</span></label>
            <select id="field-teacher_user_id" name="teacher_user_id" class="field-input <?= $teacherError ? 'field-input-error' : '' ?>">
                <option value="">Choose a teacher</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?= e($teacher['id']) ?>" <?= $currentTeacher === (string) $teacher['id'] ? 'selected' : '' ?>><?= e($teacher['last_name'] . ', ' . $teacher['first_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($teacherError): ?><p class="field-error"><span><?= e($teacherError) ?></span></p><?php endif; ?>
        </div>

        <?= partial('class-meetings', ['meetings' => $class['meetings'], 'rooms' => $rooms]) ?>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Save class</button>
            <a href="<?= e(url($back)) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
