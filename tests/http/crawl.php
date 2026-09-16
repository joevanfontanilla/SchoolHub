<?php
/**
 * HTTP acceptance crawl through the real web server (docs/22).
 *
 *   php tests/http/crawl.php [--base=http://localhost/SchoolHub/public] [--report=path.json]
 *   php tests/http/crawl.php --keep --report=path.json   leave the fixtures (for a browser pass)
 *   php tests/http/crawl.php --cleanup                    remove fixtures left by --keep
 *
 * Builds a complete set of fixtures (every role, an enrolled student with a class,
 * grades, a payment, an online payment, a published announcement, uploaded documents),
 * signs in as each role through the login form, and then:
 *
 *   1. requests EVERY GET route in routes/web.php as a visitor and as each role, and
 *      checks the status against the route's own gates (and the ownership rules of the
 *      document routes): no 5xx anywhere, 403 for the wrong role, 404 for another
 *      student's record, the login redirect for visitors;
 *   2. checks security headers and the session cookie flags;
 *   3. plants script-injection payloads in names, titles, and reasons, and fails if any
 *      page returns one unescaped;
 *   4. sends SQL- and script-injection payloads in every filter parameter of every page;
 *   5. POSTs to EVERY state-changing route without a CSRF token and requires 403 with
 *      nothing written;
 *   6. probes files that must never be served (.git, config, storage, docs, tests);
 *   7. checks the HTML of every page for basic accessibility (docs/21): a language and a
 *      title, one main landmark, images with alt text, form fields with labels, links and
 *      buttons with a name, and no duplicate ids.
 *
 * Fixtures use the __test_ / ZZT prefixes and are removed at the end, even on failure.
 * Exit status 1 when anything fails. Requires app.env local (it creates accounts).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__) . '/support.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Core\UploadedFile;
use App\Models\Finance;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\CredentialRequestService;
use App\Services\FinanceService;
use App\Services\PasswordResetService;

if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "Refusing to run against production.\n");
    exit(1);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl extension is required.\n");
    exit(1);
}

$options = getopt('', ['base::', 'report::', 'keep', 'cleanup']);
if (isset($options['cleanup'])) {
    deleteTestLoginLogs();
    deleteTestUsers();
    echo "  Fixtures removed.\n";
    exit(0);
}
$base = rtrim((string) ($options['base'] ?? 'http://localhost/SchoolHub/public'), '/');
$projectRoot = preg_replace('#/public$#', '', $base);
$report = ['base' => $base, 'started' => date('c'), 'pages' => [], 'failures' => []];
$failures = [];
$checks = 0;

const XSS_MARKERS = ['<script>alert("sh-xss")', '<img src=x onerror=alert("sh-xss")'];
const XSS_NAME = '<script>alert("sh-xss")</script>';
const XSS_ATTR = '"><img src=x onerror=alert("sh-xss")>';
const INJECTION = "' OR '1'='1' -- \"><script>alert(\"sh-xss\")</script>";
const FILTER_PARAMS = ['q', 'status', 'state', 'from', 'to', 'filter', 'category', 'term_id', 'program_id', 'year_level_id', 'page', 'role',
    'module', 'action', 'group', 'assessment', 'balance', 'login', 'ip', 'result', 'target_type', 'target_id', 'actor', 'period', 'date', 'section_id', 'type'];

function fail(string $message): void
{
    global $failures;
    $failures[] = $message;
    echo "  FAIL  {$message}\n";
}

/** @return array{status:int,headers:array<string,array<int,string>>,body:string,ms:int,location:string} */
function http(string $method, string $url, ?string $jar, array $post = [], bool $multipart = false): array
{
    $curl = curl_init($url);
    $headers = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($jar !== null) {
        curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $started = microtime(true);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $location = (string) ($headers['location'][0] ?? '');
    curl_close($curl);
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'ms' => (int) round((microtime(true) - $started) * 1000), 'location' => $location];
}

/**
 * Basic accessibility problems in one HTML page (docs/21).
 *
 * @return array<int,string>
 */
