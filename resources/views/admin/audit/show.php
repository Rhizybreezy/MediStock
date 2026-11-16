<?php
$pretty = function (?string $json): string {
    if ($json === null || $json === '') {
        return '—';
    }
    $decoded = json_decode($json, true);
    return $decoded === null ? $json : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
?>
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(route('admin.audit.index')) ?>">Audit log</a></li><li class="breadcrumb-item active" aria-current="page">#<?= (int) $log['id'] ?></li></ol></nav>
        <h1>Audit entry #<?= (int) $log['id'] ?></h1>
    </div>
    <a href="<?= e(route('admin.audit.index')) ?>" class="btn btn-light"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</a>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <dl class="dl-grid">
                <dt>Date &amp; time</dt><dd><?= e(format_date($log['created_at'], true)) ?></dd>
                <dt>User</dt><dd><?= e($log['user_name'] ? $log['user_name'] . ' (' . $log['username'] . ')' : 'System') ?></dd>
                <dt>Action</dt><dd><?= e(label($log['action'])) ?></dd>
                <dt>Record</dt><dd><?= $log['entity_type'] ? e(label($log['entity_type'])) . ($log['entity_id'] ? ' #' . (int) $log['entity_id'] : '') : '—' ?></dd>
                <dt>Description</dt><dd><?= e($log['description'] ?? '—') ?></dd>
                <dt>IP address</dt><dd><?= e($log['ip'] ?? '—') ?></dd>
                <dt>Browser</dt><dd class="small text-muted"><?= e($log['user_agent'] ?? '—') ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header"><h2 class="card-title">Previous values</h2></div><div class="card-body"><pre class="json-block mb-0"><?= e($pretty($log['old_values'])) ?></pre></div></div>
        <div class="card"><div class="card-header"><h2 class="card-title">New values</h2></div><div class="card-body"><pre class="json-block mb-0"><?= e($pretty($log['new_values'])) ?></pre></div></div>
    </div>
</div>
