<?php
/**
 * Fee lines by program and year level, percentage discounts, and the ledger card (docs/14).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\User;
use App\Services\FeeScheduleService;
use App\Services\FinanceService;

/** Remove the fixture fee lines (their codes start with ZZT). */
function removeFixtureFees(): void
{
    Database::query('DELETE FROM fee_types WHERE code LIKE ?', [TEST_CODE . '%']);
}

function fixtureFee(string $code, string $key, string $amount, ?int $programId, ?int $yearLevelId, string $basis = 'PER_UNIT'): void
{
    Database::insert('fee_types', [
        'code' => TEST_CODE . $code, 'fee_key' => $key, 'name' => 'Fixture ' . $code, 'basis' => $basis, 'amount' => $amount,
        'program_id' => $programId, 'year_level_id' => $yearLevelId, 'sort_order' => 5, 'is_active' => 1,
    ]);
}

/** The per-unit tuition rate charged for a program and year level, as shown on the assessment. */
function tuitionRate(?int $programId, ?int $yearLevelId): string
{
    $fees = FinanceService::computeFees([['units' => 1, 'lab_units' => 0]], $programId, $yearLevelId);
    foreach ($fees['items'] as $item) {
        if ($item['basis'] === 'PER_UNIT') {
            return (string) $item['unit_amount'];
        }
    }
    return 'none';
}

