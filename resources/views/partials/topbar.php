<?php
$initials = '';
foreach (array_slice(preg_split('/\s+/', trim((string) ($currentUser['full_name'] ?? '?'))), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$roleNames = implode(', ', array_column($currentUser['roles'] ?? [], 'name'));
?>
<header class="topbar">
    <button type="button" class="topbar-btn d-lg-none" data-sidebar-toggle aria-label="Open navigation">
        <i class="bi bi-list fs-5" aria-hidden="true"></i>
    </button>
    <h2 class="page-title"><?= e($pageTitle) ?></h2>

    <div class="ms-auto d-flex align-items-center gap-1">
        <button type="button" class="topbar-btn" data-theme-toggle aria-label="Toggle theme">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>

        <?php if (can('notifications.view')): ?>
        <div class="dropdown">
            <button type="button" class="topbar-btn" id="notif-bell" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="count-badge" id="notif-count" hidden>0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-menu" aria-labelledby="notif-bell">
                <div class="notif-head">
                    <strong class="small">Notifications</strong>
                    <button type="button" class="btn btn-link btn-sm p-0" id="notif-mark-all">Mark all read</button>
                </div>
                <div class="notif-list" id="notif-list">
                    <div class="text-center py-4"><span class="spinner-border spinner-border-sm text-secondary" role="status"><span class="visually-hidden">Loading…</span></span></div>
                </div>
                <div class="notif-foot"><a href="<?= e(route('notifications.index')) ?>">View all notifications</a></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dropdown ms-1">
            <a href="#" class="user-chip" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
                <span class="d-none d-md-block text-start">
                    <span class="user-name d-block"><?= e($currentUser['full_name'] ?? '') ?></span>
                    <span class="user-role d-block"><?= e($roleNames) ?></span>
                </span>
                <i class="bi bi-chevron-down small text-muted d-none d-md-inline" aria-hidden="true"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2 d-md-none">
                    <div class="fw-semibold small"><?= e($currentUser['full_name'] ?? '') ?></div>
                    <div class="text-muted small"><?= e($roleNames) ?></div>
                </li>
                <li><a class="dropdown-item" href="<?= e(route('password.change')) ?>"><i class="bi bi-key me-2" aria-hidden="true"></i>Change password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="<?= e(route('logout')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
