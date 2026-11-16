<?php
$groupMeta = [
    'general'   => ['General', 'bi-hospital', 'Hospital identity and currency.'],
    'inventory' => ['Inventory rules', 'bi-box-seam', 'Thresholds used for expiry monitoring, forecasting and receiving.'],
    'alerts'    => ['Alerts', 'bi-bell', 'Which automatic notifications are generated.'],
    'ai'        => ['AI assistant', 'bi-stars', 'Usage limits for the Gemini inventory assistant. The API key is configured on the server (.env), never here.'],
];
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Administration</li><li class="breadcrumb-item active" aria-current="page">System settings</li></ol></nav>
        <h1>System settings</h1>
        <div class="subtitle">Changes are recorded in the audit log.</div>
    </div>
</div>
<form method="post" action="<?= e(route('admin.settings.update')) ?>" novalidate class="content-narrow">
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $rows): [$gTitle, $gIcon, $gDesc] = $groupMeta[$group] ?? [label($group), 'bi-gear', '']; ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="card-title"><i class="bi <?= e($gIcon) ?> me-2 text-muted" aria-hidden="true"></i><?= e($gTitle) ?></h2></div>
        <div class="card-body">
            <?php if ($gDesc): ?><p class="small text-muted mt-0"><?= e($gDesc) ?></p><?php endif; ?>
            <div class="row g-3">
            <?php foreach ($rows as $s): $key = $s['setting_key']; $value = old($key, $s['setting_value']); ?>
                <?php if ($s['value_type'] === 'bool'): ?>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= (string) $value === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="s-<?= e($key) ?>"><?= e($s['label']) ?></label>
                            <?php if ($s['description']): ?><div class="form-text"><?= e($s['description']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-md-6">
                        <label class="form-label" for="s-<?= e($key) ?>"><?= e($s['label']) ?></label>
                        <input class="form-control<?= invalid($key) ?>" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>"
                               <?= in_array($s['value_type'], ['int', 'decimal'], true) ? 'type="number" min="0" step="' . ($s['value_type'] === 'int' ? '1' : '0.01') . '"' : 'type="text" maxlength="255"' ?>>
                        <?php if ($m = error($key)): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                        <?php if ($s['description']): ?><div class="form-text"><?= e($s['description']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="d-flex justify-content-end mb-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save settings</button>
    </div>
</form>
