<?php
/**
 * Status timeline built from enrollment_status_history (docs/26 section 5).
 *
 * @var array $history oldest first
 */
use App\Core\Auth;
use App\Models\EnrollmentApplication as Application;

$viewerId = Auth::id();
$entries = array_reverse($history);
?>
<section class="card" aria-labelledby="timeline-heading">
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 id="timeline-heading" class="text-base">Timeline</h2>
        <p class="mt-0.5 text-sm text-brand-gray">Every change to your application, newest first.</p>
    </div>

    <?php if ($entries === []): ?>
        <?= component('empty-state', ['title' => 'No activity yet']) ?>
    <?php else: ?>
        <ol class="px-5 py-5">
            <?php foreach ($entries as $index => $entry): ?>
                <?php
                $actor = (int) $entry['actor_user_id'] === $viewerId
                    ? 'You'
                    : ($entry['actor_role'] === 'Student' ? 'Student' : 'The ' . strtolower((string) $entry['actor_role']) . "'s office");
                $isLast = $index === array_key_last($entries);
                ?>
                <li class="relative flex gap-4 <?= $isLast ? '' : 'pb-6' ?>">
                    <?php if (!$isLast): ?>
                        <span class="absolute left-[7px] top-5 h-full w-0.5 bg-brand-200" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="relative mt-1.5 h-4 w-4 shrink-0 rounded-full border-2 border-white <?= $index === 0 ? 'bg-brand-600 ring-2 ring-brand-200' : 'bg-brand-200' ?>" aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <?= component('status-badge', ['status' => $entry['new_value']]) ?>
                            <time class="text-xs text-brand-gray" datetime="<?= e($entry['created_at']) ?>"><?= e(dt($entry['created_at'])) ?></time>
                        </div>
                        <p class="mt-1.5 text-sm text-slate-700">
                            <?php if ($entry['previous_value'] === null): ?>
                                <?= e($actor) ?> started the application.
                            <?php else: ?>
                                <?= e($actor) ?> moved it from <?= e(strtolower(Application::label($entry['previous_value']))) ?>
                                to <?= e(strtolower(Application::label($entry['new_value']))) ?>.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($entry['remarks'])): ?>
                            <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                <span class="font-semibold">Note:</span> <?= e($entry['remarks']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
