<?php
/**
 * Heading and tabs shared by the cashier reports.
 *
 * @var string $active   collections|outstanding
 * @var string $heading
 * @var string $subtitle
 */
echo partial('report-header', [
    'eyebrow'  => 'Cashier reports',
    'tabs'     => [
        'collections' => ['/cashier/reports/collections', 'Collections', 'banknotes'],
        'outstanding' => ['/cashier/reports/outstanding', 'Outstanding balances', 'credit-card'],
    ],
    'active'   => $active,
    'heading'  => $heading,
    'subtitle' => $subtitle,
]);
