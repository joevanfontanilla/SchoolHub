<?php
/**
 * Fill the throwaway performance database with a school-sized dataset (docs/22).
 *
 *   SCHOOLHUB_DATABASE=schoolhub_perf php database/migrate.php
 *   SCHOOLHUB_DATABASE=schoolhub_perf php database/seed.php
 *   SCHOOLHUB_DATABASE=schoolhub_perf php database/import_curricula.php
 *   SCHOOLHUB_DATABASE=schoolhub_perf php tests/perf/generate.php
 *
 * Refuses to run against any database but schoolhub_perf. Every account's password is
 * PerfPass123. Data (for the open term): 2,000 students with applications in every status,
 * 1,500 of them enrolled in sections with classes, grades, and attendance; assessments and
 * payments; 30,000 notifications; 100,000 audit entries; 30,000 sign-in attempts.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Term;

if (Config::get('db.database') !== 'schoolhub_perf') {
    fwrite(STDERR, "Refusing: set SCHOOLHUB_DATABASE=schoolhub_perf first (current database: " . Config::get('db.database') . ").\n");
    exit(1);
}
mt_srand(20260915);
$started = microtime(true);
$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('SET unique_checks=0');

/** Multi-row inserts, flushed every 500 rows. */
final class Bulk
{
    private array $rows = [];
    private ?array $columns = null;
    public int $count = 0;

    public function __construct(private string $table)
    {
    }

    public function add(array $row): void
    {
        $this->columns ??= array_keys($row);
        $this->rows[] = array_values($row);
        if (count($this->rows) >= 500) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }
        $tuple = '(' . implode(',', array_fill(0, count($this->columns), '?')) . ')';
        $sql = "INSERT INTO `{$this->table}` (`" . implode('`,`', $this->columns) . '`) VALUES ' . implode(',', array_fill(0, count($this->rows), $tuple));
        Database::query($sql, array_merge(...$this->rows));
        $this->count += count($this->rows);
        $this->rows = [];
    }
}

function lastId(string $table): int
{
    return (int) Database::scalar("SELECT COALESCE(MAX(id), 0) FROM `{$table}`");
}

$now = date('Y-m-d H:i:s');
$hash = password_hash('PerfPass123', PASSWORD_DEFAULT);
$roles = array_column(Database::select('SELECT id, name FROM roles'), 'id', 'name');
$term = Term::openForEnrollment()[0] ?? throw new RuntimeException('No open term. Run the seeder.');
$programs = Database::select("SELECT p.id, p.code, MIN(c.id) AS curriculum_id FROM programs p JOIN curricula c ON c.program_id = p.id WHERE p.is_active = 1 GROUP BY p.id, p.code");
$yearLevels = array_column(Database::select('SELECT id, sequence FROM year_levels ORDER BY sequence'), 'id', 'sequence');
$periods = array_column(Database::select('SELECT id FROM grade_periods WHERE term_id = ? ORDER BY sequence', [$term['id']]), 'id');
$requirementTypes = array_column(Database::select('SELECT id FROM requirement_types ORDER BY sort_order LIMIT 6'), 'id');
$feeTypes = Database::select('SELECT id, name FROM fee_types WHERE is_active = 1 LIMIT 6');
echo "  Term {$term['name']}, " . count($programs) . " programs\n";

