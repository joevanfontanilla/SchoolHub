<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;

/**
 * Whether an approved application has met the school's payment policy for
 * enrollment confirmation (docs/26 section 4, `enrollment_payment_policy`).
 *
 *   NONE                 no payment needed
 *   FULL                 payment_status must be PAID
 *   MINIMUM_DOWNPAYMENT  payment_status must be PARTIAL or PAID
 *
 * payment_status is derived from assessments and payments, which arrive with the
 * finance module. Until then it stays NOT_ASSESSED, so under FULL or
 * MINIMUM_DOWNPAYMENT confirmation is correctly refused ("waiting for payment").
 * The finance module also adds the minimum-percentage check against the
 * assessment total; this class is where it belongs.
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
     * @return string|null null when satisfied, otherwise why not (shown to the registrar)
     */
    public static function problem(array $application): ?string
    {
        $status = (string) $application['payment_status'];

        return match (self::current()) {
            self::NONE => null,
            self::FULL => $status === 'PAID'
                ? null
                : 'Waiting for payment: school policy requires the assessment to be fully paid before enrollment is confirmed.',
            default => in_array($status, ['PARTIAL', 'PAID'], true)
                ? null
                : 'Waiting for payment: school policy requires a down payment before enrollment is confirmed.',
        };
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
