<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('requests.index')) ?>">Stock requests</a></li><li class="breadcrumb-item active" aria-current="page">New</li></ol></nav>
        <h1>New stock request</h1>
        <div class="subtitle">Ask the central store (or pharmacy) for supplies. A reviewer approves the quantities before anything is issued.</div>
    </div>
</div>
<form method="post" action="<?= e(route('requests.store')) ?>" id="request-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="destination_location_id">Deliver to</label>
            <select class="form-select<?= invalid('destination_location_id') ?>" id="destination_location_id" name="destination_location_id" required>
                <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (string) old('destination_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="supplying_department_id">Request from</label>
            <select class="form-select" id="supplying_department_id" name="supplying_department_id">
                <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (string) old('supplying_department_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label required" for="priority">Priority</label>
            <select class="form-select" id="priority" name="priority">
                <?php foreach (['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'] as $k => $v): ?><option value="<?= $k ?>"<?= old('priority') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><label class="form-label" for="needed_by">Needed by</label><input type="date" class="form-control" id="needed_by" name="needed_by" min="<?= date('Y-m-d') ?>" value="<?= e(old('needed_by')) ?>"></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="1000" value="<?= e(old('notes')) ?>" placeholder="Context for the reviewer (e.g. high patient load this week)"></div>
    </div></div></div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">Items</h2></div>
        <div class="table-responsive" style="overflow:visible">
            <table class="table line-items mb-0" data-line-items="request">
                <thead><tr><th scope="col" class="col-product">Product</th><th scope="col" class="col-qty">Quantity</th><th scope="col" class="col-unit">Unit</th><th scope="col" class="col-remove"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <template id="request-row">
            <tr data-row>
                <td class="col-product"><div class="picker" data-picker><input type="search" class="form-control form-control-sm" placeholder="Search product…" aria-label="Product" autocomplete="off"><input type="hidden" name="lines[__i__][product_id]"></div><div class="line-hint" data-hint></div></td>
                <td data-label="Quantity" class="col-qty"><input type="number" min="0" step="any" class="form-control form-control-sm" name="lines[__i__][quantity]" aria-label="Quantity"></td>
                <td data-label="Unit" class="col-unit"><select class="form-select form-select-sm" name="lines[__i__][unit_id]" aria-label="Unit"><option value="">—</option></select></td>
                <td class="col-remove text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove-line aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
            </tr>
        </template>
        <div class="card-body pt-2 pb-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-line="request"><i class="bi bi-plus" aria-hidden="true"></i> Add item</button></div>
    </div>
    <div class="form-actions">
        <a href="<?= e(route('requests.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">Save draft</button>
        <button type="submit" name="action" value="submit" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit request</button>
    </div>
</form>