// -- Staff ---------------------------------------------------------------------------------
$users = new Bulk('users');
$addUser = static function (string $username, string $role, string $first, string $last) use ($users, $roles, $hash, $now): void {
    $users->add(['role_id' => $roles[$role], 'username' => $username, 'email' => "{$username}@perf.test", 'password_hash' => $hash,
        'first_name' => $first, 'last_name' => $last, 'status' => 'Active', 'must_change_password' => 0, 'created_at' => $now]);
};
$firstUser = lastId('users') + 1;
$addUser('perf_admin', 'Super Admin', 'Perf', 'Admin');
for ($i = 1; $i <= 3; $i++) { $addUser("perf_registrar{$i}", 'Registrar', 'Registrar', "Number {$i}"); }
for ($i = 1; $i <= 3; $i++) { $addUser("perf_cashier{$i}", 'Cashier', 'Cashier', "Number {$i}"); }
for ($i = 1; $i <= 60; $i++) { $addUser("perf_teacher{$i}", 'Teacher', 'Teacher', "Number {$i}"); }
for ($i = 1; $i <= 2000; $i++) { $addUser(sprintf('perf_student%04d', $i), 'Student', ['Ana', 'Ben', 'Carla', 'Dan', 'Ella', 'Fe', 'Gino', 'Hana'][$i % 8], sprintf('Student %04d', $i)); }
$users->flush();
$ids = array_column(Database::select('SELECT id, username FROM users WHERE id >= ?', [$firstUser]), 'id', 'username');
$teacherIds = array_values(array_filter($ids, static fn ($k) => str_starts_with($k, 'perf_teacher'), ARRAY_FILTER_USE_KEY));
$registrarId = $ids['perf_registrar1'];
$cashierId = $ids['perf_cashier1'];
echo "  users " . $users->count . "\n";

// -- Sections, classes, meetings -------------------------------------------------------------
$sections = [];
$classesBySection = [];
$sectionBulk = new Bulk('sections');
foreach ($programs as $program) {
    foreach ($yearLevels as $sequence => $yearId) {
        foreach (['A', 'B'] as $letter) {
            $sectionBulk->add(['code' => "{$program['code']}-{$sequence}{$letter}", 'program_id' => $program['id'], 'year_level_id' => $yearId,
                'term_id' => $term['id'], 'capacity' => 60, 'is_active' => 1, 'created_at' => $now]);
        }
    }
}
$sectionBulk->flush();
foreach (Database::select('SELECT * FROM sections WHERE term_id = ?', [$term['id']]) as $section) {
    $sections[$section['program_id']][$section['year_level_id']][] = $section;
}
$classBulk = new Bulk('class_schedules');
$teacherIndex = 0;
foreach ($programs as $program) {
    foreach ($yearLevels as $sequence => $yearId) {
        $subjects = array_column(Database::select(
            'SELECT id FROM subjects WHERE curriculum_id = ? AND year_level_id = ? AND term_sequence = ? LIMIT 8',
            [$program['curriculum_id'], $yearId, $term['sequence']]
        ), 'id');
        foreach ($sections[$program['id']][$yearId] ?? [] as $section) {
            foreach ($subjects as $subjectId) {
                $classBulk->add(['term_id' => $term['id'], 'section_id' => $section['id'], 'subject_id' => $subjectId,
                    'teacher_user_id' => $teacherIds[$teacherIndex++ % count($teacherIds)], 'status' => 'ACTIVE', 'created_at' => $now]);
            }
        }
    }
}
$classBulk->flush();
$meetings = new Bulk('class_meetings');
$slot = 0;
foreach (Database::select('SELECT id, section_id, subject_id FROM class_schedules WHERE term_id = ?', [$term['id']]) as $class) {
    $classesBySection[$class['section_id']][] = $class;
    $hour = 7 + ($slot++ % 10);
    foreach ([1, 3] as $day) {
        $meetings->add(['class_schedule_id' => $class['id'], 'day_of_week' => $day + ($slot % 2), 'start_time' => sprintf('%02d:00:00', $hour),
            'end_time' => sprintf('%02d:30:00', $hour + 1), 'created_at' => $now]);
    }
}
$meetings->flush();
echo "  sections " . $sectionBulk->count . ", classes " . $classBulk->count . "\n";

