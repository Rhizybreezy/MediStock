<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('adjustments.index')) ?>">Adjustments</a></li><li class="breadcrumb-item active" aria-current="page">Request</li></ol></nav>
        <h1>Request stock adjustment</h1>
        <div class="subtitle">Use negative quantities to remove stock and positive quantities to add found stock. Stock is unchanged until approved.</div>
    </div>
</div>
<form method="post" action="<?= e(route('adjustments.store')) ?>" id="adjustment-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="location_id">Location</label>
            <select class="form-select" id="location_id" name="location_id" required>
                <option value="">Select…</option>
                <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (string) old('location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="reason_code">Reason</label>
            <select class="form-select" id="reason_code" name="reason_code">
                <?php foreach ($reasons as $k => [$label, $dir]): ?><option value="<?= e($k) ?>" data-direction="<?= e($dir) ?>"<?= old('reason_code') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text" id="reason-help"></div>
        </div>
        <div class="col-md-4"><label class="form-label required" for="notes">Explanation</label><input class="form-control<?= invalid('notes') ?>" id="notes" name="notes" required minlength="5" maxlength="1000" value="<?= e(old('notes')) ?>" placeholder="What happened?"></div>
    </div></div></div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Batches</h2></div>
        <div class="table-responsive"><table class="table mb-0" id="adj-lines">
            <thead><tr><th scope="col">Batch at this location</th><th scope="col" style="width:9rem">On hand</th><th scope="col" style="width:10rem">Quantity change</th><th scope="col">Line note</th><th scope="col" style="width:2.5rem"><span class="visually-hidden">Remove</span></th></tr></thead>
            <tbody></tbody>
        </table></div>
        <template id="adj-row">
            <tr data-row>
                <td><select class="form-select form-select-sm" name="lines[__i__][batch_id]" aria-label="Batch" data-batch><option value="">Choose a location first</option></select></td>
                <td class="small align-middle" data-onhand>—</td>
                <td><input type="number" step="any" class="form-control form-control-sm" name="lines[__i__][quantity_change]" aria-label="Quantity change" placeholder="-5"></td>
                <td><input class="form-control form-control-sm" name="lines[__i__][reason]" maxlength="255" aria-label="Line note"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="adj-add"><i class="bi bi-plus" aria-hidden="true"></i> Add batch</button></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('adjustments.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for approval</button>
    </div>
</form>
