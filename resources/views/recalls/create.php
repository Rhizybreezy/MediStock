<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('recalls.index')) ?>">Recalls</a></li><li class="breadcrumb-item active" aria-current="page">Start</li></ol></nav>
        <h1>Start batch recall</h1>
        <div class="subtitle">The batch is blocked everywhere as soon as you start the recall, and departments holding it are alerted.</div>
    </div>
</div>
<div class="card" style="max-width:760px"><div class="card-body">
<form method="post" action="<?= e(route('recalls.store')) ?>" id="recall-form" novalidate data-confirm="Start this recall? The batch will be blocked from all issue and use immediately." data-confirm-button="Start recall" data-confirm-variant="danger">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label required" for="batch-search">Batch</label>
        <div class="picker" data-picker>
            <input type="search" id="batch-search" class="form-control" placeholder="Batch code, lot number or product" autocomplete="off" value="<?= $batch ? e($batch['batch_code'] . ' · ' . $batch['product_name'] . ' · lot ' . $batch['lot_number']) : '' ?>">
            <input type="hidden" name="batch_id" value="<?= $batch ? (int) $batch['id'] : '' ?>">
        </div>
        <div class="line-hint" data-hint></div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label required" for="severity">Severity</label><select class="form-select" id="severity" name="severity"><?php foreach (['critical', 'high', 'medium', 'low'] as $s): ?><option value="<?= $s ?>"<?= old('severity', 'high') === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label required" for="source">Initiated by</label><select class="form-select" id="source" name="source"><?php foreach (['manufacturer', 'regulator', 'supplier', 'internal'] as $s): ?><option value="<?= $s ?>"<?= old('source') === $s ? ' selected' : '' ?>><?= e(label($s)) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="mb-3"><label class="form-label required" for="reason">Reason</label><textarea class="form-control<?= invalid('reason') ?>" id="reason" name="reason" rows="3" required minlength="5" maxlength="2000" placeholder="Recall notice reference and defect description"><?= e(old('reason')) ?></textarea></div>
    <div class="form-actions">
        <a href="<?= e(route('recalls.index')) ?>" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-danger"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Start recall</button>
    </div>
</form>
</div></div>
