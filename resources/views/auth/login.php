<div class="d-lg-none d-flex align-items-center gap-2 mb-4">
    <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="40" height="40">
    <div class="fw-bold">MediStock Intelligence</div>
</div>
<h1 class="mb-1">Sign in</h1>
<p class="text-muted mb-4">Use your hospital account to continue.</p>

<form method="post" action="<?= e(route('login.attempt')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="login" class="form-label">Username or email</label>
        <input type="text" class="form-control form-control-lg<?= invalid('login') ?>" id="login" name="login"
               value="<?= e(old('login')) ?>" autocomplete="username" required autofocus maxlength="150">
        <?php if ($m = error('login')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
    </div>
    <div class="mb-4">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control form-control-lg<?= invalid('password') ?>" id="password" name="password"
               autocomplete="current-password" required maxlength="200">
        <?php if ($m = error('password')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
</form>
<p class="text-muted small mt-4 mb-0"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Forgotten your password? Ask your system administrator to reset it.</p>
