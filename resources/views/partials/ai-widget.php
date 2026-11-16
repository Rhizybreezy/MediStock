<button type="button" class="ai-fab" id="ai-fab" aria-controls="ai-panel" aria-expanded="false" aria-label="Open AI inventory assistant" title="AI inventory assistant"><i class="bi bi-stars" aria-hidden="true"></i></button>
<section class="ai-panel" id="ai-panel" hidden aria-label="AI inventory assistant">
    <div class="ai-head">
        <i class="bi bi-stars text-primary" aria-hidden="true"></i><span class="ai-title">MediStock assistant</span>
        <button type="button" class="btn btn-sm btn-link ms-auto" data-ai-new title="New conversation"><i class="bi bi-plus-lg" aria-hidden="true"></i><span class="visually-hidden">New conversation</span></button>
        <button type="button" class="btn btn-sm btn-link" data-ai-open title="Open full assistant"><i class="bi bi-arrows-angle-expand" aria-hidden="true"></i><span class="visually-hidden">Open full assistant</span></button>
        <button type="button" class="btn-close" data-ai-close aria-label="Close assistant"></button>
    </div>
    <div data-ai-chat class="flex-grow-1 d-flex flex-column" style="min-height:0"></div>
</section>
<script type="application/json" id="ai-widget-config"><?= json_encode(['suggestions' => array_slice(\App\Controllers\Ai\AssistantController::suggestions(), 0, 4), 'page' => route('ai.index')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
