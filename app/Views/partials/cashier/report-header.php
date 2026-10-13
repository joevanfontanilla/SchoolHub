<?php
/**
 * Heading and tabs shared by the cashier reports.
 *
 * @var string $active   collections|outstanding|accounts|crj|cdj
 * @var string $heading
 * @var string $subtitle
 */
echo partial('report-header', [
    'eyebrow'  => 'Cashier reports',
    'tabs'     => [
        'collections'   => ['/cashier/reports/collections', 'Collections', 'banknotes'],
        'outstanding'   => ['/cashier/reports/outstanding', 'Outstanding balances', 'credit-card'],
        'accounts'      => ['/cashier/reports/account-list', 'Account list', 'users'],
        'crj'           => ['/cashier/reports/receipts-journal', 'Cash Receipts Journal', 'receipt'],
        'cdj'           => ['/cashier/reports/disbursements-journal', 'Cash Disbursements Journal', 'banknotes'],
    ],
    'active'   => $active,
    'heading'  => $heading,
    'subtitle' => $subtitle,
]);
