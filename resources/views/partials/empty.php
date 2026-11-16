<div class="empty-state">
    <i class="bi <?= e($icon ?? 'bi-inbox') ?>" aria-hidden="true"></i>
    <div class="empty-title"><?= e($title ?? 'Nothing here yet') ?></div>
    <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
</div>