function accessibilityProblems(string $html): array
{
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $problems = [];

    $htmlNode = $document->getElementsByTagName('html')->item(0);
    if ($htmlNode === null || $htmlNode->getAttribute('lang') === '') {
        $problems[] = 'no lang on <html>';
    }
    if (trim((string) $document->getElementsByTagName('title')->item(0)?->textContent) === '') {
        $problems[] = 'no <title>';
    }
    if ($document->getElementsByTagName('main')->length !== 1) {
        $problems[] = $document->getElementsByTagName('main')->length . ' <main> landmarks';
    }
    foreach ($document->getElementsByTagName('img') as $img) {
        if (!$img->hasAttribute('alt')) {
            $problems[] = 'image without alt: ' . $img->getAttribute('src');
        }
    }
    $labelled = [];
    foreach ($document->getElementsByTagName('label') as $label) {
        if ($label->getAttribute('for') !== '') {
            $labelled[$label->getAttribute('for')] = true;
        }
    }
    foreach ($xpath->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea') as $field) {
        $insideLabel = $xpath->query('ancestor::label', $field)->length > 0;
        if (!$insideLabel && !isset($labelled[$field->getAttribute('id')]) && $field->getAttribute('aria-label') === '' && $field->getAttribute('aria-labelledby') === '') {
            $problems[] = 'field without a label: ' . ($field->getAttribute('name') ?: $field->nodeName);
        }
    }
    foreach ($xpath->query('//a[@href] | //button | //summary') as $control) {
        $name = trim($control->textContent) . $control->getAttribute('aria-label') . $control->getAttribute('title');
        foreach ($xpath->query('.//img[@alt]', $control) as $img) {
            $name .= $img->getAttribute('alt');
        }
        if (trim($name) === '') {
            $problems[] = 'link or button without a name: ' . substr($document->saveHTML($control), 0, 80);
        }
    }
    $ids = [];
    foreach ($xpath->query('//*[@id]') as $node) {
        $ids[$node->getAttribute('id')] = ($ids[$node->getAttribute('id')] ?? 0) + 1;
    }
    foreach (array_keys(array_filter($ids, static fn ($n) => $n > 1)) as $id) {
        $problems[] = "duplicate id \"{$id}\"";
    }
    return array_values(array_unique($problems));
}

function token(string $body): string
{
    return preg_match('/name="_token" value="([a-f0-9]{64})"/', $body, $m) ? $m[1] : '';
}

function signIn(string $base, string $jar, string $login, string $password): void
{
    @unlink($jar);
    $page = http('GET', $base . '/login', $jar);
    $result = http('POST', $base . '/login', $jar, ['_token' => token($page['body']), 'login' => $login, 'password' => $password]);
    if ($result['status'] !== 302 || str_ends_with($result['location'], '/login')) {
        throw new RuntimeException("Could not sign in as {$login} (status {$result['status']}).");
    }
}

