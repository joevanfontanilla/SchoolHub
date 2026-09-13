<?php
/** Public requirements page (docs/06, docs/10). */
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Requirements</h1>
    <p class="mt-2 text-slate-600">
        Prepare clear scans or photographs of the documents below. Accepted file types are PDF, JPG, and PNG,
        up to 5 MB each.
    </p>

    <div class="table-scroll mt-8">
        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <caption class="sr-only">Documents required for enrollment, by student type</caption>
            <thead class="bg-slate-50">
                <tr>
                    <th scope="col" class="px-4 py-3 font-semibold text-brand-navy">Document</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-brand-navy">Applies to</th>
                    <th scope="col" class="px-4 py-3 font-semibold text-brand-navy">Required</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                $requirements = [
                    ['PSA Birth Certificate', 'All applicants', true],
                    ['Form 138 / Report Card', 'New students', true],
                    ['Certificate of Good Moral Character', 'All applicants', true],
                    ['Transfer Credential (Honorable Dismissal)', 'Transferees', true],
                    ['Transcript of Records', 'Transferees', true],
                    ['2x2 ID Photograph', 'All applicants', true],
                    ['Valid Government or School ID', 'All applicants', false],
                    ['Medical Certificate', 'All applicants', false],
                ];
                foreach ($requirements as [$document, $applies, $required]):
                ?>
                    <tr>
                        <th scope="row" class="px-4 py-3 font-medium text-brand-navy"><?= e($document) ?></th>
                        <td class="px-4 py-3 text-slate-600"><?= e($applies) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($required): ?>
                                <span class="badge bg-brand-100 text-brand-700">Required</span>
                            <?php else: ?>
                                <span class="badge bg-slate-100 text-slate-600">Optional</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-8 rounded-xl border border-slate-200 bg-slate-50 p-5">
        <h2 class="text-sm font-semibold text-brand-navy">A note on your documents</h2>
        <p class="mt-1.5 text-sm text-slate-600">
            Uploaded documents are stored privately and are visible only to you and the registrar's office.
            They are never published on this website.
        </p>
    </div>
</div>
