<?php
/**
 * Portal academic calendar (docs/09): the read-only calendar for signed-in users, using
 * the same shared partial as the public page.
 *
 * @var array<int,string> $years
 * @var string            $year
 * @var array             $groups
 * @var App\Models\User   $user
 */
?>
<?= partial('page-hero', [
    'heading'     => 'Academic Calendar',
    'subtitle'    => 'Semester dates, examination periods, and no-class days.',
    'breadcrumbs' => [['Home', $user->isStudent() ? '/student/dashboard' : null], ['Academic Calendar', null]],
    'print'       => true,
]) ?>

<?php if (($user->hasPermission('calendar.manage'))): ?>
    <div class="mb-6 flex justify-end">
        <a href="<?= e(url('/manage/calendar')) ?>" class="btn-secondary"><?= icon('pencil', 'h-5 w-5') ?> Manage calendar</a>
    </div>
<?php endif; ?>

<?= partial('calendar-view', ['years' => $years, 'year' => $year, 'groups' => $groups, 'calendarPath' => '/portal/calendar']) ?>
