<?php
/**
 * The grading rule (docs/27). Pure arithmetic — no database.
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

return [
    'only the scale steps, INC, and DRP are valid entries' => function (TestRunner $t): void {
        foreach (['1', '1.00', '1.25', '1.5', '2.75', '3.00', '5', '5.00', 'inc', 'DRP', ' 2.00 '] as $good) {
            $t->assertTrue(G::isValid($good), "{$good} should be valid");
        }
        foreach (['2.30', '4.00', '0.75', '3.25', '1.125', '6', '0', '-1', 'ABC', '', '1.2.5', 'A+'] as $bad) {
            $t->assertFalse(G::isValid($bad), "{$bad} should be refused");
        }
        $t->assertEquals(['grade' => '1.50', 'remark' => null], G::parse('1.5'), 'Normalized to two decimals');
        $t->assertEquals(['grade' => null, 'remark' => 'INC'], G::parse('inc'));
    },

    'the weighted example from the policy: 1.75 / 2.00 / 1.50 gives 1.75' => function (TestRunner $t): void {
        // 0.3*1.75 + 0.3*2.00 + 0.4*1.50 = 0.525 + 0.600 + 0.600 = 1.725 -> nearest step 1.75
        $final = G::final(['prelim' => g('1.75'), 'midterm' => g('2.00'), 'finals' => g('1.50')], W);
        $t->assertEquals(['grade' => '1.75', 'remark' => 'PASSED'], $final);
    },

    'an average above 3.00 fails with 5.00 and is never rounded into a pass' => function (TestRunner $t): void {
        // 0.3*3.00 + 0.3*3.00 + 0.4*3.25... 3.25 is not enterable, so use 5.00 somewhere:
        // 0.3*2.75 + 0.3*3.00 + 0.4*3.00 = 0.825 + 0.9 + 1.2 = 2.925 -> passes as 3.00
        $t->assertEquals(['grade' => '3.00', 'remark' => 'PASSED'], G::final(['prelim' => g('2.75'), 'midterm' => g('3.00'), 'finals' => g('3.00')], W));

        // 0.3*3.00 + 0.3*3.00 + 0.4*5.00 = 0.9 + 0.9 + 2.0 = 3.80 -> 5.00
        $t->assertEquals(['grade' => '5.00', 'remark' => 'FAILED'], G::final(['prelim' => g('3.00'), 'midterm' => g('3.00'), 'finals' => g('5.00')], W));

        // Directly: 3.01 and 3.10 must fail, even though they are "close to" 3.00.
        $t->assertEquals('5.00', G::roundAverage(3.01));
        $t->assertEquals('5.00', G::roundAverage(3.10));
    },

    'exactly 3.00 passes, even with floating-point noise' => function (TestRunner $t): void {
        $t->assertEquals('3.00', G::roundAverage(3.0));
        $t->assertEquals('3.00', G::roundAverage(3.0000000000000004), 'Noise above 3.00 must not fail the student');
        $t->assertEquals(['grade' => '3.00', 'remark' => 'PASSED'], G::final(['prelim' => g('3.00'), 'midterm' => g('3.00'), 'finals' => g('3.00')], W));
    },

    'rounding goes to the nearest step, and an exact tie goes to the worse grade' => function (TestRunner $t): void {
        $t->assertEquals('1.25', G::roundAverage(1.20), '1.20 is nearer 1.25');
        $t->assertEquals('1.00', G::roundAverage(1.10), '1.10 is nearer 1.00');
        $t->assertEquals('1.25', G::roundAverage(1.125), 'A tie rounds up to the worse grade');
        $t->assertEquals('2.75', G::roundAverage(2.625), 'Tie');
        $t->assertEquals('3.00', G::roundAverage(2.875), 'A tie at the top still passes as 3.00');
        $t->assertEquals('1.00', G::roundAverage(1.0));
    },

    'DRP outranks INC, and INC outranks numbers' => function (TestRunner $t): void {
        $t->assertEquals(['grade' => null, 'remark' => 'DRP'], G::final(['prelim' => g('1.00'), 'midterm' => g('INC'), 'finals' => g('DRP')], W));
        $t->assertEquals(['grade' => null, 'remark' => 'INC'], G::final(['prelim' => g('1.00'), 'midterm' => g('INC'), 'finals' => g('1.00')], W));
        // INC with a period still missing is still INC, not "in progress".
        $t->assertEquals(['grade' => null, 'remark' => 'INC'], G::final(['prelim' => g('INC')], W));
    },

    'a missing period leaves the final in progress' => function (TestRunner $t): void {
        $t->assertEquals(['grade' => null, 'remark' => 'IN_PROGRESS'], G::final(['prelim' => g('1.50'), 'midterm' => g('1.50')], W));
        $t->assertEquals(['grade' => null, 'remark' => 'IN_PROGRESS'], G::final(['prelim' => g('1.50'), 'midterm' => null, 'finals' => g('1.50')], W));
        $t->assertEquals(['grade' => null, 'remark' => 'IN_PROGRESS'], G::final([], W));
    },

    'weights must be positive and total exactly 100' => function (TestRunner $t): void {
        foreach ([['a' => 30, 'b' => 30, 'c' => 30], ['a' => 50, 'b' => 60], ['a' => 100, 'b' => 0], ['a' => -10, 'b' => 110], []] as $bad) {
            $t->assertThrows(static fn () => G::assertWeights($bad), null, 'Weights ' . json_encode($bad) . ' must be refused');
        }
        G::assertWeights(['a' => 30, 'b' => 30, 'c' => 40]);
        G::assertWeights(['a' => 33.33, 'b' => 33.33, 'c' => 33.34]);
        $t->assertTrue(true);
    },

    'different weights change the result' => function (TestRunner $t): void {
        $grades = ['prelim' => g('1.00'), 'midterm' => g('1.00'), 'finals' => g('3.00')];
        // 30/30/40: 0.3 + 0.3 + 1.2 = 1.80 -> 1.75
        $t->assertEquals('1.75', G::final($grades, W)['grade']);
        // 20/20/60: 0.2 + 0.2 + 1.8 = 2.20 -> 2.25
        $t->assertEquals('2.25', G::final($grades, ['prelim' => 20, 'midterm' => 20, 'finals' => 60])['grade']);
    },
];
