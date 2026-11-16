<div class="row g-3">
    <div class="col-md-4"><label class="form-label required" for="department_id">Requesting department</label>
        <select class="form-select" id="department_id" name="department_id"><?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) old('department_id') === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label required" for="priority">Priority</label>
        <select class="form-select" id="priority" name="priority"><?php foreach (['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'] as $k => $v): ?><option value="<?= $k ?>"<?= old('priority') === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label required" for="justification">Justification</label><input class="form-control<?= invalid('justification') ?>" id="justification" name="justification" required minlength="5" maxlength="2000" value="<?= e(old('justification', $defaultJustification ?? '')) ?>" placeholder="Why is this purchase needed?"><?php if ($m = error('justification')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?></div>
</div>
