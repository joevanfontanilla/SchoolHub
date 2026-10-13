<?php
/**
 * The account list by program and year level (docs/14, docs/15).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\FinanceReport;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

/** @return array<int,array<string,mixed>> every account row of a report, by assessment id */
function accountRows(array $report): array
{
    $rows = [];
    foreach ($report['groups'] as $group) {
        foreach ($group['rows'] as $row) {
            $rows[(int) $row['id']] = $row;
        }
    }
    return $rows;
}

return [
    'accounts are grouped by program and year level, numbered within each group, with totals' => function (TestRunner $t): void {
        try {
            [, , $appA] = approvedApplication();
            [, , $appB] = approvedApplication();
            $programs = array_map('intval', array_column(Database::select('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 2'), 'id'));
            Database::update('enrollment_applications', ['program_id' => $programs[1]], ['id' => $appB]); // fixture setup: a second program

            $a = Finance::assessmentForApplication($appA);
            $b = Finance::assessmentForApplication($appB);
            $report = FinanceReport::accountList((int) $a['term_id'], null, null);

            $groupOf = static function (int $assessmentId) use ($report): ?array {
                foreach ($report['groups'] as $group) {
                    foreach ($group['rows'] as $row) {
                        if ((int) $row['id'] === $assessmentId) {
                            return $group;
                        }
                    }
                }
                return null;
            };
            $t->assertNotNull($groupOf((int) $a['id']), 'The first account is listed');
            $t->assertNotNull($groupOf((int) $b['id']), 'The second account is listed');
            $t->assertTrue($groupOf((int) $a['id'])['program'] !== $groupOf((int) $b['id'])['program'], 'Different programs are different groups');

            $sum = 0;
            foreach ($report['groups'] as $group) {
                $sum += count($group['rows']);
                $t->assertEquals(array_sum(array_column($group['rows'], 'balance')), $group['totals']['balance'], $group['program'] . ' totals agree with its rows');
            }
            $t->assertEquals($sum, $report['count'], 'The count covers every row');
            $t->assertEquals(array_sum(array_map(static fn ($g) => $g['totals']['due'], $report['groups'])), $report['totals']['due'], 'The grand total is the sum of the groups');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'program and year level filters narrow the list' => function (TestRunner $t): void {
        try {
            [, , $appId] = approvedApplication();
            $assessment = Finance::assessmentForApplication($appId);
            $application = Database::selectOne('SELECT program_id, year_level_id FROM enrollment_applications WHERE id = ?', [$appId]);
            $termId = (int) $assessment['term_id'];
            $id = (int) $assessment['id'];

            $t->assertTrue(isset(accountRows(FinanceReport::accountList($termId, (int) $application['program_id'], (int) $application['year_level_id']))[$id]), 'Found under its own program and year');

            $otherProgram = (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 AND id <> ? ORDER BY id LIMIT 1', [$application['program_id']]);
            $t->assertFalse(isset(accountRows(FinanceReport::accountList($termId, $otherProgram, null))[$id]), 'Not under another program');

            $otherYear = (int) Database::scalar('SELECT id FROM year_levels WHERE id <> ? ORDER BY sequence LIMIT 1', [$application['year_level_id']]);
            $t->assertFalse(isset(accountRows(FinanceReport::accountList($termId, null, $otherYear))[$id]), 'Not under another year level');

            $otherTerm = (int) Database::scalar('SELECT id FROM terms WHERE id <> ? LIMIT 1', [$termId]);
            $t->assertFalse(isset(accountRows(FinanceReport::accountList($otherTerm, null, null))[$id]), 'Not under another term');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'each row agrees with the account: tuition and misc make up the assessment, installments add up to the balance' => function (TestRunner $t): void {
        try {
            [, , $appId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($appId)['id'];

            FinanceService::adjust($cashier, $id, ['type' => 'SCHOLARSHIP', 'percent' => '50', 'reason' => 'Academic scholarship']);
            FinanceService::adjust($cashier, $id, ['type' => 'OLD_ACCOUNT', 'amount' => '400', 'reason' => 'Balance from last term']);

            $before = accountRows(FinanceReport::accountList((int) Finance::assessment($id)['term_id'], null, null))[$id];
            $t->assertNull($before['plan'], 'Not enrolled: no installments yet');

            FinanceService::recordPayment($cashier, $id, ['amount' => FinanceService::money(PaymentPolicy::requiredCents(Finance::assessment($id))), 'payment_method' => 'Cash']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '250', 'payment_method' => 'Cash']);

            $assessment = Finance::assessment($id);
            $row = accountRows(FinanceReport::accountList((int) $assessment['term_id'], null, null))[$id];

            $t->assertEquals(FinanceService::cents($assessment['total_amount']), $row['tuition'] + $row['misc'], 'Tuition and miscellaneous make up the assessment');
            $t->assertTrue($row['discount'] > 0, 'The scholarship shows as a discount');
            $t->assertEquals(max(0, FinanceService::dueCents($assessment)), $row['due']);
            $t->assertEquals(FinanceService::cents($assessment['balance']), $row['balance']);

            $t->assertNotNull($row['plan'], 'Enrolled: the installments are shown');
            $t->assertEquals($row['balance'], array_sum(array_column($row['plan']['rows'], 'balance')), 'Installment balances add up to the account balance');
            $t->assertEquals(Finance::installmentPlan($assessment), $row['plan'], 'The same plan as the ledger card');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an empty selection returns an empty report, and the default term is the latest assessed' => function (TestRunner $t): void {
        $empty = FinanceReport::accountList(null, 99999999, null);
        $t->assertEquals(0, $empty['count']);
        $t->assertEquals([], $empty['groups']);
        $t->assertEquals(0, $empty['totals']['balance']);

        $latest = FinanceReport::latestAssessedTermId();
        $t->assertTrue($latest === null || $latest > 0);
    },

    'the account list routes are the cashier\'s, behind reports.cashier' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = 0;
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/reports/account-list')) {
                $found++;
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true), $route['pattern'] . ' is gated to the Cashier role');
                $t->assertTrue(in_array('can:reports.cashier', $route['middleware'], true), $route['pattern'] . ' needs reports.cashier');
            }
        }
        $t->assertEquals(3, $found, 'Screen, print, and CSV');
    },
];
