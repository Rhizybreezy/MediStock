/* Full assistant page: chat bound to the selected conversation. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('ai-page-chat');
        if (!root || !window.MSChat) { return; }
        var cfg = JSON.parse(document.getElementById('ai-page-config').textContent);
        var chat = MSChat.create(root, {
            conversationId: cfg.conversation, suggestions: cfg.suggestions,
            onConversation: function (id) { window.history.replaceState(null, '', cfg.page + '?c=' + id); }
        });
        chat.render(cfg.messages);
        chat.focus();
    });
})();