// -- Students and applications ---------------------------------------------------------------
$students = new Bulk('students');
$profiles = new Bulk('student_profiles');
$studentFirst = lastId('students') + 1;
for ($i = 1; $i <= 2000; $i++) {
    $program = $programs[$i % count($programs)];
    $yearSequence = 1 + ($i % 4);
    $students->add(['user_id' => $ids[sprintf('perf_student%04d', $i)], 'student_number' => $i <= 1500 ? sprintf('2026-%05d', 10000 + $i) : null,
        'program_id' => $program['id'], 'curriculum_id' => $program['curriculum_id'], 'year_level_id' => $yearLevels[$yearSequence],
        'student_type' => 'New', 'status' => $i <= 1500 ? 'Enrolled' : 'Applicant', 'created_at' => $now]);
}
$students->flush();
$studentRows = Database::select('SELECT id, user_id, program_id, curriculum_id, year_level_id FROM students WHERE id >= ? ORDER BY id', [$studentFirst]);
foreach ($studentRows as $student) {
    $profiles->add(['student_id' => $student['id'], 'birthdate' => '2005-06-15', 'sex' => 'Female', 'civil_status' => 'Single', 'mobile' => '09171234567',
        'barangay' => 'Poblacion', 'city_municipality' => 'Baler', 'province' => 'Aurora', 'nationality' => 'Filipino', 'created_at' => $now]);
}
$profiles->flush();

$applications = new Bulk('enrollment_applications');
foreach ($studentRows as $index => $student) {
    $n = $index + 1;
    [$status, $state, $payment] = match (true) {
        $n <= 1500 => ['APPROVED', 'ENROLLED', $n % 3 === 0 ? 'PAID' : 'PARTIAL'],
        $n <= 1700 => ['SUBMITTED', 'NOT_ENROLLED', 'NOT_ASSESSED'],
        $n <= 1800 => ['UNDER_REVIEW', 'NOT_ENROLLED', 'NOT_ASSESSED'],
        $n <= 1900 => ['REQUIREMENTS_INCOMPLETE', 'NOT_ENROLLED', 'NOT_ASSESSED'],
        default    => ['DRAFT', 'NOT_ENROLLED', 'NOT_ASSESSED'],
    };
    $sectionList = $sections[$student['program_id']][$student['year_level_id']] ?? [];
    $applications->add(['application_number' => sprintf('APP-2026-%05d', 10000 + $n), 'student_id' => $student['id'], 'academic_year_id' => $term['academic_year_id'],
        'term_id' => $term['id'], 'program_id' => $student['program_id'], 'year_level_id' => $student['year_level_id'],
        'section_id' => $n <= 1500 && $sectionList !== [] ? $sectionList[$n % count($sectionList)]['id'] : null, 'student_type' => 'New',
        'application_status' => $status, 'payment_status' => $payment, 'enrollment_state' => $state,
        'submitted_at' => $status === 'DRAFT' ? null : date('Y-m-d H:i:s', strtotime("-{$n} minutes")), 'created_at' => date('Y-m-d H:i:s', strtotime("-{$n} minutes"))]);
}
$applications->flush();
$applicationRows = Database::select('SELECT * FROM enrollment_applications WHERE student_id >= ? ORDER BY id', [$studentFirst]);
echo "  students 2000, applications " . $applications->count . "\n";

