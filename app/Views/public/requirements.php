<?php
/**
 * Public requirements page (docs/06, docs/10). Read from requirement_types, so
 * it always matches what applicants are actually asked for.
 *
 * @var array $requirements
 */
use App\Models\RequirementType;
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <p class="eyebrow">Enrollment</p>
    <h1 class="mt-2 text-2xl sm:text-3xl">Requirements</h1>
    <p class="mt-2 text-slate-600">
        Prepare clear scans or photographs of the documents below. Accepted file types are PDF, JPG, and PNG,
        up to 5 MB each. The documents you are asked for depend on your student type.
    </p>

    <?php if ($requirements === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', ['title' => 'The requirement list has not been published yet']) ?>
        </div>
    <?php else: ?>
        <div class="table-scroll mt-8">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <caption class="sr-only">Documents required for enrollment, by student type</caption>
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Document</th>
                        <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Applies to</th>
                        <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Required</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($requirements as $requirement): ?>
                        <tr>
                            <th scope="row" class="px-4 py-3 align-top font-medium text-brand-navy">
                                <?= e($requirement['name']) ?>
                                <?php if (!empty($requirement['description'])): ?>
                                    <span class="mt-0.5 block text-xs font-normal text-brand-gray"><?= e($requirement['description']) ?></span>
                                <?php endif; ?>
                            </th>
                            <td class="px-4 py-3 align-top text-slate-600"><?= e(RequirementType::appliesLabel($requirement)) ?></td>
                            <td class="px-4 py-3 align-top">
                                <?php if ((int) $requirement['is_required'] === 1): ?>
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
    <?php endif; ?>

    <div class="mt-8 rounded-xl border border-slate-200 bg-slate-50 p-5">
        <h2 class="text-sm">A note on your documents</h2>
        <p class="mt-1.5 text-sm text-slate-600">
            Uploaded documents are stored privately and are visible only to you and the registrar's office.
            They are never published on this website.
        </p>
    </div>
</div>
