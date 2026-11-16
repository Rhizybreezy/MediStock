<?php $minLen = (int) config('security.password.min_length', 8); ?>
<?php if (!$forced): ?>
<div class="page-header"><div><h1>Change password</h1><div class="subtitle">Choose a strong password you don't use elsewhere.</div></div></div>
<div class="card content-narrow" style="max-width:520px"><div class="card-body">
<?php else: ?>
<h1 class="mb-1">Set a new password</h1>
<p class="text-muted mb-4">For security, you must change your temporary password before continuing.</p>
<?php endif; ?>

<form method="post" action="<?= e(route('password.update')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label for="current_password" class="form-label required">Current password</label>
        <input type="password" class="form-control<?= invalid('current_password') ?>" id="current_password" name="current_password" autocomplete="current-password" required>
        <?php if ($m = error('current_password')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
    </div>
    <div class="mb-3">
        <label for="new_password" class="form-label required">New password</label>
        <input type="password" class="form-control<?= invalid('new_password') ?>" id="new_password" name="new_password" autocomplete="new-password" required minlength="<?= $minLen ?>" aria-describedby="pwHelp">
        <div id="pwHelp" class="form-text">At least <?= $minLen ?> characters, including letters and numbers.</div>
        <?php if ($m = error('new_password')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
    </div>
    <div class="mb-4">
        <label for="new_password_confirmation" class="form-label required">Confirm new password</label>
        <input type="password" class="form-control" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn btn-primary<?= $forced ? ' btn-lg w-100' : '' ?>">Update password</button>
</form>

<?php if ($forced): ?>
<form method="post" action="<?= e(route('logout')) ?>" class="mt-3 text-center">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-link btn-sm text-muted">Sign out instead</button>
</form>
<?php else: ?>
</div></div>
<?php endif; ?>
