<?php /** @var App\Core\Paginator $page */ ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Departments</li><li class="breadcrumb-item active" aria-current="page">Reservations</li></ol></nav>
        <h1>Emergency reservations</h1>
        <div class="subtitle">Hold critical items back from routine use (e.g. mass-casualty or theatre reserves). Active reservations are excluded from issuing and consumption.</div>
    </div>
</div>
<?php if ($locations): ?>
<div class="card mb-3"><div class="card-header"><h2 class="card-title">Request a reservation</h2><span class="small text-muted">Only products marked “critical” can be reserved</span></div><div class="card-body">
    <form method="post" action="<?= e(route('reservations.store')) ?>" class="row g-2 align-items-end" id="reservation-form" novalidate>
        <?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label" for="location_id">Location</label>
            <select class="form-select" id="location_id" name="location_id" required>
                <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label" for="res-product">Product</label>
            <div class="picker" data-picker><input type="search" id="res-product" class="form-control" placeholder="Search product…" autocomplete="off"><input type="hidden" name="product_id"></div>
            <div class="line-hint" data-hint></div></div>
        <div class="col-md-1"><label class="form-label" for="quantity">Qty</label><input type="number" min="0" step="any" class="form-control" id="quantity" name="quantity" required></div>
        <div class="col-md-1"><label class="form-label" for="unit_id">Unit</label><select class="form-select" id="unit_id" name="unit_id"><option value="">—</option></select></div>
        <div class="col-md-2"><label class="form-label" for="purpose">Purpose</label><input class="form-control" id="purpose" name="purpose" required minlength="5" maxlength="255" placeholder="e.g. trauma reserve"></div>
        <div class="col-md-1"><label class="form-label" for="valid_until">Until</label><input type="date" class="form-control" id="valid_until" name="valid_until" min="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-1"><button type="submit" class="btn btn-primary w-100">Request</button></div>
    </form>
</div></div>
<?php endif; ?>
<div class="card">
    <form class="filter-bar" method="get">
        <select name="status" class="form-select" data-auto-submit aria-label="Status">
            <option value="">Any status</option>
            <?php foreach (['pending', 'active', 'released', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php if ($page->items): ?>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th scope="col">Product</th><th scope="col">Location</th><th scope="col" class="num">Quantity</th><th scope="col">Purpose</th><th scope="col">Valid until</th><th scope="col">Status</th><th scope="col">Requested</th><th scope="col" class="table-actions"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($page->items as $r): $dept = (int) $r['department_id']; ?>
            <tr>
                <td><?= e($r['product_name']) ?><span class="cell-sub"><?= e($r['product_code']) ?></span></td>
                <td class="small"><?= e($r['location_name']) ?><span class="cell-sub"><?= e($r['department_name']) ?></span></td>
                <td class="num"><?= e(qty($r['quantity'])) ?> <span class="small text-muted"><?= e($r['unit_code']) ?></span></td>
                <td class="small"><?= e($r['purpose']) ?></td>
                <td class="small"><?= $r['valid_until'] ? e(format_date($r['valid_until'])) : 'Until released' ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="small"><?= e($r['reserved_by_name']) ?><span class="cell-sub"><?= e(time_ago($r['created_at'])) ?></span></td>
                <td class="table-actions">
                    <?php if ($r['status'] === 'pending' && can('reservations.approve', $dept) && (int) $r['reserved_by'] !== $uid): ?>
                        <form method="post" action="<?= e(route('reservations.decide', ['id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?><button name="action" value="approve" class="btn btn-sm btn-primary">Approve</button> <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button></form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['pending', 'active'], true) && (can('reservations.manage', $dept) || can('reservations.approve', $dept))): ?>
                        <form method="post" action="<?= e(route('reservations.decide', ['id' => $r['id']])) ?>" class="d-inline" data-confirm="Release this reservation? The stock returns to normal use." data-confirm-button="Release"><?= csrf_field() ?><input type="hidden" name="action" value="release"><button type="submit" class="btn btn-sm btn-outline-secondary">Release</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <?= partial('partials/empty', ['icon' => 'bi-shield-plus', 'title' => 'No reservations']) ?>
    <?php endif; ?>
    <?= partial('partials/pagination', ['page' => $page]) ?>
</div>
