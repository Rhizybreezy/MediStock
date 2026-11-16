<?php
/** @var ?array $user */
$isEdit = $user !== null;
$val = fn (string $k, $default = '') => old($k, $user[$k] ?? $default);
$selectedRoles = array_map('intval', (array) old('roles', $userRoleIds));
$selectedDepts = array_map('intval', (array) old('departments', array_keys($userDepts)));
$primaryDept = (int) old('primary_department_id', (int) (array_search(1, $userDepts, true) ?: 0));
$temp = \App\Core\Session::getFlash('temp_password');
$self = $isEdit && (int) $user['id'] === (int) auth()->id();
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('admin.users.index')) ?>">Users</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($user['username']) : 'New' ?></li></ol></nav>
        <h1><?= $isEdit ? e($user['full_name']) : 'New user' ?></h1>
        <?php if ($isEdit): ?><div class="subtitle"><?= status_badge($user['status']) ?> · Last sign-in: <?= e($user['last_login_at'] ? format_date($user['last_login_at'], true) : 'never') ?></div><?php endif; ?>
    </div>
</div>

<?php if ($temp): ?>
<div class="alert alert-success" role="alert">
    <div class="fw-semibold mb-1"><i class="bi bi-key me-1" aria-hidden="true"></i>Temporary password for <?= e($temp['username']) ?></div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <code class="fs-6 px-2 py-1 bg-body rounded border"><?= e($temp['password']) ?></code>
        <span class="small">Give this to the user securely. It is shown only once, and they must change it at sign-in.</span>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="<?= $isEdit ? 'col-xl-8' : 'col-xl-9' ?>">
<div class="card"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('admin.users.update', ['id' => $user['id']]) : route('admin.users.store')) ?>" novalidate id="user-form">
    <?= csrf_field() ?>
    <div class="form-section-title">Account details</div>
    <div class="form-section-desc">Basic identity and sign-in information.</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label required" for="full_name">Full name</label>
            <input class="form-control<?= invalid('full_name') ?>" id="full_name" name="full_name" value="<?= e($val('full_name')) ?>" required maxlength="120">
            <?php if ($m = error('full_name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="username">Username</label>
            <input class="form-control<?= invalid('username') ?>" id="username" name="username" value="<?= e($val('username')) ?>" required maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="off">
            <div class="form-text">Letters, numbers, dot, dash and underscore.</div>
            <?php if ($m = error('username')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="email">Email</label>
            <input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email" value="<?= e($val('email')) ?>" required maxlength="150">
            <?php if ($m = error('email')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control<?= invalid('phone') ?>" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30">
            <?php if ($m = error('phone')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <?php if (!$isEdit): ?>
        <div class="col-md-6">
            <label class="form-label required" for="password">Initial password</label>
            <input type="password" class="form-control<?= invalid('password') ?>" id="password" name="password" required autocomplete="new-password">
            <div class="form-text">At least <?= (int) config('security.password.min_length', 8) ?> characters with letters and numbers.</div>
            <?php if ($m = error('password')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="password_confirmation">Confirm password</label>
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="must_change_password" name="must_change_password" value="1" <?= old('must_change_password', '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="must_change_password">Require the user to change this password at first sign-in</label>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-section">
        <div class="form-section-title required">Roles</div>
        <div class="form-section-desc">Roles grant permissions. <strong>Department-level</strong> roles only see and act on the departments assigned below.</div>
        <?php if ($m = error('roles')): ?><div class="alert alert-danger py-2 small"><?= e($m) ?></div><?php endif; ?>
        <div class="row g-2">
            <?php foreach ($roles as $r): ?>
            <div class="col-md-6">
                <label class="check-card">
                    <input class="form-check-input role-check" type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" data-scope="<?= e($r['scope']) ?>" <?= in_array((int) $r['id'], $selectedRoles, true) ? 'checked' : '' ?>>
                    <span>
                        <span class="check-title d-block"><?= e($r['name']) ?></span>
                        <span class="check-desc"><?= $r['scope'] === 'global' ? 'Hospital-wide access' : 'Limited to assigned departments' ?></span>
                    </span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Departments</div>
        <div class="form-section-desc" id="dept-help">Departments this user belongs to. Required for department-level roles. Mark one as primary.</div>
        <?php if ($m = error('departments')): ?><div class="alert alert-danger py-2 small"><?= e($m) ?></div><?php endif; ?>
        <div class="table-responsive border rounded">
            <table class="table table-sm mb-0">
                <thead><tr><th scope="col" style="width:3rem">Assign</th><th scope="col">Department</th><th scope="col" class="text-center" style="width:6rem">Primary</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $d): $checked = in_array((int) $d['id'], $selectedDepts, true); ?>
                    <tr>
                        <td><input class="form-check-input dept-check" type="checkbox" name="departments[]" value="<?= (int) $d['id'] ?>" id="dept-<?= (int) $d['id'] ?>" <?= $checked ? 'checked' : '' ?>></td>
                        <td><label for="dept-<?= (int) $d['id'] ?>" class="mb-0"><?= e($d['name']) ?> <span class="text-muted small">(<?= e($d['code']) ?>)</span></label></td>
                        <td class="text-center"><input class="form-check-input" type="radio" name="primary_department_id" value="<?= (int) $d['id'] ?>" aria-label="Primary: <?= e($d['name']) ?>" <?= $primaryDept === (int) $d['id'] ? 'checked' : '' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e(route('admin.users.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
    </div>
</form>
</div></div>
</div>

<?php if ($isEdit): ?>
<div class="col-xl-4">
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Account actions</h3></div>
        <div class="card-body d-grid gap-2">
            <form method="post" action="<?= e(route('admin.users.reset_password', ['id' => $user['id']])) ?>"
                  data-confirm="Generate a new temporary password for <?= e($user['username']) ?>? Their current password will stop working." data-confirm-button="Reset password" data-confirm-variant="warning">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-key me-1" aria-hidden="true"></i>Reset password</button>
            </form>
            <?php if (!$self): ?>
                <?php if ($user['status'] === 'active'): ?>
                <form method="post" action="<?= e(route('admin.users.status', ['id' => $user['id']])) ?>"
                      data-confirm="Deactivate <?= e($user['username']) ?>? They will no longer be able to sign in." data-confirm-button="Deactivate" data-confirm-variant="danger">
                    <?= csrf_field() ?><input type="hidden" name="status" value="inactive">
                    <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-person-slash me-1" aria-hidden="true"></i>Deactivate account</button>
                </form>
                <?php else: ?>
                <form method="post" action="<?= e(route('admin.users.status', ['id' => $user['id']])) ?>">
                    <?= csrf_field() ?><input type="hidden" name="status" value="active">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-person-check me-1" aria-hidden="true"></i><?= $user['status'] === 'locked' ? 'Unlock account' : 'Activate account' ?></button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="small text-muted mb-0">You cannot deactivate your own account.</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Details</h3></div>
        <div class="card-body">
            <dl class="dl-grid">
                <dt>Created</dt><dd><?= e(format_date($user['created_at'], true)) ?></dd>
                <dt>Updated</dt><dd><?= e(format_date($user['updated_at'], true)) ?></dd>
                <dt>Password set</dt><dd><?= e($user['password_changed_at'] ? format_date($user['password_changed_at'], true) : '—') ?></dd>
                <dt>Failed sign-ins</dt><dd><?= (int) $user['failed_login_count'] ?></dd>
            </dl>
            <?php if (can('audit.view')): ?>
                <a class="small d-inline-block mt-3" href="<?= e(route('admin.audit.index', [], ['entity_type' => 'user', 'q' => $user['username']])) ?>">View audit history <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
