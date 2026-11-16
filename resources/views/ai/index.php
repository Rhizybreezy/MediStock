<div class="row g-3 ai-page">
    <div class="col-lg-3 d-flex flex-column">
        <div class="card flex-grow-1 d-flex flex-column" style="min-height:0">
            <div class="card-header"><h2 class="card-title"><i class="bi bi-stars me-1 text-primary" aria-hidden="true"></i>Conversations</h2><a class="btn btn-sm btn-primary" href="<?= e(route('ai.index')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> New</a></div>
            <div class="list-group list-group-flush ai-conv-list overflow-auto" id="conv-list">
                <?php foreach ($conversations as $c): ?>
                    <a href="<?= e(route('ai.index', [], ['c' => $c['id']])) ?>" class="list-group-item list-group-item-action<?= (int) $c['id'] === (int) $active ? ' active' : '' ?>">
                        <div class="small fw-semibold text-truncate"><?= e($c['title']) ?></div>
                        <div class="cell-sub"><?= (int) $c['questions'] ?> question(s) · <?= e(time_ago($c['updated_at'])) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$conversations): ?><div class="p-3 small text-muted">No conversations yet.</div><?php endif; ?>
            </div>
            <?php if ($active): ?>
            <div class="card-body border-top">
                <form method="post" action="<?= e(route('ai.conversation.update', ['id' => $active])) ?>" class="input-group input-group-sm mb-2">
                    <?= csrf_field() ?><input class="form-control" name="title" maxlength="150" placeholder="Rename conversation" aria-label="New title" required><button class="btn btn-outline-secondary" type="submit">Rename</button>
                </form>
                <form method="post" action="<?= e(route('ai.conversation.update', ['id' => $active])) ?>" data-confirm="Archive this conversation?" data-confirm-button="Archive"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><button class="btn btn-sm btn-link text-muted p-0" type="submit"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive</button></form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-9 d-flex flex-column">
        <div class="card flex-grow-1 d-flex flex-column" style="min-height:0">
            <div class="card-header">
                <h1 class="card-title fs-6"><?= $active ? e(array_values(array_filter($conversations, fn ($c) => (int) $c['id'] === (int) $active))[0]['title'] ?? 'Conversation') : 'Ask the MediStock assistant' ?></h1>
                <span class="small text-muted d-none d-md-inline">Live data · role-based access · proposals need approval</span>
            </div>
            <?php if (!$available): ?>
                <div class="card-body"><div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>The AI assistant is not available: it is switched off in System settings or no API key is configured on the server.</div></div>
            <?php else: ?>
                <div id="ai-page-chat" class="flex-grow-1 d-flex flex-column" style="min-height:0"></div>
                <script type="application/json" id="ai-page-config"><?= json_encode(['conversation' => $active, 'messages' => $transcript, 'suggestions' => $suggestions, 'page' => route('ai.index')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <?php endif; ?>
        </div>
    </div>
</div>
