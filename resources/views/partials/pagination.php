<?php
/** @var App\Core\Paginator $page */
$req = request();
$link = fn (int $n) => url($req->path(), array_merge($req->queryAll(), ['page' => $n]));
$last = $page->lastPage();
$current = $page->page;
$window = array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn ($n) => $n >= 1 && $n <= $last));
sort($window);
?>
<div class="table-footer">
    <div>
        <?php if ($page->total > 0): ?>
            Showing <strong><?= $page->from() ?>–<?= $page->to() ?></strong> of <strong><?= number_format($page->total) ?></strong>
        <?php else: ?>
            No records
        <?php endif; ?>
    </div>
    <?php if ($last > 1): ?>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm">
            <li class="page-item<?= $current <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($link(max(1, $current - 1))) ?>" aria-label="Previous">&lsaquo;</a>
            </li>
            <?php $prev = 0; foreach ($window as $n): ?>
                <?php if ($n - $prev > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item<?= $n === $current ? ' active' : '' ?>">
                    <a class="page-link" href="<?= e($link($n)) ?>"<?= $n === $current ? ' aria-current="page"' : '' ?>><?= $n ?></a>
                </li>
            <?php $prev = $n; endforeach; ?>
            <li class="page-item<?= $current >= $last ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($link(min($last, $current + 1))) ?>" aria-label="Next">&rsaquo;</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
