<?php
/**
 * Printable disbursement voucher (docs/14).
 *
 * @var array<string,mixed> $voucher
 */
?>
<section class="mx-auto mt-6 max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-brand-navy pb-3">
        <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Disbursement Voucher</h1>
        <dl class="text-right text-sm">
            <div><dt class="inline text-slate-500">Voucher No.</dt> <dd class="inline font-display text-lg font-bold text-brand-navy"><?= e($voucher['voucher_number']) ?></dd></div>
            <div><dt class="inline text-slate-500">Date</dt> <dd class="inline font-semibold"><?= e(dt($voucher['disbursed_at'], 'F j, Y')) ?></dd></div>
        </dl>
    </div>

    <dl class="space-y-3 text-sm">
        <div class="flex gap-3"><dt class="w-32 shrink-0 text-slate-500">Pay to</dt><dd class="font-semibold text-brand-navy"><?= e($voucher['payee']) ?></dd></div>
        <div class="flex gap-3"><dt class="w-32 shrink-0 text-slate-500">Particulars</dt><dd><?= e($voucher['particulars']) ?></dd></div>
        <div class="flex gap-3"><dt class="w-32 shrink-0 text-slate-500">Payment method</dt><dd><?= e($voucher['payment_method']) ?><?php if ($voucher['reference_number']): ?> · Ref. <?= e($voucher['reference_number']) ?><?php endif; ?></dd></div>
    </dl>

    <div class="flex items-center justify-between rounded border border-slate-300 bg-slate-50 px-4 py-3">
        <span class="text-sm font-semibold uppercase tracking-wide text-slate-500">Amount</span>
        <span class="font-display text-2xl font-extrabold tabular-nums text-brand-navy"><?= e(money($voucher['amount'])) ?></span>
    </div>

    <div class="grid grid-cols-3 gap-6 pt-10 text-center text-xs text-slate-600">
        <div><?= partial('signature-image', ['userId' => (int) $voucher['recorded_by'], 'name' => trim($voucher['first_name'] . ' ' . $voucher['last_name'])]) ?><div class="border-t border-slate-400 pt-1"><?= e(trim($voucher['first_name'] . ' ' . $voucher['last_name'])) ?></div><div class="mt-0.5">Prepared by (Cashier)</div></div>
        <div><div class="border-t border-slate-400 pt-1">&nbsp;</div><div class="mt-0.5">Approved by</div></div>
        <div><div class="border-t border-slate-400 pt-1">&nbsp;</div><div class="mt-0.5">Received by (Payee)</div></div>
    </div>
</section>
