<?php
/**
 * Academic Records → Curriculum Checklist: the student's program curriculum with each
 * subject marked passed, credited, in progress, or not yet taken (docs/28).
 *
 * @var array   $enrollments
 * @var ?array  $enrollment
 * @var array   $school
 * @var array|null $prospectus
 */
?>
<?= partial('student/records-header', ['tab' => 'prospectus', 'enrollments' => $enrollments, 'enrollment' => $enrollment, 'school' => $school]) ?>

<?= partial('prospectus', ['prospectus' => $prospectus]) ?>
