<div class="error-page">
    <div class="text-center" style="max-width:520px">
        <div class="error-code"><?= (int) $status ?></div>
        <h1 class="h4 fw-bold mt-2"><?= e($title) ?></h1>
        <p class="text-muted"><?= e($message) ?></p>
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="<?= e(url('/')) ?>" class="btn btn-primary"><i class="bi bi-house me-1" aria-hidden="true"></i>Go to dashboard</a>
            <?php if ($title === 'Session expired'): ?>
                <a href="<?= e(url('/login')) ?>" class="btn btn-outline-secondary">Sign in again</a>
            <?php endif; ?>
        </div>
        <?php if (!empty($trace)): ?>
            <pre class="json-block text-start mt-4"><?= e($trace) ?></pre>
        <?php endif; ?>
    </div>
</div>
