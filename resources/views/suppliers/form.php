<?php $isEdit = $supplier !== null; $val = fn ($k, $d = '') => old($k, $supplier[$k] ?? $d); ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('suppliers.index')) ?>">Suppliers</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? e($supplier['name']) : 'Register' ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
    </div>
</div>
<div class="card content-narrow"><div class="card-body">
<form method="post" action="<?= e($isEdit ? route('suppliers.update', ['id' => $supplier['id']]) : route('suppliers.store')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label required" for="name">Supplier name</label><input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="150"><?php if ($m = error('name')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?></div>
        <div class="col-md-4"><label class="form-label required" for="status">Status</label><select class="form-select" id="status" name="status"><?php foreach (['active', 'inactive', 'blacklisted'] as $s): ?><option value="<?= $s ?>"<?= $val('status', 'active') === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" value="<?= e($val('contact_person')) ?>" maxlength="120"></div>
        <div class="col-md-4"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30"></div>
        <div class="col-md-4"><label class="form-label" for="email">Email</label><input type="email" class="form-control<?= invalid('email') ?>" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="150"><?php if ($m = error('email')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?></div>
        <div class="col-md-8"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" value="<?= e($val('address')) ?>" maxlength="255"></div>
        <div class="col-md-4"><label class="form-label" for="tax_number">Tax / registration no.</label><input class="form-control" id="tax_number" name="tax_number" value="<?= e($val('tax_number')) ?>" maxlength="50"></div>
        <div class="col-md-6"><label class="form-label" for="payment_terms">Payment terms</label><input class="form-control" id="payment_terms" name="payment_terms" value="<?= e($val('payment_terms')) ?>" maxlength="100" placeholder="e.g. 30 days after delivery"></div>
        <div class="col-md-6"><label class="form-label required" for="default_lead_time_days">Typical lead time (days)</label><input type="number" min="0" max="365" class="form-control<?= invalid('default_lead_time_days') ?>" id="default_lead_time_days" name="default_lead_time_days" value="<?= e($val('default_lead_time_days', 14)) ?>"><div class="form-text">Used for expected delivery dates and stock-out forecasting.</div></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e($val('notes')) ?></textarea></div>
    </div>
    <div class="form-actions">
        <a href="<?= e($isEdit ? route('suppliers.show', ['id' => $supplier['id']]) : route('suppliers.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary">Save supplier</button>
    </div>
</form>
</div></div>
