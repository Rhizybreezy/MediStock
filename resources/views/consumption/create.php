<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('consumption.index')) ?>">Consumption</a></li><li class="breadcrumb-item active" aria-current="page">Record</li></ol></nav>
        <h1>Record consumption</h1>
        <div class="subtitle">Record supplies used or dispensed. Do not enter patient names or identifiers.</div>
    </div>
</div>
<form method="post" action="<?= e(route('consumption.store')) ?>" id="issue-form" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="source_location_id">Taken from</label>
            <select class="form-select" id="source_location_id" name="source_location_id" required>
                <?php $dept = null; foreach ($locations as $l): if ($dept !== $l['department_name']): if ($dept !== null): ?></optgroup><?php endif; $dept = $l['department_name']; ?><optgroup label="<?= e($dept) ?>"><?php endif; ?>
                    <option value="<?= (int) $l['id'] ?>"<?= (string) old('source_location_id') === (string) $l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; if ($dept !== null): ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="purpose">Purpose</label>
            <select class="form-select" id="purpose" name="purpose">
                <?php foreach ($purposes as $k => $v): ?><option value="<?= e($k) ?>"<?= old('purpose') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="500" value="<?= e(old('notes')) ?>" placeholder="e.g. morning ward round"></div>
    </div></div></div>
    <?= partial('transfers/_lines') ?>
    <div class="form-actions">
        <a href="<?= e(route('consumption.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Record consumption</button>
    </div>
</form>
