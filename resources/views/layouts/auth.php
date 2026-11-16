<?php /** Layout for sign-in and forced password change. */ ?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base" content="<?= e(rtrim(url('/'), '/')) ?>">
    <title><?= e($title ?? 'Sign in') ?> · <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="auth-shell">
    <aside class="auth-brand" aria-hidden="true">
        <div class="brand-row">
            <img src="<?= e(asset('images/logo.svg')) ?>" alt="">
            <div>
                <div class="fw-bold fs-5 text-white">MediStock Intelligence</div>
                <div class="small text-uppercase" style="letter-spacing:.1em;color:#5eead4">Hospital Inventory</div>
            </div>
        </div>
        <div>
            <h2>Every medicine and supply, accounted for.</h2>
            <p class="mb-4">Batch-level tracking, expiry intelligence and department visibility for your hospital's inventory.</p>
            <div class="auth-feature"><i class="bi bi-upc-scan"></i><span>Batch and expiry tracking with FEFO issuing</span></div>
            <div class="auth-feature"><i class="bi bi-graph-up-arrow"></i><span>Stock-out and expiry risk forecasting</span></div>
            <div class="auth-feature"><i class="bi bi-shield-check"></i><span>Role-based access and a complete audit trail</span></div>
        </div>
        <div class="small" style="color:#7fb5ae">&copy; <?= date('Y') ?> <?= e(setting('hospital_name', 'MediStock Intelligence')) ?></div>
    </aside>
    <main class="auth-form-wrap">
        <div class="auth-form">
            <?= $content ?>
        </div>
    </main>
</div>
<div class="toast-stack" id="toast-stack" aria-live="polite"></div>
<?= partial('partials/flash') ?>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
