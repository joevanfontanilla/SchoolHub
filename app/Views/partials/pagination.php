<?php
/**
 * Previous / next pagination that keeps the current filters.
 *
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 * @var string $path    application path, e.g. '/registrar/applications'
 * @var array  $query   current filter values (page is replaced)
 * @var string $noun    'application', 'student', ...
 */
if ($pages <= 1) {
    echo '<p class="px-5 py-3 text-xs text-brand-gray">' . e($total) . ' ' . e($noun . ($total === 1 ? '' : 's')) . '</p>';
    return;
}

$link = static function (int $target) use ($path, $query): string {
    $params = array_filter(array_merge($query, ['page' => $target]), static fn ($v) => $v !== null && $v !== '');
    return url($path) . '?' . http_build_query($params);
};
?>
<nav class="flex items-center justify-between gap-3 border-t border-slate-200 px-5 py-3" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a href="<?= e($link($page - 1)) ?>" class="btn-secondary">Previous</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <p class="text-sm text-slate-600">Page <?= e($page) ?> of <?= e($pages) ?> · <?= e($total) ?> <?= e($noun) ?>s</p>
    <?php if ($page < $pages): ?>
        <a href="<?= e($link($page + 1)) ?>" class="btn-secondary">Next</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</nav>
