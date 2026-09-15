<?php
/**
 * Time the main pages of every role against the performance database (docs/22).
 *
 *   php -S 127.0.0.1:8099 -t public tests/perf/router.php                (in one terminal)
 *   SCHOOLHUB_DATABASE=schoolhub_perf php tests/perf/measure.php [--base=http://127.0.0.1:8099]
 *
 * Each page is requested 3 times; the report shows the median server time
 * (X-Debug-Time-Ms), the database statements per request (X-Debug-Queries), and the
 * size. Pages over 300 ms or 60 statements are flagged. Exit status 1 if any is flagged.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (Config::get('db.database') !== 'schoolhub_perf') {
    fwrite(STDERR, "Set SCHOOLHUB_DATABASE=schoolhub_perf so the page ids come from the performance database.\n");
    exit(1);
}
$options = getopt('', ['base::']);
$base = rtrim((string) ($options['base'] ?? 'http://127.0.0.1:8099'), '/');
const SLOW_MS = 300;
const MANY_QUERIES = 60;

function request(string $url, string $jar, array $post = []): array
{
    $curl = curl_init($url);
    $headers = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($post !== []) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($curl);
    $result = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'total' => curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000,
        'server' => (int) ($headers['x-debug-time-ms'] ?? 0), 'queries' => (int) ($headers['x-debug-queries'] ?? 0), 'bytes' => strlen($body), 'body' => $body];
    curl_close($curl);
    return $result;
}

function login(string $base, string $username): string
{
    $jar = tempnam(sys_get_temp_dir(), 'shperf');
    $page = request($base . '/login', $jar);
    preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $m);
    request($base . '/login', $jar, ['_token' => $m[1] ?? '', 'login' => $username, 'password' => 'PerfPass123']);
    return $jar;
}

$one = static fn (string $sql, array $b = []) => Database::scalar($sql, $b);
$student = Database::selectOne("SELECT u.username, s.id AS student_id, ea.id AS application_id, fa.id AS assessment_id
    FROM users u JOIN students s ON s.user_id = u.id JOIN enrollment_applications ea ON ea.student_id = s.id JOIN fee_assessments fa ON fa.application_id = ea.id
    WHERE u.username LIKE 'perf_student%' AND ea.enrollment_state = 'ENROLLED' ORDER BY u.id LIMIT 1");
$teacher = Database::selectOne("SELECT u.username, cs.id AS class_id FROM class_schedules cs JOIN users u ON u.id = cs.teacher_user_id
    JOIN class_enlistments ce ON ce.class_schedule_id = cs.id GROUP BY cs.id, u.username ORDER BY COUNT(ce.id) DESC LIMIT 1");
$sectionId = $one('SELECT section_id FROM class_schedules WHERE id = ?', [$teacher['class_id']]);
$submissionId = $one("SELECT id FROM payment_submissions WHERE status = 'PENDING' LIMIT 1");
$receiptId = $one('SELECT id FROM payments WHERE fee_assessment_id = ? LIMIT 1', [$student['assessment_id']]);
$announcementId = $one("SELECT id FROM announcements WHERE audience = 'EVERYONE' LIMIT 1");
$auditId = $one('SELECT MAX(id) FROM audit_logs');
$month = date('Y-m-01');

$plan = [
    'visitor'   => [null, ['/', '/announcements', '/programs', '/login']],
    'student'   => [$student['username'], ['/student/dashboard', '/student/enrollment', "/student/enrollment/{$student['application_id']}", '/student/requirements',
        '/student/status', '/student/schedule', '/student/grades', '/student/attendance', '/student/history', '/student/account', "/receipts/{$receiptId}",
        "/statements/{$student['assessment_id']}", '/student/schedule/print', '/portal/announcements', "/portal/announcements/{$announcementId}", '/portal/notifications']],
    'teacher'   => [$teacher['username'], ['/teacher/dashboard', '/teacher/classes', "/teacher/classes/{$teacher['class_id']}", "/teacher/classes/{$teacher['class_id']}/attendance",
        "/teacher/classes/{$teacher['class_id']}/grades", '/teacher/grades', '/teacher/schedule', '/teacher/reports',
        "/teacher/classes/{$teacher['class_id']}/reports/class-list", "/teacher/classes/{$teacher['class_id']}/reports/attendance",
        "/teacher/classes/{$teacher['class_id']}/reports/grades"]],
    'registrar' => ['perf_registrar1', ['/registrar/dashboard', '/registrar/applications', '/registrar/applications?status=SUBMITTED', "/registrar/applications/{$student['application_id']}",
        '/registrar/students', '/registrar/students?q=Student%200500', "/registrar/students/{$student['student_id']}", '/registrar/subjects', '/registrar/sections',
        "/registrar/sections/{$sectionId}/classes", '/registrar/grading', '/manage/announcements',
        '/registrar/reports/enrollment', '/registrar/reports/enrollment/print', '/registrar/reports/applications', '/registrar/reports/applications/print',
        '/registrar/reports/requirements', '/registrar/reports/requirements/print', '/registrar/reports/history']],
    'cashier'   => ['perf_cashier1', ['/cashier/dashboard', '/cashier/payments', "/cashier/payments/{$submissionId}", '/cashier/accounts', '/cashier/accounts?q=Student%200500',
        "/cashier/accounts/{$student['assessment_id']}", "/cashier/reports/collections?from={$month}&to=" . date('Y-m-d'), '/cashier/reports/outstanding',
        '/cashier/reports/outstanding/export']],
    'admin'     => ['perf_admin', ['/admin/dashboard', '/admin/users', '/admin/users?q=student', '/admin/audit-logs', '/admin/audit-logs?action=finance.', "/admin/audit-logs/{$auditId}",
        '/admin/login-activity', '/admin/roles', '/admin/settings', '/admin/health', '/admin/backups',
        '/admin/reports/accounts', '/admin/reports/accounts/print', '/admin/reports/sign-ins', '/admin/reports/activity', '/admin/reports/usage']],
];

$flagged = 0;
printf("\n  %-9s %-58s %6s %7s %7s %8s\n", 'role', 'page', 'status', 'server', 'queries', 'size');
foreach ($plan as $role => [$username, $paths]) {
    $jar = $username === null ? tempnam(sys_get_temp_dir(), 'shperf') : login($base, $username);
    foreach ($paths as $path) {
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $runs[] = request($base . $path, $jar);
        }
        usort($runs, static fn ($a, $b) => $a['server'] <=> $b['server']);
        $median = $runs[1];
        $flag = $median['status'] !== 200 || $median['server'] > SLOW_MS || $median['queries'] > MANY_QUERIES;
        $flagged += $flag ? 1 : 0;
        printf("%s %-9s %-58s %6d %5d ms %7d %6d KB\n", $flag ? '!' : ' ', $role, substr($path, 0, 58), $median['status'], $median['server'], $median['queries'], (int) round($median['bytes'] / 1024));
    }
    @unlink($jar);
}
echo "\n  {$flagged} page(s) flagged (over " . SLOW_MS . ' ms, over ' . MANY_QUERIES . " statements, or not 200).\n\n";
exit($flagged === 0 ? 0 : 1);