$requirements = new Bulk('enrollment_requirements');
$history = new Bulk('enrollment_status_history');
$chosen = new Bulk('application_subjects');
foreach ($applicationRows as $application) {
    foreach ($requirementTypes as $typeId) {
        $requirements->add(['application_id' => $application['id'], 'requirement_type_id' => $typeId, 'is_required' => 1,
            'status' => $application['application_status'] === 'APPROVED' ? 'VERIFIED' : ($application['application_status'] === 'DRAFT' ? 'NOT_SUBMITTED' : 'SUBMITTED'), 'created_at' => $now]);
    }
    if ($application['application_status'] !== 'DRAFT') {
        foreach (['SUBMITTED', 'UNDER_REVIEW', $application['application_status']] as $step => $value) {
            $history->add(['application_id' => $application['id'], 'axis' => 'application_status', 'previous_value' => $step === 0 ? 'DRAFT' : null,
                'new_value' => $value, 'actor_user_id' => $step === 0 ? null : $registrarId, 'actor_role' => $step === 0 ? 'Student' : 'Registrar', 'created_at' => $now]);
        }
    }
    $classes = $application['section_id'] ? ($classesBySection[$application['section_id']] ?? []) : [];
    $subjectIds = $classes !== [] ? array_column($classes, 'subject_id') : array_column(Database::select('SELECT id FROM subjects WHERE year_level_id = ? AND term_sequence = ? LIMIT 6', [$application['year_level_id'], $term['sequence']]), 'id');
    foreach (array_unique($subjectIds) as $subjectId) {
        $chosen->add(['application_id' => $application['id'], 'subject_id' => $subjectId, 'created_at' => $now]);
    }
}
$requirements->flush();
$history->flush();
$chosen->flush();
$files = new Bulk('enrollment_requirement_files');
foreach (Database::select("SELECT er.id, s.user_id FROM enrollment_requirements er JOIN enrollment_applications ea ON ea.id = er.application_id JOIN students s ON s.id = ea.student_id WHERE er.status <> 'NOT_SUBMITTED' AND s.id >= ?", [$studentFirst]) as $requirement) {
    $files->add(['enrollment_requirement_id' => $requirement['id'], 'original_filename' => 'scan.pdf', 'stored_filename' => "perf-{$requirement['id']}.pdf", 'storage_path' => "uploads/requirements/perf/perf-{$requirement['id']}.pdf",
        'mime_type' => 'application/pdf', 'file_size' => 12345, 'checksum' => str_repeat('0', 64), 'uploaded_by' => $requirement['user_id'], 'uploaded_at' => $now, 'is_current' => 1]);
}
$files->flush();
echo "  requirements " . $requirements->count . ", files " . $files->count . ", history " . $history->count . "\n";

// -- Enrollments, enlistments, grades, attendance ---------------------------------------------
$enrollments = new Bulk('enrollments');
foreach ($applicationRows as $application) {
    if ($application['enrollment_state'] === 'ENROLLED' && $application['section_id']) {
        $enrollments->add(['application_id' => $application['id'], 'student_id' => $application['student_id'], 'term_id' => $term['id'], 'section_id' => $application['section_id'],
            'total_units' => 21, 'enrolled_at' => $now, 'enrolled_by' => $registrarId, 'status' => 'ENROLLED', 'created_at' => $now]);
    }
}
$enrollments->flush();
$enlistments = new Bulk('class_enlistments');
foreach (Database::select('SELECT id, section_id FROM enrollments WHERE term_id = ?', [$term['id']]) as $enrollment) {
    foreach ($classesBySection[$enrollment['section_id']] ?? [] as $class) {
        $enlistments->add(['enrollment_id' => $enrollment['id'], 'class_schedule_id' => $class['id'], 'status' => 'ENLISTED', 'enlisted_at' => $now, 'enlisted_by' => $registrarId, 'created_at' => $now]);
    }
}
$enlistments->flush();
$grades = new Bulk('grades');
$attendance = new Bulk('attendance');
$validGrades = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];
$dates = array_map(static fn ($d) => date('Y-m-d', strtotime("-{$d} weeks monday")), [1, 2, 3, 4]);
foreach (Database::select('SELECT ce.id, cs.teacher_user_id FROM class_enlistments ce JOIN class_schedules cs ON cs.id = ce.class_schedule_id WHERE cs.term_id = ?', [$term['id']]) as $enlistment) {
    foreach (array_slice($periods, 0, 2) as $periodId) {
        $grades->add(['class_enlistment_id' => $enlistment['id'], 'grade_period_id' => $periodId, 'grade' => $validGrades[mt_rand(0, 9)],
            'recorded_by' => $enlistment['teacher_user_id'], 'recorded_at' => $now, 'created_at' => $now]);
    }
    foreach ($dates as $date) {
        $attendance->add(['class_enlistment_id' => $enlistment['id'], 'attendance_date' => $date, 'status' => ['PRESENT', 'PRESENT', 'PRESENT', 'LATE', 'ABSENT'][mt_rand(0, 4)],
            'recorded_by' => $enlistment['teacher_user_id'], 'recorded_at' => $now, 'created_at' => $now]);
    }
}
$grades->flush();
$attendance->flush();
echo "  enrollments " . $enrollments->count . ", enlistments " . $enlistments->count . ", grades " . $grades->count . ", attendance " . $attendance->count . "\n";