$jars = [];
try {
    echo "\n  SchoolHub HTTP crawl against {$base}\n  Building fixtures…\n";

    // -- Fixtures ------------------------------------------------------------------------
    $enrolled = enrolledStudent();
    $studentA = $enrolled['student'];
    $registrar = $enrolled['registrar'];
    $applicationId = $enrolled['application_id'];
    $teacher = makeUser(User::ROLE_TEACHER);
    $cashier = makeUser(User::ROLE_CASHIER);
    $admin = makeUser(User::ROLE_SUPER_ADMIN);
    $studentB = makeStudent();
    $spare = makeUser(User::ROLE_CASHIER);
    actingAs(null);

    $roomId = makeRoom();
    $subjectId = makeSubject();
    $classId = makeClass($registrar, $enrolled['section_id'], $subjectId, $teacher->id, [meeting((int) date('N', strtotime(lastWeekday(1))), '08:00', '09:00', $roomId)]);
    $assessment = Finance::assessmentForApplication($applicationId);
    $payment = FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '100', 'payment_method' => 'Cash']);
    $submissionId = FinanceService::submitPayment($studentA, (int) $assessment['id'], [
        'amount' => '50', 'method' => 'GCash', 'reference_number' => 'GC-CRAWL', 'paid_on' => date('Y-m-d'),
    ], UploadedFile::forTesting(fixtureFile('png'), 'proof.png'));
    FinanceService::adjust($cashier, (int) $assessment['id'], ['type' => 'DISCOUNT', 'amount' => '10', 'reason' => 'Crawl adjustment']);

    // The crawl runs against the real database, so nothing it plants may reach a real account.
    // Records are created through the services with plain text, so their audit entries hold no
    // payload (the audit log can never be edited); the payloads are written into the tables
    // afterwards, as fixture setup. The public announcement is not urgent (no banner on anyone's
    // portal), and the notifications it sends to accounts other than the fixtures are removed at
    // once. The urgent banner is still checked through the class announcement, which only the
    // fixture class receives.
    $announcementId = AnnouncementService::save($registrar, null, [
        'title' => 'Crawl notice', 'content' => 'Body text for the crawl.', 'category' => 'General',
        'audience' => 'EVERYONE', 'is_public' => '1', 'is_urgent' => '0', 'image_alt' => 'Poster',
    ], UploadedFile::forTesting(fixtureFile('png'), 'poster.png'), 'publish');
    Database::query(
        'DELETE FROM notifications WHERE link_url = ? AND user_id NOT IN (SELECT id FROM (SELECT id FROM users WHERE username LIKE ?) AS fixtures)',
        ['/portal/announcements/' . $announcementId, testPrefixPattern()]
    );
    $classAnnouncementId = AnnouncementService::save($teacher, null, [
        'title' => 'Class notice', 'content' => 'Room change for the crawl class.', 'category' => 'Academic',
        'audience' => 'CLASSES', 'classes' => [$classId], 'is_urgent' => '1',
    ], null, 'publish');

    $link = PasswordResetService::issue($spare, PasswordResetService::RESET, $admin);

    // A profile photo for studentA, so /student/photo and the registrar's photo route return 200
    // (studentB has none, checked as a 404 below). deleteTestUsers() removes the file.
    $photoPath = 'uploads/profile-photos/' . bin2hex(random_bytes(16)) . '.png';
    if (!is_dir(STORAGE_PATH . '/uploads/profile-photos')) {
        mkdir(STORAGE_PATH . '/uploads/profile-photos', 0750, true);
    }
    copy(fixtureFile('png'), STORAGE_PATH . '/' . $photoPath);
    Database::update('student_profiles', ['photo_path' => $photoPath], ['student_id' => $enrolled['student_id']]);

    // A document request, so the student and registrar credential pages have a row to render.
    CredentialRequestService::request($studentA, ['type' => 'TRANSCRIPT', 'purpose' => 'For the crawl', 'copies' => 1]);

    // Script-injection payloads in names, catalog text, reasons, announcements, and the fixtures'
    // notifications (fixture setup, straight to the tables).
    Database::update('users', ['first_name' => XSS_NAME, 'last_name' => XSS_ATTR], ['id' => $studentA->id]);
    Database::update('rooms', ['name' => XSS_NAME], ['id' => $roomId]);
    Database::update('subjects', ['title' => XSS_NAME], ['id' => $subjectId]);
    Database::query('UPDATE financial_adjustments SET reason = ? WHERE fee_assessment_id = ?', ['Crawl ' . XSS_ATTR, (int) $assessment['id']]);
    Database::update('announcements', ['title' => 'Crawl notice ' . XSS_NAME, 'content' => "Body text for the crawl.\n\n" . XSS_ATTR, 'image_alt' => 'Poster ' . XSS_ATTR], ['id' => $announcementId]);
    Database::update('announcements', ['title' => 'Class notice ' . XSS_NAME, 'content' => "Room change for the crawl class.\n\n" . XSS_ATTR], ['id' => $classAnnouncementId]);
    Database::query('UPDATE notifications SET title = CONCAT(title, ?) WHERE link_url IN (?, ?)',
        [' ' . XSS_NAME, '/portal/announcements/' . $announcementId, '/portal/announcements/' . $classAnnouncementId]);
    $announcement = App\Models\Announcement::find($announcementId);

    $ids = [
        'application'  => $applicationId,
        'student'      => $enrolled['student_id'],
        'file'         => (int) Database::scalar('SELECT f.id FROM enrollment_requirement_files f JOIN enrollment_requirements er ON er.id = f.enrollment_requirement_id WHERE er.application_id = ? LIMIT 1', [$applicationId]),
        'requirement'  => (int) Database::scalar('SELECT id FROM enrollment_requirements WHERE application_id = ? LIMIT 1', [$applicationId]),
        'assessment'   => (int) $assessment['id'],
        'payment'      => $payment['payment_id'],
        'submission'   => $submissionId,
        'announcement' => $announcementId,
        'class_announcement' => $classAnnouncementId,
        'class'        => $classId,
        'section'      => $enrolled['section_id'],
        'room'         => $roomId,
        'subject'      => $subjectId,
        'program'      => (int) Database::scalar('SELECT program_id FROM enrollment_applications WHERE id = ?', [$applicationId]),
        'curriculum'   => (int) Database::scalar('SELECT id FROM curricula ORDER BY id LIMIT 1'),
        'fee'          => (int) Database::scalar('SELECT id FROM fee_types ORDER BY id LIMIT 1'),
        'audit'        => (int) Database::scalar('SELECT MAX(id) FROM audit_logs'),
        'notification' => (int) Database::scalar('SELECT id FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$studentA->id]),
        'period'       => (int) Database::scalar('SELECT id FROM grade_periods ORDER BY id LIMIT 1'),
        'term'         => (int) Database::scalar('SELECT term_id FROM enrollment_applications WHERE id = ?', [$applicationId]),
        'enlistment'   => (int) Database::scalar('SELECT id FROM class_enlistments WHERE enrollment_id = ? LIMIT 1', [$enrolled['enrollment_id']]),
    ];

    // A path for a route pattern, with each placeholder filled by the right fixture.
    $pathFor = static function (string $pattern) use ($ids, $announcement, $link, $studentA): string {
        $id = match (true) {
            str_starts_with($pattern, '/admin/users/')            => $studentA->id,
            str_starts_with($pattern, '/admin/audit-logs/')       => $ids['audit'],
            str_starts_with($pattern, '/admin/roles/')            => 2,
            str_starts_with($pattern, '/admin/backups/')          => PHP_INT_MAX,
            str_starts_with($pattern, '/student/enrollment/')     => $ids['application'],
            str_starts_with($pattern, '/student/account/')        => $ids['assessment'],
            str_starts_with($pattern, '/cashier/payments/')       => $ids['submission'],
            str_starts_with($pattern, '/cashier/accounts/')       => $ids['assessment'],
            str_starts_with($pattern, '/cashier/fees/')           => $ids['fee'],
            str_starts_with($pattern, '/receipts/')               => $ids['payment'],
            str_starts_with($pattern, '/statements/')             => $ids['assessment'],
            str_starts_with($pattern, '/payment-proofs/')         => $ids['submission'],
            str_starts_with($pattern, '/portal/notifications/')   => $ids['notification'],
            str_starts_with($pattern, '/teacher/announcements/')  => $ids['class_announcement'],
            str_contains($pattern, 'announcement')                => $ids['announcement'],
            str_starts_with($pattern, '/teacher/classes/')        => $ids['class'],
            str_starts_with($pattern, '/registrar/applications/') => $ids['application'],
            str_starts_with($pattern, '/registrar/students/')     => $ids['student'],
            str_starts_with($pattern, '/registrar/programs/')     => $ids['program'],
            str_starts_with($pattern, '/registrar/curricula/')    => $ids['curriculum'],
            str_starts_with($pattern, '/registrar/subjects/')     => $ids['subject'],
            str_starts_with($pattern, '/registrar/sections/')     => $ids['section'],
            str_starts_with($pattern, '/registrar/classes/')      => $ids['class'],
            str_starts_with($pattern, '/registrar/rooms/')        => $ids['room'],
            str_starts_with($pattern, '/registrar/enlistments/')  => $ids['enlistment'],
            str_starts_with($pattern, '/registrar/grading/periods/') => $ids['period'],
            str_starts_with($pattern, '/registrar/grading/terms/') => $ids['term'],
            str_starts_with($pattern, '/documents/')              => $ids['file'],
            default                                               => 999999,
        };
        return strtr($pattern, [
            '{id}' => (string) $id, '{fileId}' => (string) $ids['file'], '{requirementId}' => (string) $ids['requirement'],
            '{payment}' => (string) $ids['payment'], '{group}' => 'school', '{slug}' => (string) $announcement['slug'], '{token}' => $link['token'],
        ]);
    };

    $sessions = [
        'visitor'   => null,
        'studentA'  => $studentA,
        'studentB'  => $studentB,
        'teacher'   => $teacher,
        'registrar' => $registrar,
        'cashier'   => $cashier,
        'admin'     => $admin,
    ];
    $report['accounts'] = array_map(static fn (?User $u) => $u?->username, $sessions);
    echo "  Signing in as each role…\n";
    foreach ($sessions as $name => $user) {
        $jars[$name] = tempnam(sys_get_temp_dir(), 'shcrawl');
        if ($user !== null) {
            signIn($base, $jars[$name], $user->username, 'TestPass123');
        }
    }

    // Statuses a document route answers, which its controller decides (not route middleware).
    $documentRules = [
        '/receipts/'       => ['studentA' => 200, 'studentB' => 404, 'teacher' => 403, 'registrar' => 200, 'cashier' => 200, 'admin' => 403],
        '/statements/'     => ['studentA' => 200, 'studentB' => 404, 'teacher' => 403, 'registrar' => 200, 'cashier' => 200, 'admin' => 403],
        '/payment-proofs/' => ['studentA' => 200, 'studentB' => 404, 'teacher' => 403, 'registrar' => 403, 'cashier' => 200, 'admin' => 403],
        '/documents/'      => ['studentA' => 200, 'studentB' => 404, 'teacher' => 403, 'registrar' => 200, 'cashier' => 403, 'admin' => 200],
    ];
    // Allowed pages that legitimately redirect or are empty for these fixtures.
    $allowedAlso = [
        '/cashier/reports'                     => [302],
        '/registrar/reports'                   => [302],
        '/admin/reports'                       => [302],
        '/student/enrollment/apply'            => [302],
        '/registrar/applications/{id}/confirm' => [302],
        '/downloads/{id}'                      => [404],
        '/branding/logo'                       => [302],
        // A student with no application is sent to the application form.
        '/student/requirements'                => [302],
        '/student/status'                      => [302],
    ];

    $router = new Router();
    require BASE_PATH . '/routes/web.php';
    $routes = $router->routes();

    // -- 1-3. Every GET route, for every session --------------------------------------------
    echo "  Crawling " . count(array_filter($routes, static fn ($r) => $r['method'] === 'GET')) . " GET routes × " . count($sessions) . " sessions…\n";
    foreach ($routes as $route) {
        if ($route['method'] !== 'GET' || str_ends_with($route['pattern'], '/export') || $route['pattern'] === '/student/schedule/print') {
            if ($route['method'] === 'GET' && (str_ends_with($route['pattern'], '/export') || $route['pattern'] === '/student/schedule/print')) {
                // Exports and the schedule printout are checked once by their owner below.
            }
            if ($route['method'] !== 'GET') {
                continue;
            }
        }
        $path = $pathFor($route['pattern']);
        foreach ($sessions as $name => $user) {
            $middleware = $route['middleware'];
            $expected = [200];
            if (in_array('guest', $middleware, true)) {
                $expected = $user === null ? [200] : [302];
            } elseif ($user === null && in_array('auth', $middleware, true)) {
                $expected = [302];
            } else {
                foreach ($middleware as $gate) {
                    if (str_starts_with($gate, 'role:') && $user !== null && $user->role_name !== substr($gate, 5)) {
                        $expected = [403];
                        break;
                    }
                    if (str_starts_with($gate, 'can:') && $user !== null && !$user->hasPermission(substr($gate, 4))) {
                        $expected = [403];
                        break;
                    }
                }
                foreach ($documentRules as $prefix => $rule) {
                    if (str_starts_with($route['pattern'], $prefix) && $user !== null) {
                        $expected = [$rule[$name]];
                    }
                }
                if ($expected === [200] && $name === 'studentB' && str_starts_with($route['pattern'], '/student/') && str_contains($route['pattern'], '{')) {
                    $expected = [404]; // another student's record does not exist for them
                }
                if ($expected === [200] && $route['pattern'] === '/student/schedule/print' && $name === 'studentB') {
                    $expected = [404];
                }
                if ($expected === [200] && $route['pattern'] === '/student/photo' && $name === 'studentB') {
                    $expected = [404]; // studentB has uploaded no photo
                }
                if ($expected === [200] && $route['pattern'] === '/student/cor' && $name === 'studentB') {
                    $expected = [404]; // studentB is not enrolled, so has no certificate of registration
                }
                if ($expected === [200] && $route['pattern'] === '/account/photo' && $name !== 'studentA') {
                    $expected = [404]; // only studentA has a photo; other roles have no avatar yet
                }
                if ($expected !== [403] && isset($allowedAlso[$route['pattern']])) {
                    $expected = array_merge($expected, $allowedAlso[$route['pattern']]);
                }
            }

            $response = http('GET', $base . $path, $jars[$name]);
            $checks++;
            $report['pages'][] = ['session' => $name, 'path' => $path, 'status' => $response['status'], 'ms' => $response['ms'],
                'queries' => (int) ($response['headers']['x-debug-queries'][0] ?? 0)];

            if ($response['status'] >= 500 || $response['status'] === 0) {
                fail("{$name} GET {$path}: server error {$response['status']}");
                continue;
            }
            if (!in_array($response['status'], $expected, true)) {
                fail("{$name} GET {$path}: expected " . implode('/', $expected) . ", got {$response['status']}" . ($response['location'] ? " → {$response['location']}" : ''));
            }
            if ($response['status'] === 302 && $user === null && in_array('auth', $middleware, true) && !str_ends_with($response['location'], '/login')) {
                fail("visitor GET {$path}: redirected to {$response['location']}, not the sign-in page");
            }
            foreach (['content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy'] as $header) {
                if (!isset($response['headers'][$header]) && $response['status'] !== 302 && str_contains((string) ($response['headers']['content-type'][0] ?? ''), 'text/html')) {
                    fail("{$name} GET {$path}: missing {$header}");
                }
            }
            foreach (XSS_MARKERS as $marker) {
                if (str_contains($response['body'], $marker)) {
                    fail("{$name} GET {$path}: unescaped script payload in the page");
                }
            }
            // System Health shows the PHP error log on purpose; every other page must not contain error text.
            if ($route['pattern'] !== '/admin/health' && preg_match('/SQLSTATE|Fatal error|Warning: |Notice: |Deprecated: |Stack trace/', $response['body'])) {
                fail("{$name} GET {$path}: error text in the page");
            }
            if ($response['status'] === 200 && str_contains((string) ($response['headers']['content-type'][0] ?? ''), 'text/html')) {
                foreach (accessibilityProblems($response['body']) as $problem) {
                    fail("{$name} GET {$path}: accessibility: {$problem}");
                }
            }
        }
    }

    // Exports and printouts, for their owners.
    foreach ([['registrar', '/statements/' . $ids['assessment'], 'text/html'], ['cashier', '/cashier/reports/collections/export', 'text/csv'],
              ['cashier', '/cashier/reports/outstanding/export', 'text/csv'], ['admin', '/admin/audit-logs/export?action=login', 'text/csv'],
              ['studentA', '/student/schedule/print', 'text/html'],
              ['admin', '/admin/reports/accounts/export', 'text/csv'], ['admin', '/admin/reports/sign-ins/export', 'text/csv'],
              ['admin', '/admin/reports/activity/export', 'text/csv'], ['admin', '/admin/reports/usage/export', 'text/csv'],
              ['registrar', '/registrar/reports/enrollment/export', 'text/csv'], ['registrar', '/registrar/reports/applications/export', 'text/csv'],
              ['registrar', '/registrar/reports/requirements/export', 'text/csv'], ['registrar', '/registrar/reports/history/export', 'text/csv'],
              ['teacher', '/teacher/classes/' . $ids['class'] . '/reports/class-list/export', 'text/csv'],
              ['teacher', '/teacher/classes/' . $ids['class'] . '/reports/attendance/export', 'text/csv'],
              ['teacher', '/teacher/classes/' . $ids['class'] . '/reports/grades/export', 'text/csv']] as [$name, $path, $type]) {
        $response = http('GET', $base . $path, $jars[$name]);
        $checks++;
        if ($response['status'] !== 200 || !str_contains((string) ($response['headers']['content-type'][0] ?? ''), $type)) {
            fail("{$name} GET {$path}: expected 200 {$type}, got {$response['status']} " . ($response['headers']['content-type'][0] ?? ''));
        }
    }

    // Document preview (docs/10, docs/15): a file shown inline and a page embedded in the
    // preview window may be framed by this site only; everything else is never framed, a
    // download stays a download, and another student's file is still a 404.
    $previews = [
        ['studentA', '/documents/' . $ids['file'] . '?inline=1', 200, 'inline', 'SAMEORIGIN', "default-src 'none'"],
        ['studentA', '/documents/' . $ids['file'], 200, 'attachment', 'DENY', null],
        ['registrar', '/documents/' . $ids['file'] . '?inline=1', 200, 'inline', 'SAMEORIGIN', "default-src 'none'"],
        ['studentB', '/documents/' . $ids['file'] . '?inline=1', 404, null, null, null],
        ['cashier', '/payment-proofs/' . $ids['submission'] . '?inline=1', 200, 'inline', 'SAMEORIGIN', "default-src 'none'"],
        ['studentA', '/receipts/' . $ids['payment'] . '?embed=1', 200, null, 'SAMEORIGIN', "frame-ancestors 'self'"],
        ['studentA', '/receipts/' . $ids['payment'], 200, null, 'DENY', "frame-ancestors 'none'"],
        ['studentA', '/student/documents?embed=1', 200, null, 'DENY', "frame-ancestors 'none'"],
    ];
    foreach ($previews as [$name, $path, $status, $disposition, $frame, $policy]) {
        $response = http('GET', $base . $path, $jars[$name]);
        $checks++;
        $got = [
            $response['status'],
            $disposition === null ? null : strtok((string) ($response['headers']['content-disposition'][0] ?? ''), ';'),
            $frame === null ? null : (string) ($response['headers']['x-frame-options'][0] ?? ''),
        ];
        $csp = (string) ($response['headers']['content-security-policy'][0] ?? '');
        if ($got !== [$status, $disposition, $frame] || ($policy !== null && !str_contains($csp, $policy))) {
            fail("{$name} GET {$path}: expected {$status} " . ($disposition ?? '-') . ' ' . ($frame ?? '-') . ' ' . ($policy ?? '') . ', got ' . implode(' ', array_map('strval', $got)) . " {$csp}");
        }
    }

    // Session cookie flags.
    $login = http('GET', $base . '/login', null);
    $cookie = implode(' ', $login['headers']['set-cookie'] ?? []);
    $checks++;
    if (!str_contains($cookie, 'HttpOnly') || !str_contains($cookie, 'SameSite=Lax')) {
        fail("The session cookie lacks HttpOnly or SameSite: {$cookie}");
    }

    // -- 4. Injection payloads in every filter parameter -----------------------------------
    echo "  Sending injection payloads to every page's filters…\n";
    $query = http_build_query(array_fill_keys(FILTER_PARAMS, INJECTION));
    // A spreadsheet export cannot run a script, and exports are audited with their filters: they get
    // the SQL part only, so no script text is written into the (unchangeable) audit log.
    $sqlOnlyQuery = http_build_query(array_fill_keys(FILTER_PARAMS, "' OR '1'='1' -- "));
    foreach ($routes as $route) {
        if ($route['method'] !== 'GET' || str_contains($route['pattern'], '{')) {
            continue;
        }
        foreach (['visitor', 'studentA', 'teacher', 'registrar', 'cashier', 'admin'] as $name) {
            $response = http('GET', $base . $route['pattern'] . '?' . (str_ends_with($route['pattern'], '/export') ? $sqlOnlyQuery : $query), $jars[$name]);
            $checks++;
            if ($response['status'] >= 500) {
                fail("{$name} GET {$route['pattern']} with injected filters: server error {$response['status']}");
            }
            if ($route['pattern'] !== '/admin/health' && preg_match('/SQLSTATE|syntax to use near|Fatal error/', $response['body'])) {
                fail("{$name} GET {$route['pattern']} with injected filters: database error text");
            }
            foreach (XSS_MARKERS as $marker) {
                if (str_contains($response['body'], $marker)) {
                    fail("{$name} GET {$route['pattern']} with injected filters: reflected script payload");
                }
            }
        }
    }

    // -- 5. Every POST route without a CSRF token ------------------------------------------
    echo "  Posting to every state-changing route without a CSRF token…\n";
    $auditBefore = (int) Database::scalar('SELECT COUNT(*) FROM audit_logs');
    $posts = 0;
    foreach ($routes as $route) {
        if ($route['method'] === 'GET') {
            continue;
        }
        foreach (['visitor', 'admin', 'registrar', 'studentA'] as $name) {
            $response = http('POST', $base . $pathFor($route['pattern']), $jars[$name], ['reason' => 'csrf probe', 'amount' => '1']);
            $checks++;
            $posts++;
            if ($response['status'] !== 403) {
                fail("{$name} POST {$route['pattern']} without a token: expected 403, got {$response['status']}");
            }
        }
    }
    $checks++;
    if ((int) Database::scalar('SELECT COUNT(*) FROM audit_logs') !== $auditBefore) {
        fail('A POST without a CSRF token changed something (new audit entries were written).');
    }

    // -- 6. Files that must never be served ------------------------------------------------
    echo "  Probing files that must never be served…\n";
    foreach (['/.git/HEAD', '/config/config.local.php', '/config/config.php', '/storage/logs/php-error.log', '/storage/backups/',
              '/docs/00_MASTER_SPECIFICATION.md', '/tests/run.php', '/database/backup.php', '/bootstrap.php', '/CLAUDE.md', '/tailwindcss.exe',
              '/routes/web.php', '/app/Core/Database.php', '/public/assets/', '/public/../config/config.php'] as $path) {
        $response = http('GET', $projectRoot . $path, null);
        $checks++;
        if (!in_array($response['status'], [403, 404], true) || str_contains($response['body'], "'password'") || str_contains($response['body'], 'ref: refs/')) {
            fail("GET {$path} is reachable (status {$response['status']})");
        }
    }

    $report['failures'] = $failures;
    $report['checks'] = $checks;
    echo "\n  {$checks} checks, " . count($failures) . " failure(s). {$posts} CSRF probes, " . count($report['pages']) . " page requests.\n";
    $slow = $report['pages'];
    usort($slow, static fn ($a, $b) => $b['ms'] <=> $a['ms']);
    echo "  Slowest pages:\n";
    foreach (array_slice($slow, 0, 5) as $page) {
        printf("    %5d ms  %3d queries  %-9s %s\n", $page['ms'], $page['queries'], $page['session'], $page['path']);
    }
} catch (Throwable $e) {
    fail('Crawl aborted: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
} finally {
    foreach ($jars as $jar) {
        @unlink($jar);
    }
    actingAs(null);
    if (isset($options['report'])) {
        file_put_contents((string) $options['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    if (isset($options['keep'])) {
        // Kept fixtures stay in the real database while someone looks around in a browser. The
        // injection checks are done, so the planted script text becomes plain names, and the public
        // announcement is archived: real accounts and the public website never show crawl content.
        if (isset($ids['room'], $studentA)) {
            Database::update('users', ['first_name' => 'Crawl', 'last_name' => 'Student'], ['id' => $studentA->id]);
            Database::update('rooms', ['name' => 'Crawl room'], ['id' => $ids['room']]);
            Database::update('subjects', ['title' => 'Crawl subject'], ['id' => $ids['subject']]);
            Database::update('announcements', ['title' => 'Crawl notice', 'content' => 'Body text for the crawl.', 'image_alt' => 'Poster', 'status' => 'ARCHIVED'], ['id' => $ids['announcement']]);
            Database::update('announcements', ['title' => 'Class notice', 'content' => 'Room change for the crawl class.'], ['id' => $ids['class_announcement']]);
            Database::query("UPDATE financial_adjustments SET reason = 'Crawl adjustment' WHERE fee_assessment_id = ?", [$ids['assessment']]);
            Database::query("UPDATE notifications SET title = 'Crawl notification', body = NULL WHERE title LIKE ? OR body LIKE ?", ['%sh-xss%', '%sh-xss%']);
        }
        echo "  Fixtures kept (password TestPass123), with the script payloads replaced by plain text.\n";
        echo "  Remove them with: php tests/http/crawl.php --cleanup\n\n";
    } else {
        deleteTestLoginLogs();
        deleteTestUsers();
        echo "  Fixtures removed.\n\n";
    }
}

exit($failures === [] ? 0 : 1);
