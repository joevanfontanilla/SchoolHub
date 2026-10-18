<?php
/**
 * The grading rule and the Official Grading System (docs/27). Pure arithmetic: no database.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Services\GradeCalculator as G;

function g(string $value): array
{
    $parsed = G::parse($value);
    if ($parsed === null) {
        throw new RuntimeException("Fixture grade {$value} is not valid");
    }
    return $parsed;
}

const W = ['prelim' => 30, 'midterm' => 30, 'finals' => 40];

/** A class row as AcademicRecordService returns it, for GWA tests. */
function gwaRow(string $code, float $units, ?string $grade, string $remark = 'PASSED'): array
{
    return ['code' => $code, 'units' => $units, 'final' => ['grade' => $grade, 'remark' => $remark]];
}

return [
    'the table is the school\'s Official Grading System, row for row' => function (TestRunner $t): void {
        $t->assertEquals(50 + 5 * 3 + 1, count(G::TABLE), '50 rows from 1.00 to 1.49, then 1.50 to 3.00 in tenths');
        // Spot checks against the printed sheet.
        $expected = ['1.00' => '100', '1.01' => '99.8', '1.09' => '99.0', '1.10' => '98.4', '1.25' => '95.4', '1.29' => '95.0', '1.30' => '94.4',
            '1.44' => '92.0', '1.45' => '91.4', '1.49' => '91.0', '1.50' => '90', '1.90' => '86', '2.00' => '85', '2.40' => '81',
            '2.50' => '80', '2.90' => '76', '3.00' => '75'];
        $rows = array_column(G::TABLE, 1, 0);
        foreach ($expected as $equivalent => $percentage) {
            $t->assertEquals($percentage, $rows[$equivalent], "{$equivalent} is {$percentage}");
        }
        // Best first, and strictly decreasing, so a lookup by "highest row at or below" is well defined.
        $previous = 101.0;
        foreach (G::TABLE as [$equivalent, $percentage]) {
            $t->assertTrue((float) $percentage < $previous, "{$equivalent} is lower than the row before");
            $previous = (float) $percentage;
        }
        $t->assertEquals('Excellent', G::descriptor('1.49'));
        $t->assertEquals('Very good', G::descriptor('1.50'));
        $t->assertEquals('Good', G::descriptor('2.40'));
        $t->assertEquals('Satisfactorily', G::descriptor('2.90'));
        $t->assertEquals('Passed', G::descriptor('3.00'));
        $t->assertEquals('Failed', G::descriptor('5.00'));
    },

    'a percentage takes the equivalent of the highest row at or below it, and below 75 is 5.00' => function (TestRunner $t): void {
        foreach ([['100', '1.00'], ['99.9', '1.01'], ['99.8', '1.01'], ['99.7', '1.02'], ['92.0', '1.44'], ['91.9', '1.45'], ['91.4', '1.45'], ['91.3', '1.46'],
                  ['91.0', '1.49'], ['90.9', '1.50'], ['90', '1.50'], ['89.9', '1.60'], ['89', '1.60'], ['86', '1.90'], ['85.5', '2.00'], ['81', '2.40'],
                  ['80', '2.50'], ['76', '2.90'], ['75.9', '3.00'], ['75', '3.00'], ['74.9', '5.00'], ['60', '5.00'], ['0', '5.00']] as [$percentage, $equivalent]) {
            $t->assertEquals($equivalent, G::equivalentFor($percentage), "{$percentage}% is {$equivalent}");
        }
        $t->assertEquals('92.00', G::percentageFor('1.44'));
        $t->assertEquals('100.00', G::percentageFor('1'));
        $t->assertEquals('74.00', G::percentageFor('5.00'), 'A failing equivalent counts as 74');
        $t->assertNull(G::percentageFor('1.75'), '1.75 is not in the table');
    },

    'the teacher can type a percentage, an equivalent from the table, or a mark, and nothing else' => function (TestRunner $t): void {
        $t->assertEquals(['grade' => '1.40', 'percentage' => '92.40', 'remark' => null], G::parse('92.4'));
        $t->assertEquals(['grade' => '1.45', 'percentage' => '91.90', 'remark' => null], G::parse('91.9%'));
        $t->assertEquals(['grade' => '1.40', 'percentage' => '92.40', 'remark' => null], G::parse('1.40'), 'An equivalent brings the table\'s percentage');
        $t->assertEquals(['grade' => '1.00', 'percentage' => '100.00', 'remark' => null], G::parse('1'));
        $t->assertEquals(['grade' => '3.00', 'percentage' => '75.00', 'remark' => null], G::parse(' 3 '));
        $t->assertEquals(['grade' => '5.00', 'percentage' => '74.00', 'remark' => null], G::parse('5.00'));
        $t->assertEquals(['grade' => '5.00', 'percentage' => '60.00', 'remark' => null], G::parse('60'));
        $t->assertEquals(['grade' => '1.50', 'percentage' => '90.00', 'remark' => null], G::parse('90'));
        foreach (['INC', 'OW', 'UW', 'NGA'] as $mark) {
            $t->assertEquals(['grade' => null, 'percentage' => null, 'remark' => $mark], G::parse(strtolower($mark)), "{$mark} is a mark");
        }
        foreach (['1.75', '2.35', '3.5', '4.00', '0.75', '0', '101', '100.5', '-1', '92.456', 'ABC', '', '1.2.5', 'A+', 'DRP', '5.5'] as $bad) {
            $t->assertFalse(G::isValid($bad), "{$bad} should be refused");
        }
    },

    'the final is the weighted average of the percentages, to one decimal, looked up in the table' => function (TestRunner $t): void {
        // 0.2*92.4 + 0.2*88 + 0.2*90 + 0.4*85 = 88.08 -> 88.1 -> 1.70
        $w = ['p' => 20, 'm' => 20, 's' => 20, 'f' => 40];
        $final = G::final(['p' => g('92.4'), 'm' => g('88'), 's' => g('90'), 'f' => g('85')], $w);
        $t->assertEquals(['grade' => '1.70', 'percentage' => '88.1', 'remark' => 'PASSED'], $final);

        // 30/30/40 of equivalents typed as such: 1.75 is not enterable, so use 1.70 (88), 2.00 (85), 1.50 (90): 0.3*88 + 0.3*85 + 0.4*90 = 87.9 -> 1.80 (87)
        $t->assertEquals(['grade' => '1.80', 'percentage' => '87.9', 'remark' => 'PASSED'], G::final(['prelim' => g('1.70'), 'midterm' => g('2.00'), 'finals' => g('1.50')], W));

        // Exactly 75 passes as 3.00; just under fails as 5.00.
        $t->assertEquals(['grade' => '3.00', 'percentage' => '75.0', 'remark' => 'PASSED'], G::final(['prelim' => g('75'), 'midterm' => g('75'), 'finals' => g('75')], W));
        $t->assertEquals(['grade' => '5.00', 'percentage' => '74.9', 'remark' => 'FAILED'], G::final(['prelim' => g('75'), 'midterm' => g('75'), 'finals' => g('74.75')], ['prelim' => 30, 'midterm' => 30, 'finals' => 40]));

        // A failing equivalent typed without a percentage counts as 74.
        $t->assertEquals('5.00', G::final(['prelim' => g('5.00'), 'midterm' => g('5.00'), 'finals' => g('5.00')], W)['grade']);
    },

    'a grade saved before percentages existed is averaged by the table\'s percentage for its equivalent' => function (TestRunner $t): void {
        $legacy = static fn (string $grade): array => ['grade' => $grade, 'remark' => null];
        $final = G::final(['prelim' => $legacy('1.50'), 'midterm' => $legacy('2.00'), 'finals' => $legacy('1.00')], W);
        // 0.3*90 + 0.3*85 + 0.4*100 = 92.5 -> 1.40
        $t->assertEquals(['grade' => '1.40', 'percentage' => '92.5', 'remark' => 'PASSED'], $final);
    },

    'the marks outrank numbers in a fixed order: OW, UW, NGA, INC' => function (TestRunner $t): void {
        $mark = static fn (string $m): array => ['grade' => null, 'percentage' => null, 'remark' => $m];
        $t->assertEquals(['grade' => null, 'percentage' => null, 'remark' => 'OW'], G::final(['prelim' => g('1.00'), 'midterm' => g('INC'), 'finals' => g('OW')], W));
        $t->assertEquals('OW', G::final(['prelim' => $mark('UW'), 'midterm' => $mark('OW'), 'finals' => g('90')], W)['remark']);
        $t->assertEquals('UW', G::final(['prelim' => $mark('NGA'), 'midterm' => $mark('UW'), 'finals' => g('90')], W)['remark']);
        $t->assertEquals('NGA', G::final(['prelim' => $mark('INC'), 'midterm' => $mark('NGA'), 'finals' => g('90')], W)['remark']);
        $t->assertEquals('INC', G::final(['prelim' => g('1.00'), 'midterm' => g('INC'), 'finals' => g('1.00')], W)['remark']);
        // A mark with a period still missing is still that mark, not "in progress".
        $t->assertEquals('INC', G::final(['prelim' => g('INC')], W)['remark']);
    },

    'a missing period leaves the final in progress' => function (TestRunner $t): void {
        $none = ['grade' => null, 'percentage' => null, 'remark' => 'IN_PROGRESS'];
        $t->assertEquals($none, G::final(['prelim' => g('90'), 'midterm' => g('90')], W));
        $t->assertEquals($none, G::final(['prelim' => g('90'), 'midterm' => null, 'finals' => g('90')], W));
        $t->assertEquals($none, G::final([], W));
    },

    'different weights change the result' => function (TestRunner $t): void {
        $grades = ['prelim' => g('100'), 'midterm' => g('100'), 'finals' => g('80')];
        // 30/30/40: 30 + 30 + 32 = 92 -> 1.44
        $t->assertEquals('1.44', G::final($grades, W)['grade']);
        // 20/20/60: 20 + 20 + 48 = 88 -> 1.70
        $t->assertEquals('1.70', G::final($grades, ['prelim' => 20, 'midterm' => 20, 'finals' => 60])['grade']);
    },

    'a grade is shown as the equivalent with its percentage, and a mark as itself' => function (TestRunner $t): void {
        $t->assertEquals('1.40 (92.4%)', G::display(['grade' => '1.40', 'percentage' => '92.40', 'remark' => null]));
        $t->assertEquals('1.40', G::display(['grade' => '1.40', 'percentage' => '92.40', 'remark' => null], false));
        $t->assertEquals('2.00', G::display(['grade' => '2.00', 'percentage' => null, 'remark' => null]));
        $t->assertEquals('NGA', G::display(['grade' => null, 'percentage' => null, 'remark' => 'NGA']));
        $t->assertEquals('—', G::display(null));
    },

    'the GWA is unit-weighted over numeric finals, leaving out NSTP, PE, the marks, and in-progress classes' => function (TestRunner $t): void {
        $classes = [
            gwaRow('CORE 1', 3, '1.50'),
            gwaRow('CLJ 4', 4, '2.00'),
            gwaRow('CORE 2', 3, '5.00', 'FAILED'),     // a failure counts
            gwaRow('NSTP 01', 3, '1.00'),              // does not count
            gwaRow('PATH-FIT 1', 2, '1.00'),           // does not count
            gwaRow('PED1', 2, '1.00'),                 // does not count (Criminology PE)
            gwaRow('GE001', 3, null, 'INC'),
            gwaRow('GE002', 3, null, 'OW'),
            gwaRow('GE005', 3, null, 'UW'),
            gwaRow('GE006', 3, null, 'NGA'),
            gwaRow('GE003', 3, null, 'IN_PROGRESS'),
        ];
        // (1.50×3 + 2.00×4 + 5.00×3) ÷ 10 = 27.5 ÷ 10 = 2.75
        $t->assertEquals('2.75', G::gwa($classes));
        $t->assertNull(G::gwa([gwaRow('NSTP 02', 3, '1.25'), gwaRow('GE004', 3, null, 'INC')]), 'Nothing counts yet');
        $t->assertEquals('1.83', G::gwa([gwaRow('A', 3, '1.80'), gwaRow('B', 3, '1.80'), gwaRow('C', 3, '1.90')]), '1.8333 rounds to 1.83');
        $t->assertEquals('1.85', G::gwa([gwaRow('A', 3, '1.80'), gwaRow('B', 3, '1.90')]), '1.85');
    },

    'only NSTP and physical education codes are left out of the GWA' => function (TestRunner $t): void {
        foreach (['NSTP 01', 'nstp02', 'CWTS 1', 'ROTC 2', 'LTS 1', 'PATH-FIT 3', 'PATHFIT 4', 'PE 1', 'PED4', 'PED 2'] as $excluded) {
            $t->assertFalse(G::countsTowardGwa($excluded), "{$excluded} is left out");
        }
        foreach (['GE010', 'PEACE 1', 'PUB AD 1', 'PROF1', 'PED', 'EPC', 'CORE 1', 'PRACTICUM 1'] as $counted) {
            $t->assertTrue(G::countsTowardGwa($counted), "{$counted} counts");
        }
    },

    'weights must be positive and total exactly 100' => function (TestRunner $t): void {
        foreach ([['a' => 30, 'b' => 30, 'c' => 30], ['a' => 50, 'b' => 60], ['a' => 100, 'b' => 0], ['a' => -10, 'b' => 110], []] as $bad) {
            $t->assertThrows(static fn () => G::assertWeights($bad), null, 'Weights ' . json_encode($bad) . ' must be refused');
        }
        G::assertWeights(['a' => 30, 'b' => 30, 'c' => 40]);
        G::assertWeights(['a' => 33.33, 'b' => 33.33, 'c' => 33.34]);
        $t->assertTrue(true);
    },
];
