<?php
/**
 * One side of the e-ID card, CR80 size: 85.6 x 54 mm (docs/29).
 *
 * @var array  $card EIdService::card()
 * @var string $side front|back
 * @var array  $school
 */
$schoolName = $school['school_name'] ?? 'Lyceum of the East Aurora';
$initials = strtoupper(mb_substr(preg_replace('/[^\p{L} ]/u', '', $card['name']), 0, 1));
$line = $card['kind'] === 'student'
    ? trim(($card['programCode'] ?? '') . ($card['yearLevel'] ? ' · ' . $card['yearLevel'] : ''))
    : $card['role'];
$box = 'relative overflow-hidden bg-white text-slate-800 shadow-md ring-1 ring-slate-300 print:shadow-none print:ring-0';
?>
<?php if ($side === 'front'): ?>
    <div class="<?= $box ?>" style="width:85.6mm;height:54mm;border-radius:3mm" role="group" aria-label="E-ID, front">
        <div class="flex items-center gap-[2mm] bg-brand-navy px-[3mm] text-white" style="height:11mm">
            <img src="<?= e(school_logo_url()) ?>" alt="" class="school-seal" style="height:8mm;width:8mm">
            <div class="min-w-0 leading-tight">
                <p class="truncate font-display font-extrabold uppercase" style="font-size:2.9mm"><?= e($schoolName) ?></p>
                <p class="truncate opacity-80" style="font-size:2mm"><?= e($school['school_tagline'] ?? '') ?></p>
            </div>
        </div>
        <div class="flex gap-[3mm] px-[3mm]" style="margin-top:3mm">
            <div class="shrink-0 overflow-hidden bg-slate-100 ring-1 ring-slate-300" style="width:24mm;height:30mm;border-radius:1.5mm">
                <?php if (!empty($card['photoUri'])): ?>
                    <img src="<?= e($card['photoUri']) ?>" alt="Photo of <?= e($card['name']) ?>" class="h-full w-full object-cover">
                <?php else: ?>
                    <div class="flex h-full w-full items-center justify-center bg-brand-50 font-display font-bold text-brand-navy" style="font-size:10mm" aria-hidden="true"><?= e($initials) ?></div>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1 leading-snug">
                <p class="font-display font-extrabold uppercase text-brand-navy" style="font-size:3.6mm"><?= e($card['name']) ?></p>
                <p class="text-slate-500" style="margin-top:1.5mm;font-size:2mm">ID number</p>
                <p class="font-display font-bold tabular-nums" style="font-size:3.4mm"><?= e($card['idNumber']) ?></p>
                <p class="text-slate-500" style="margin-top:1.2mm;font-size:2mm"><?= $card['kind'] === 'student' ? 'Program and year' : 'Position' ?></p>
                <p class="font-semibold" style="font-size:2.8mm"><?= e($line) ?></p>
                <?php if ($card['schoolYear']): ?><p class="text-slate-600" style="margin-top:1mm;font-size:2.4mm">S.Y. <?= e($card['schoolYear']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-r from-brand-sky via-brand-primary to-brand-navy" style="height:2mm" aria-hidden="true"></div>
    </div>
<?php else: ?>
    <div class="<?= $box ?>" style="width:85.6mm;height:54mm;border-radius:3mm" role="group" aria-label="E-ID, back">
        <div class="flex gap-[3mm] px-[3mm]" style="padding-top:3.5mm">
            <div class="shrink-0 bg-white" style="width:34mm;height:34mm"><?= $card['qrSvg'] ?></div>
            <div class="min-w-0 flex-1 leading-snug" style="font-size:2.3mm">
                <p class="font-display font-bold text-brand-navy" style="font-size:3mm">Scan to verify</p>
                <p class="text-slate-600" style="margin-top:1mm">Scan the code with a phone camera. The school's website says whether this ID is valid.</p>
                <p class="text-slate-500" style="margin-top:1.5mm"><?= e($school['school_address'] ?? '') ?></p>
                <p class="text-slate-500"><?= e($school['school_phone'] ?? '') ?></p>
            </div>
        </div>
        <div class="absolute inset-x-0 text-center" style="bottom:4mm">
            <?php if (!empty($card['signatureUri'])): ?>
                <img src="<?= e($card['signatureUri']) ?>" alt="Signature of <?= e($card['name']) ?>" class="mx-auto object-contain" style="height:9mm;max-width:40mm">
            <?php endif; ?>
            <p class="mx-auto border-t border-slate-400 pt-[0.5mm] text-slate-500" style="width:44mm;font-size:2mm">Holder's signature</p>
        </div>
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-r from-brand-sky via-brand-primary to-brand-navy" style="height:2mm" aria-hidden="true"></div>
    </div>
<?php endif; ?>