// -- Finance ------------------------------------------------------------------------------------
$assessments = new Bulk('fee_assessments');
foreach ($applicationRows as $index => $application) {
    if ($application['application_status'] !== 'APPROVED') {
        continue;
    }
    $paid = $application['payment_status'] === 'PAID' ? 15000 : mt_rand(3, 12) * 1000;
    $assessments->add(['assessment_number' => sprintf('ASM-2026-%05d', 10000 + $index), 'application_id' => $application['id'], 'student_id' => $application['student_id'],
        'term_id' => $term['id'], 'total_amount' => '15000.00', 'total_adjustments' => '0.00', 'total_paid' => number_format($paid, 2, '.', ''),
        'balance' => number_format(15000 - $paid, 2, '.', ''), 'status' => 'ACTIVE', 'assessed_by' => $registrarId, 'assessed_at' => date('Y-m-d H:i:s', strtotime("-{$index} hours")), 'created_at' => $now]);
}
$assessments->flush();
$items = new Bulk('fee_items');
$payments = new Bulk('payments');
$submissions = new Bulk('payment_submissions');
$receipt = 10000;
foreach (Database::select('SELECT * FROM fee_assessments WHERE term_id = ?', [$term['id']]) as $index => $assessment) {
    foreach ($feeTypes as $order => $fee) {
        $items->add(['fee_assessment_id' => $assessment['id'], 'fee_type_id' => $fee['id'], 'description' => $fee['name'], 'basis' => 'FIXED', 'quantity' => 1,
            'unit_amount' => '2500.00', 'amount' => '2500.00', 'sort_order' => $order]);
    }
    $remaining = (float) $assessment['total_paid'];
    $parts = $remaining >= 15000 ? [5000, 10000] : [$remaining];
    foreach ($parts as $part) {
        $receipt++;
        $payments->add(['transaction_number' => "PAY-2026-{$receipt}", 'receipt_number' => "OR-2026-{$receipt}", 'fee_assessment_id' => $assessment['id'],
            'student_id' => $assessment['student_id'], 'amount' => number_format($part, 2, '.', ''), 'payment_method' => ['Cash', 'GCash', 'Maya'][$receipt % 3],
            'paid_at' => date('Y-m-d H:i:s', strtotime('-' . ($receipt % 40) . ' days')), 'cashier_user_id' => $cashierId, 'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($receipt % 40) . ' days'))]);
    }
    if ($index % 10 === 0) {
        $submissions->add(['fee_assessment_id' => $assessment['id'], 'student_id' => $assessment['student_id'], 'amount' => '1000.00', 'method' => 'GCash',
            'reference_number' => "GC{$index}", 'paid_on' => date('Y-m-d'), 'original_filename' => 'proof.png', 'stored_filename' => "perf-{$index}.png", 'storage_path' => "uploads/payments/perf/perf-{$index}.png",
            'mime_type' => 'image/png', 'file_size' => 1000, 'checksum' => str_repeat('0', 64), 'status' => 'PENDING', 'submitted_at' => $now, 'created_at' => $now]);
    }
}
$items->flush();
$payments->flush();
$submissions->flush();
echo "  assessments " . $assessments->count . ", items " . $items->count . ", payments " . $payments->count . ", submissions " . $submissions->count . "\n";

// -- Communication and logs ------------------------------------------------------------------------
$announcements = new Bulk('announcements');
for ($i = 1; $i <= 60; $i++) {
    $announcements->add(['title' => "School announcement {$i}", 'slug' => "perf-announcement-{$i}", 'content' => str_repeat("Details of announcement {$i}. ", 20),
        'category' => ['General', 'Enrollment', 'Academic', 'Finance', 'Events', 'Advisory'][$i % 6], 'audience' => $i % 3 === 0 ? 'TARGETED' : 'EVERYONE',
        'is_public' => $i % 3 === 0 ? 0 : 1, 'is_urgent' => $i % 20 === 0 ? 1 : 0, 'status' => 'PUBLISHED', 'published_at' => date('Y-m-d H:i:s', strtotime("-{$i} days")),
        'notified_at' => $now, 'recipient_count' => 2000, 'author_user_id' => $registrarId, 'published_by' => $registrarId, 'created_at' => $now]);
}
$announcements->flush();
$targets = new Bulk('announcement_targets');
foreach (Database::select("SELECT id FROM announcements WHERE audience = 'TARGETED'") as $row) {
    $targets->add(['announcement_id' => $row['id'], 'target_type' => 'ROLE', 'target_id' => $roles['Student']]);
    $targets->add(['announcement_id' => $row['id'], 'target_type' => 'PROGRAM', 'target_id' => $programs[$row['id'] % count($programs)]['id']]);
}
$targets->flush();
$notifications = new Bulk('notifications');
foreach ($studentRows as $index => $student) {
    for ($k = 0; $k < 15; $k++) {
        $notifications->add(['user_id' => $student['user_id'], 'type' => ['enrollment.approved', 'finance.payment_recorded', 'grades.released', 'announcement.published'][$k % 4],
            'title' => "Update {$k} for you", 'body' => 'Something happened on your account.', 'link_url' => '/student/account', 'state' => $k < 8 ? 'UNREAD' : 'READ',
            'created_at' => date('Y-m-d H:i:s', strtotime("-{$k} days -{$index} minutes"))]);
    }
}
$notifications->flush();
$audit = new Bulk('audit_logs');
$actions = ['login.success', 'enrollment.status_changed', 'finance.payment_recorded', 'grades.recorded', 'requirement.verified', 'announcement.published'];
for ($i = 0; $i < 100000; $i++) {
    $audit->add(['user_id' => $ids[sprintf('perf_student%04d', 1 + $i % 2000)], 'actor_role' => 'Student', 'action' => $actions[$i % 6], 'module' => explode('.', $actions[$i % 6])[0],
        'target_type' => 'User', 'target_id' => $i % 5000, 'ip_address' => '10.0.' . ($i % 250) . '.' . ($i % 200), 'user_agent' => 'Perf', 'result' => $i % 50 === 0 ? 'FAILURE' : 'SUCCESS',
        'created_at' => date('Y-m-d H:i:s', time() - $i * 60)]);
}
$audit->flush();
$logins = new Bulk('login_logs');
for ($i = 0; $i < 30000; $i++) {
    $logins->add(['user_id' => $ids[sprintf('perf_student%04d', 1 + $i % 2000)], 'username_attempted' => sprintf('perf_student%04d', 1 + $i % 2000), 'ip_address' => '10.1.' . ($i % 250) . '.' . ($i % 200),
        'user_agent' => 'Perf', 'result' => $i % 10 === 0 ? 'FAILED' : 'SUCCESS', 'attempted_at' => date('Y-m-d H:i:s', time() - $i * 300 - 86400)]);
}
$logins->flush();
echo "  announcements 60, notifications " . $notifications->count . ", audit " . $audit->count . ", sign-ins " . $logins->count . "\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('SET unique_checks=1');
Database::query('ANALYZE TABLE users, students, enrollment_applications, enrollment_requirements, class_schedules, class_enlistments, grades, attendance, fee_assessments, payments, notifications, audit_logs, login_logs, announcements');
printf("  Done in %.1f s\n", microtime(true) - $started);
