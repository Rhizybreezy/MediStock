<?php
/**
 * A list of work items with optional counts.
 * @var string $title
 * @var string $icon
 * @var array  $items  list of [label, icon, url, count?]
 * @var string $empty  text when there is nothing to do
 */
$open = array_values(array_filter($items, fn ($i) => !array_key_exists('count', $i) || $i['count'] > 0));
?>
<div class="card h-100">
    <div class="card-header"><h3 class="card-title"><i class="bi <?= e($icon) ?> me-1" aria-hidden="true"></i><?= e($title) ?></h3></div>
    <?php if ($items): ?>
    <div class="list-group list-group-flush">
        <?php foreach ($items as $i): $n = $i['count'] ?? null; ?>
            <a class="list-group-item list-group-item-action d-flex align-items-center gap-2<?= $n === 0 ? ' text-muted' : '' ?>" href="<?= e($i['url']) ?>">
                <i class="bi <?= e($i['icon']) ?>" aria-hidden="true"></i>
                <span class="flex-grow-1"><?= e($i['label']) ?></span>
                <?php if ($n !== null): ?><span class="badge rounded-pill <?= $n > 0 ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= number_format($n) ?></span><?php else: ?><i class="bi bi-chevron-right small" aria-hidden="true"></i><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$open && array_key_exists('count', $items[0])): ?><div class="card-body py-2 small text-success border-top"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i><?= e($empty) ?></div><?php endif; ?>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-check2-circle', 'title' => $empty]) ?>
    <?php endif; ?>
</div>
