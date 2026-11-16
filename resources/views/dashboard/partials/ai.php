<?php
/**
 * "Ask the assistant" card with role-specific questions. Clicking a question opens the
 * floating assistant and asks it (handled by ai-widget.js via data-ai-ask).
 * @var string[] $prompts
 */
if (!can('ai.use') || !setting('ai_enabled', true) || !config('ai.api_key')) {
    return;
}
?>
<div class="card h-100 dash-ai">
    <div class="card-header">
        <h3 class="card-title"><i class="bi bi-stars text-primary me-1" aria-hidden="true"></i>Ask the assistant</h3>
        <a class="small" href="<?= e(route('ai.index')) ?>">Open assistant <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <div class="card-body d-flex flex-column gap-2">
        <?php foreach ($prompts as $q): ?>
            <button type="button" class="btn btn-sm btn-light border text-start" data-ai-ask="<?= e($q) ?>"><i class="bi bi-chat-dots me-1 text-muted" aria-hidden="true"></i><?= e($q) ?></button>
        <?php endforeach; ?>
        <div class="small text-muted mt-auto">Answers use live data you are allowed to see. The assistant can only prepare drafts for approval.</div>
    </div>
</div>