return [
    'for each fee, the most specific applicable line wins: program and year, then program, then year, then general' => function (TestRunner $t): void {
        try {
            $programs = array_map('intval', array_column(Database::select('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 2'), 'id'));
            $years = array_map('intval', array_column(Database::select('SELECT id FROM year_levels ORDER BY sequence LIMIT 2'), 'id'));
            [$p1, $p2] = $programs;
            [$y1, $y2] = $years;

            $general = tuitionRate($p1, $y1);
            $t->assertTrue($general !== 'none', 'The schedule has a general tuition line');

            fixtureFee('YEAR', 'TUITION', '111.11', null, $y1);
            $t->assertEquals('111.11', tuitionRate($p1, $y1), 'A year-level line beats the general one');
            $t->assertEquals($general, tuitionRate($p1, $y2), 'Another year keeps the general rate');

            fixtureFee('PROG', 'TUITION', '222.22', $p1, null);
            $t->assertEquals('222.22', tuitionRate($p1, $y1), 'A program line beats a year line');
            $t->assertEquals('222.22', tuitionRate($p1, $y2), 'And applies to every year of that program');
            $t->assertEquals($general, tuitionRate($p2, $y2), 'Another program and year keeps the general rate');
            $t->assertEquals('111.11', tuitionRate($p2, $y1), 'Another program in the scoped year gets the year line');

            fixtureFee('BOTH', 'TUITION', '333.33', $p1, $y1);
            $t->assertEquals('333.33', tuitionRate($p1, $y1), 'Program and year together are the most specific');
            $t->assertEquals('222.22', tuitionRate($p1, $y2));
        } finally {
            removeFixtureFees();
        }
    },

    'a scoped line that replaces nothing is an extra charge for that program only' => function (TestRunner $t): void {
        try {
            [$p1, $p2] = array_map('intval', array_column(Database::select('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 2'), 'id'));
            fixtureFee('NSTP', TEST_CODE . 'NSTP', '250.00', $p1, null, 'FIXED');

            $has = static fn (int $p) => array_filter(
                FinanceService::computeFees([['units' => 3, 'lab_units' => 0]], $p, null)['items'],
                static fn ($i) => $i['description'] === 'Fixture NSTP'
            ) !== [];
            $t->assertTrue($has($p1), 'Charged to the program it is scoped to');
            $t->assertFalse($has($p2), 'Not charged to others');
        } finally {
            removeFixtureFees();
        }
    },

    'the fee schedule refuses a duplicate scope, an unknown program, and a replace without a scope' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $p1 = (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 1');
            $base = ['name' => 'Fixture', 'basis' => 'PER_UNIT', 'amount' => '100.00', 'sort_order' => '5', 'is_active' => '1'];

            FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'A', 'program_id' => (string) $p1, 'replaces' => 'TUITION']);
            $row = Database::selectOne('SELECT * FROM fee_types WHERE code = ?', [TEST_CODE . 'A']);
            $t->assertEquals('TUITION', $row['fee_key'], 'It replaces the general tuition line');
            $t->assertEquals($p1, (int) $row['program_id']);

            $errors = fieldErrors(fn () => FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'B', 'program_id' => (string) $p1, 'replaces' => 'TUITION']));
            $t->assertTrue(isset($errors['replaces']), 'A second line for the same fee, program, and year is refused');

            $errors = fieldErrors(fn () => FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'C', 'program_id' => '99999999']));
            $t->assertTrue(isset($errors['program_id']), 'An unknown program is refused');

            $errors = fieldErrors(fn () => FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'D', 'replaces' => 'TUITION']));
            $t->assertTrue(isset($errors['replaces']), 'A line for everyone cannot replace another');

            $t->assertEquals(403, statusOf(fn () => FeeScheduleService::save(makeUser(User::ROLE_REGISTRAR), null, $base + ['code' => TEST_CODE . 'E'])));
        } finally {
            removeFixtureFees();
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a percentage discount is that share of the tuition only, rounded to the centavo, and says so in its reason' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = Finance::assessmentForApplication($applicationId);
            $assessmentId = (int) $assessment['id'];

            $tuition = FinanceService::cents((string) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM fee_items WHERE fee_assessment_id = ? AND basis = 'PER_UNIT'", [$assessmentId]));
            $t->assertTrue($tuition > 0, 'The assessment has tuition');

            $id = FinanceService::adjust($cashier, $assessmentId, ['type' => 'SCHOLARSHIP', 'percent' => '50', 'reason' => 'Academic scholarship, SO 12']);
            $adjustment = Database::selectOne('SELECT * FROM financial_adjustments WHERE id = ?', [$id]);
            $t->assertEquals(-intdiv($tuition * 50 + 50, 100), FinanceService::signedCents($adjustment['amount']), 'Half the tuition, as a credit');
            $t->assertTrue(str_starts_with($adjustment['reason'], '50% of tuition. '), 'The reason carries the percentage');
            $t->assertContains('Academic scholarship', $adjustment['reason']);

            $after = Finance::assessmentForApplication($applicationId);
            $t->assertEquals(FinanceService::cents($assessment['total_amount']) + FinanceService::signedCents($adjustment['amount']), FinanceService::dueCents($after), 'The amount due fell by that share');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a percentage must be one of the two ways to say it, for a discount or scholarship, between 0 and 100' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessmentId = (int) Finance::assessmentForApplication($applicationId)['id'];
            $reason = 'Scholarship grant for the term';

            $both = fieldErrors(fn () => FinanceService::adjust($cashier, $assessmentId, ['type' => 'DISCOUNT', 'percent' => '10', 'amount' => '100', 'reason' => $reason]));
            $t->assertTrue(isset($both['percent']), 'A percentage and a peso amount together are refused');

            $charge = fieldErrors(fn () => FinanceService::adjust($cashier, $assessmentId, ['type' => 'ADDITIONAL_CHARGE', 'percent' => '10', 'reason' => $reason]));
            $t->assertTrue(isset($charge['percent']), 'A charge cannot be a percentage');

            foreach (['0', '100.01', '-5', 'abc', '12.345'] as $bad) {
                $errors = fieldErrors(fn () => FinanceService::adjust($cashier, $assessmentId, ['type' => 'DISCOUNT', 'percent' => $bad, 'reason' => $reason]));
                $t->assertTrue(isset($errors['percent']), "{$bad}% is refused");
            }

            $none = fieldErrors(fn () => FinanceService::adjust($cashier, $assessmentId, ['type' => 'DISCOUNT', 'reason' => $reason]));
            $t->assertTrue(isset($none['amount']), 'Neither an amount nor a percentage is refused');

            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM financial_adjustments WHERE fee_assessment_id = ?', [$assessmentId]), 'Nothing was recorded');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the ledger runs a balance down the rows and ends at the account balance' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];

            FinanceService::adjust($cashier, $id, ['type' => 'DISCOUNT', 'percent' => '25', 'reason' => 'Early enrollment discount']);
            $first = FinanceService::recordPayment($cashier, $id, ['amount' => '1000', 'payment_method' => 'Cash']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '200', 'payment_method' => 'GCash', 'reference_number' => 'REF-1']);
            FinanceService::reversePayment($cashier, $first['payment_id'], 'Recorded on the wrong account');
            FinanceService::adjust($cashier, $id, ['type' => 'ADDITIONAL_CHARGE', 'amount' => '75', 'reason' => 'Bridging course fee']);

            $assessment = Finance::assessment($id);
            $ledger = FinanceService::ledger($assessment, Finance::items($id), Finance::payments($id), Finance::adjustments($id), App\Services\SubjectSelectionService::chosen($applicationId));

            $t->assertEquals(FinanceService::cents($assessment['balance']), $ledger['balance'], 'The ledger ends at the account balance');
            $t->assertEquals($ledger['balance'], end($ledger['rows'])['balance']);

            $rows = $ledger['rows'];
            $t->assertEquals(FinanceService::cents($assessment['total_amount']), $rows[0]['debit'], 'The first row is the account, debited with the assessment total');
            $running = $rows[0]['debit'];
            foreach (array_slice($rows, 1) as $row) {
                $running += $row['debit'] - $row['credit'];
                $t->assertEquals($running, $row['balance'], 'Each balance follows from the one above');
            }
            $t->assertTrue(count(array_filter($rows, static fn ($r) => str_starts_with($r['ref'], 'OR-'))) >= 2, 'Payments show their receipt numbers');
            $t->assertTrue(count(array_filter($rows, static fn ($r) => str_starts_with($r['ref'], 'Rev. OR-'))) === 1, 'The reversal points at the receipt it cancels');
            $t->assertTrue($ledger['discountPercent'] !== null && abs($ledger['discountPercent'] - 25.0) < 0.01, 'The block shows the 25% discount');
            $t->assertEquals(FinanceService::cents($assessment['total_amount']), $ledger['tuition'] + $ledger['misc'], 'Tuition and miscellaneous make up the assessment');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the ledger card route exists and requires sign-in (who may open it is covered by the HTTP crawl)' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = array_filter($router->routes(), static fn ($r) => $r['pattern'] === '/statements/{id}/ledger');
        $t->assertEquals(1, count($found), 'The ledger route exists once');
        $t->assertTrue(in_array('auth', current($found)['middleware'], true), 'It requires sign-in');
    },
];
