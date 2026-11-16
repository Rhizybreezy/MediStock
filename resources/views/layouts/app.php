<?php
/** Main application layout: sidebar + top bar + content. */
$currentUser = user();
$pageTitle = $title ?? 'MediStock';
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(rtrim(url('/'), '/')) ?>">
    <title><?= e($pageTitle) ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="app-shell">
    <?= partial('partials/sidebar') ?>
    <div class="sidebar-backdrop"></div>
    <div class="main">
        <?= partial('partials/topbar', ['pageTitle' => $pageTitle, 'currentUser' => $currentUser]) ?>
        <main id="main-content" class="content" tabindex="-1">
            <?= $content ?>
        </main>
    </div>
</div>

<div class="toast-stack" id="toast-stack" aria-live="polite"></div>
<?= partial('partials/flash') ?>
<?= partial('partials/confirm-modal') ?>
<?php if (can('ai.use') && setting('ai_enabled', true) && config('ai.api_key') && request()->path() !== '/assistant'): ?>
    <?= partial('partials/ai-widget') ?>
<?php endif; ?>

<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (can('ai.use') && setting('ai_enabled', true) && config('ai.api_key') && request()->path() !== '/assistant'): ?>
<script src="<?= e(asset('js/components/ai-chat.js')) ?>"></script>
<script src="<?= e(asset('js/components/ai-widget.js')) ?>"></script>
<?php endif; ?>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
