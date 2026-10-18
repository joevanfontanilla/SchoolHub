<?php
/**
 * One side of the e-ID card: portrait CR80, 54 x 85.6 mm, like the school's printed ID (docs/29).
 *
 * @var array  $card EIdService::card()
 * @var string $side front|back
 * @var array  $school
 */
$schoolName = (string) ($school['school_name'] ?? 'Lyceum of the East Aurora');
$schoolAddress = (string) ($school['school_address'] ?? '');
$nameParts = explode(' ', trim($schoolName), 2);
$initials = strtoupper(mb_substr(preg_replace('/[^\p{L} ]/u', '', $card['name']), 0, 1));
$student = $card['kind'] === 'student';
$ribbon = (string) ($card['ribbonName'] ?? mb_strtoupper($card['name']));
$ribbonSize = mb_strlen($ribbon) > 26 ? '2.5mm' : (mb_strlen($ribbon) > 20 ? '2.9mm' : '3.3mm');
$program = mb_strtoupper((string) ($student ? ($card['program'] ?? $card['programCode'] ?? '') : $card['role']));
$president = $card['president'] ?? ['name' => '', 'title' => '', 'signatureUri' => null];
$blue = '#1F5FA8';
$qrLabelStyle = $student ? 'left:3mm;width:18mm;top:74mm' : 'left:0;right:0;top:49mm';
$returnStyle = $student ? 'left:23mm;right:2mm;top:55mm;text-align:left' : 'left:3mm;right:3mm;top:54mm;text-align:center';
$presidentStyle = $student ? 'left:23mm;right:1mm;top:66mm' : 'left:3mm;right:3mm;top:64mm';
$qrLeft = $student ? '3mm' : '15mm';
$face = 'position:relative;overflow:hidden;width:54mm;height:85.6mm;border-radius:3mm;background:linear-gradient(180deg,#EEF6FC 0%,#CFE4F4 100%);color:#14213d;font-family:Calibri,Arial,sans-serif';
?>
<?php if ($side === 'front'): ?>
    <div style="<?= e($face) ?>" role="group" aria-label="E-ID, front">
        <div style="position:absolute;left:0;top:0;width:54mm;height:23mm;background:linear-gradient(120deg,#1B5CA6 0%,#2F8FD0 70%,#53B4E8 100%)">
            <img src="<?= e(school_logo_url()) ?>" alt="" class="school-seal" style="position:absolute;left:2.5mm;top:3mm;width:11mm;height:11mm;border-radius:50%;background:#fff">
            <div style="position:absolute;left:15mm;right:2mm;top:2.5mm;color:#fff;line-height:1.05">
                <p style="margin:0;font-weight:900;text-transform:uppercase;letter-spacing:.2mm;font-size:5mm"><?= e($nameParts[0]) ?></p>
                <p style="margin:.3mm 0 0;font-weight:700;text-transform:uppercase;font-size:2.1mm"><?= e($nameParts[1] ?? '') ?></p>
                <p style="margin:.6mm 0 0;text-transform:uppercase;font-size:1.5mm;opacity:.95"><?= e($schoolAddress) ?></p>
            </div>
            <svg viewBox="0 0 54 6" preserveAspectRatio="none" style="position:absolute;left:0;bottom:-.2mm;width:54mm;height:5mm" aria-hidden="true"><path d="M0 4 C 9 0.5, 20 6, 31 3 S 47 0.5, 54 3 L54 6 L0 6 Z" fill="#EEF6FC"/></svg>
        </div>

        <img src="<?= e(asset('assets/images/campus.jpg')) ?>" alt="" style="position:absolute;left:0;top:23mm;width:54mm;height:37.5mm;object-fit:cover;object-position:50% 50%">
        <div style="position:absolute;left:2.5mm;top:26mm;width:24mm;height:30mm;background:#fff;border:.3mm solid #fff;box-shadow:0 0 .6mm rgba(0,0,0,.35);overflow:hidden">
            <?php if (!empty($card['photoUri'])): ?>
                <img src="<?= e($card['photoUri']) ?>" alt="Photo of <?= e($card['name']) ?>" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
                <div style="display:flex;width:100%;height:100%;align-items:center;justify-content:center;background:#E3F0FA;color:#1F5FA8;font-weight:800;font-size:11mm" aria-hidden="true"><?= e($initials) ?></div>
            <?php endif; ?>
        </div>
        <div style="position:absolute;left:29mm;top:26mm;width:23mm;height:30mm;border-radius:1mm;color:#fff">
            <?php if ($card['schoolYear']): ?><p style="position:absolute;left:2mm;right:2mm;top:1.5mm;margin:0;text-align:center;font-size:1.7mm;font-weight:700;letter-spacing:.1mm;background:rgba(20,33,61,.72);border-radius:.8mm;padding:.5mm 0">S.Y. <?= e($card['schoolYear']) ?></p><?php endif; ?>
            <div style="position:absolute;left:0;right:0;bottom:2mm;text-align:center;background:rgba(255,255,255,.92);padding:1mm 0">
                <p style="margin:0;font-size:1.8mm;font-weight:800;font-style:italic;color:#14213d"><?= $student ? 'Student ID. No.' : 'ID No.' ?></p>
                <p style="margin:.2mm 0 0;font-size:3mm;font-weight:900;color:#C0172B;letter-spacing:.1mm"><?= e($card['idNumber']) ?></p>
            </div>
        </div>

        <div style="position:absolute;left:0;top:60.5mm;width:54mm;height:7.2mm;background:<?= e($blue) ?>;display:flex;align-items:center;justify-content:center">
            <p style="margin:0;max-width:51mm;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#fff;font-weight:800;text-transform:uppercase;font-size:<?= e($ribbonSize) ?>"><?= e($ribbon) ?></p>
        </div>
        <p style="position:absolute;left:2mm;right:2mm;top:68.6mm;margin:0;text-align:center;font-weight:900;text-transform:uppercase;line-height:1.1;font-size:2.8mm"><?= e($program) ?></p>

        <div style="position:absolute;left:0;right:0;top:74mm;text-align:center">
            <?php if (!empty($card['signatureUri'])): ?>
                <img src="<?= e($card['signatureUri']) ?>" alt="Signature of <?= e($card['name']) ?>" style="display:block;margin:0 auto;height:6mm;max-width:34mm;object-fit:contain">
            <?php else: ?>
                <div style="height:6mm"></div>
            <?php endif; ?>
            <div style="margin:0 auto;width:42mm;border-top:.35mm solid #14213d"></div>
            <p style="margin:.4mm 0 0;font-size:1.8mm;font-weight:800;letter-spacing:.1mm">SIGNATURE</p>
        </div>
    </div>
