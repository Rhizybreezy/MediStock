<?php $isReturn = $type === 'return_to_store'; ?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('transfers.index')) ?>">Transfers &amp; returns</a></li><li class="breadcrumb-item active" aria-current="page"><?= $isReturn ? 'Return to store' : 'New transfer' ?></li></ol></nav>
        <h1><?= e($title) ?></h1>
        <div class="subtitle"><?= $isReturn ? 'Send unused or excess stock back to the central store.' : 'Move stock to another department or location.' ?> A different authorised person approves before it is dispatched.</div>
    </div>
</div>
<form method="post" action="<?= e(route('transfers.store')) ?>" id="issue-form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="source_location_id">From</label>
            <select class="form-select" id="source_location_id" name="source_location_id" required>
                <?php $dept = null; foreach ($sources as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (string) old('source_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="destination_location_id">To</label>
            <select class="form-select" id="destination_location_id" name="destination_location_id" required>
                <option value="">Select…</option>
                <?php $dept = null; foreach ($destinations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>" data-cold="<?= (int) $l['is_cold_chain'] ?>"<?= (string) old('destination_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?><?= (int) $l['is_cold_chain'] ? ' ❄' : '' ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="reason">Reason</label>
            <input class="form-control" id="reason" name="reason" required maxlength="255" value="<?= e(old('reason')) ?>" placeholder="<?= $isReturn ? 'e.g. overstock, ward closed' : 'e.g. shortage in ICU' ?>">
        </div>
    </div></div></div>
    <?= partial('transfers/_lines') ?>
    <div class="form-actions">
        <a href="<?= e(route('transfers.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for approval</button>
    </div>
</form>
