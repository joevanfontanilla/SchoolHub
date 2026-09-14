<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;

/**
 * Whether an approved application has met the school's payment policy for
 * enrollment confirmation (docs/26 section 4, `enrollment_payment_policy`).
 *
 *   NONE                 no payment needed
 *   FULL                 the whole assessment must be paid
 *   MINIMUM_DOWNPAYMENT  total paid must reach minimum_downpayment_percent of the assessment
 *
 * Read from the fee assessment, whose totals FinanceService maintains.
 */
final class PaymentPolicy
{
    public const NONE                = 'NONE';
    public const FULL                = 'FULL';
    public const MINIMUM_DOWNPAYMENT = 'MINIMUM_DOWNPAYMENT';

    public static function current(): string
    {
        $policy = (string) SystemSetting::get('enrollment_payment_policy', self::MINIMUM_DOWNPAYMENT);
        return in_array($policy, [self::NONE, self::FULL, self::MINIMUM_DOWNPAYMENT], true) ? $policy : self::MINIMUM_DOWNPAYMENT;
    }

    /**
     * @param array<string,mixed> $application
     * @return string|null null when satisfied, otherwise why not (shown to the registrar and student)
     */
    public static function problem(array $application): ?string
    {
        $policy = self::current();
        if ($policy === self::NONE) {
            return null;
        }

        $assessment = \App\Core\Database::selectOne(
            "SELECT * FROM fee_assessments WHERE application_id = ? AND status = 'ACTIVE'",
            [$application['id']]
        );
        if ($assessment === null) {
            return 'Waiting for payment: the fee assessment has not been issued yet.';
        }

        $paid = FinanceService::cents($assessment['total_paid']);
        $required = self::requiredCents($assessment);
        if ($paid >= $required) {
            return null;
        }
        $due = money(FinanceService::money(FinanceService::dueCents($assessment)));

        return $policy === self::FULL
            ? 'Waiting for payment: school policy requires the amount due of ' . $due . ' to be fully paid before enrollment.'
            : sprintf(
                'Waiting for payment: school policy requires a down payment of at least %s (%d%% of %s) before enrollment. %s has been paid.',
                money(FinanceService::money($required)),
                self::downpaymentPercent(),
                $due,
                money($assessment['total_paid'])
            );
    }

    /**
     * The amount that must be paid, in centavos, before the student is enrolled.
     *
     * @param array<string,mixed> $assessment
     */
    public static function requiredCents(array $assessment): int
    {
        // The amount due: the assessment total plus discounts, scholarships, and charges.
        $due = max(0, FinanceService::dueCents($assessment));
        return match (true) {
            self::current() === self::NONE, $due === 0 => 0, // nothing to pay, e.g. a full scholarship
            self::current() === self::FULL             => $due,
            // At least one centavo, so a zero-percent setting still means "a down payment".
            default                                    => max(1, (int) ceil($due * self::downpaymentPercent() / 100)),
        };
    }

    public static function downpaymentPercent(): int
    {
        return max(0, min(100, (int) SystemSetting::get('minimum_downpayment_percent', 20)));
    }

    public static function label(?string $policy = null): string
    {
        return match ($policy ?? self::current()) {
            self::NONE => 'No payment required before confirmation',
            self::FULL => 'Full payment required before confirmation',
            default    => 'Down payment required before confirmation',
        };
    }
}