<?php else: ?>
    <div style="<?= e($face) ?>" role="group" aria-label="E-ID, back">
        <?php if ($student): ?>
            <div style="position:absolute;left:0;top:0;width:54mm;height:16mm;background:<?= e($blue) ?>;color:#fff;font-size:1.9mm;line-height:1.15">
                <p style="position:absolute;left:3mm;right:3mm;top:1.8mm;margin:0;max-height:5.2mm;overflow:hidden"><b style="display:inline-block;width:12mm">Address:</b><span style="font-weight:700;text-transform:uppercase"><?= e((string) ($card['address'] ?? '')) ?></span></p>
                <p style="position:absolute;left:3mm;right:3mm;top:8mm;margin:0"><b style="display:inline-block;width:12mm">Birthday:</b><span style="font-weight:700;text-transform:uppercase"><?= e((string) ($card['birthday'] ?? '')) ?></span></p>
                <p style="position:absolute;left:3mm;right:3mm;top:11.2mm;margin:0"><b style="display:inline-block;width:12mm">Blood Type:</b><span style="font-weight:700"><?= e((string) ($card['bloodType'] ?? '')) ?></span></p>
            </div>
            <div style="position:absolute;left:2mm;right:2mm;top:17.5mm;text-align:center;line-height:1.2">
                <p style="margin:0;font-size:1.65mm;font-weight:800;font-style:italic">IN CASE OF EMERGENCY, PLEASE NOTIFY:</p>
                <?php if (!empty($card['emergency'])): ?>
                    <p style="margin:.8mm 0 0;font-size:2.2mm;font-weight:800;text-transform:uppercase"><?= e($card['emergency']['name']) ?></p>
                    <?php if ($card['emergency']['address'] !== ''): ?><p style="margin:.2mm 0 0;font-size:1.6mm;text-transform:uppercase"><?= e($card['emergency']['address']) ?></p><?php endif; ?>
                    <p style="margin:.2mm 0 0;font-size:2mm;font-weight:700"><?= e($card['emergency']['mobile']) ?></p>
                <?php endif; ?>
            </div>

            <table style="position:absolute;left:2.5mm;top:28.5mm;width:49mm;border-collapse:collapse;font-size:1.55mm;text-align:center;background:rgba(255,255,255,.5)">
                <thead><tr style="color:#1F5FA8;font-weight:800">
                    <th style="border:.25mm solid #14213d;padding:.5mm;height:5mm;line-height:1.05">ACADEMIC<br>YEAR</th>
                    <th style="border:.25mm solid #14213d;padding:.5mm;line-height:1.05">1st<br>SEMESTER</th>
                    <th style="border:.25mm solid #14213d;padding:.5mm;line-height:1.05">2nd<br>SEMESTER</th>
                </tr></thead>
                <tbody>
                    <?php foreach (($card['grid'] ?? []) as $row): ?>
                        <tr style="height:4.2mm">
                            <td style="border:.25mm solid #14213d;font-weight:800;font-size:1.7mm"><?= e($row['year']) ?></td>
                            <td style="border:.25mm solid #14213d"><span class="screen-only" style="color:#1F8A4C;font-weight:900"><?= e($row['marks'][1] ? '✓' : '') ?></span></td>
                            <td style="border:.25mm solid #14213d"><span class="screen-only" style="color:#1F8A4C;font-weight:900"><?= e($row['marks'][2] ? '✓' : '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php $qrTop = '55mm'; $qrSize = '18mm'; ?>
        <?php else: ?>
            <div style="position:absolute;left:0;top:0;width:54mm;height:16mm;background:<?= e($blue) ?>;color:#fff;text-align:center">
                <p style="margin:0;padding-top:3.5mm;font-size:2mm;font-weight:700;letter-spacing:.3mm">STAFF IDENTIFICATION</p>
                <p style="margin:1mm 0 0;font-size:3mm;font-weight:900;text-transform:uppercase"><?= e($card['role']) ?></p>
            </div>
            <?php $qrTop = '24mm'; $qrSize = '24mm'; ?>
        <?php endif; ?>

        <div style="position:absolute;left:<?= e($qrLeft) ?>;top:<?= e($qrTop) ?>;width:<?= e($qrSize) ?>;height:<?= e($qrSize) ?>;background:#fff;padding:.6mm;box-sizing:border-box"><?= $card['qrSvg'] ?></div>
        <p style="position:absolute;<?= e($qrLabelStyle) ?>;margin:0;text-align:center;font-size:1.6mm;font-weight:800">SCAN TO VERIFY</p>

        <div style="position:absolute;<?= e($returnStyle) ?>;line-height:1.2">
            <p style="margin:0;font-size:1.7mm;font-weight:700">If found, please return to</p>
            <p style="margin:.3mm 0 0;font-size:2.1mm;font-weight:900;text-transform:uppercase"><?= e($schoolName) ?></p>
            <p style="margin:.2mm 0 0;font-size:1.6mm"><?= e($schoolAddress) ?></p>
        </div>

        <div style="position:absolute;<?= e($presidentStyle) ?>;text-align:center">
            <?php if (!empty($president['signatureUri'])): ?>
                <img src="<?= e($president['signatureUri']) ?>" alt="Signature of <?= e($president['name']) ?>" style="display:block;margin:0 auto;height:5mm;max-width:26mm;object-fit:contain">
            <?php endif; ?>
            <p style="margin:.2mm 0 0;font-size:2mm;font-weight:900;text-transform:uppercase"><?= e($president['name']) ?></p>
            <p style="margin:0;font-size:1.7mm;font-weight:700;text-transform:uppercase"><?= e($president['title']) ?></p>
        </div>

        <div style="position:absolute;left:0;bottom:0;width:54mm;height:5mm;background:<?= e($blue) ?>;color:#fff;text-align:center;line-height:5mm;font-size:2mm;font-weight:800;letter-spacing:.9mm">NON-TRANSFERABLE</div>
    </div>
<?php endif; ?>
