<?php
/**
 * Status badge for any enrollment status value, on any axis.
 *
 * Always label + icon + color, never color alone (docs/21). The label comes
 * from EnrollmentApplication::label() so wording is defined in one place.
 *
 * @var string $status
 * @var string $size   'sm' (default) or 'md'
 * @var string $label  optional wording, when the status code alone is not the words to show
 */
use App\Models\EnrollmentApplication;

$tones = [
    'neutral' => 'bg-slate-100 text-slate-700 ring-slate-200',
    'info'    => 'bg-brand-50 text-brand-700 ring-brand-200',
    'success' => 'bg-success-bg text-success-text ring-success-line',
    'warning' => 'bg-warning-bg text-warning-text ring-warning-line',
    'danger'  => 'bg-danger-bg text-danger-text ring-danger-line',
];

$icons = [
    'pencil'  => 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z',
    'send'    => 'M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5',
    'clock'   => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    'alert'   => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
    'check'   => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    'x'       => 'm9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    'minus'   => 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
];

[$tone, $icon] = match ($status) {
    'DRAFT', 'NOT_SUBMITTED', 'NOT_ASSESSED', 'NOT_ENROLLED' => ['neutral', 'minus'],
    'SUBMITTED'                                              => ['info', 'send'],
    'UNDER_REVIEW'                                           => ['info', 'clock'],
    'REQUIREMENTS_INCOMPLETE', 'CORRECTION_REQUIRED',
    'PENDING', 'PARTIAL'                                     => ['warning', 'alert'],
    'APPROVED', 'VERIFIED', 'PAID', 'ENROLLED'               => ['success', 'check'],
    'REJECTED'                                               => ['danger', 'x'],
    'CANCELLED'                                              => ['neutral', 'x'],
    // Classes, grades, and attendance (docs/12, docs/27)
    'PASSED', 'PRESENT', 'ENLISTED', 'OPEN'                  => ['success', 'check'],
    'FAILED', 'ABSENT'                                       => ['danger', 'x'],
    'INC', 'LATE'                                           => ['warning', 'alert'],
    'IN_PROGRESS'                                            => ['info', 'clock'],
    'EXCUSED'                                                => ['info', 'minus'],
    'DRP', 'DROPPED', 'CLOSED'                              => ['neutral', 'x'],
    default                                                  => ['neutral', 'minus'],
};

$md = ($size ?? 'sm') === 'md';
?>
<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-semibold ring-1 ring-inset <?= $tones[$tone] ?> <?= $md ? 'px-3 py-1.5 text-sm' : 'px-2.5 py-1 text-xs' ?>">
    <svg class="<?= $md ? 'h-4 w-4' : 'h-3.5 w-3.5' ?> shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icons[$icon] ?>"/>
    </svg>
    <?= e($label ?? EnrollmentApplication::label($status)) ?>
</span>
