/* Floating AI assistant widget (available on authorised pages). */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var fab = document.getElementById('ai-fab');
        var panel = document.getElementById('ai-panel');
        if (!fab || !panel || !window.MSChat) { return; }
        var cfg = JSON.parse(document.getElementById('ai-widget-config').textContent);
        var stored = null;
        try { stored = sessionStorage.getItem('ms-ai-conv'); } catch (e) { /* ignore */ }
        var chat = MSChat.create(panel.querySelector('[data-ai-chat]'), {
            compact: true, suggestions: cfg.suggestions,
            onConversation: function (id) { try { sessionStorage.setItem('ms-ai-conv', id); } catch (e) { /* ignore */ } }
        });
        var loaded = false;
        var toggle = function (open) {
            panel.hidden = !open;
            fab.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && !loaded) {
                loaded = true;
                chat.load(stored ? parseInt(stored, 10) : null).then(function () { chat.focus(); });
            } else if (open) {
                chat.focus();
            }
        };
        fab.addEventListener('click', function () { toggle(panel.hidden); });
        panel.querySelector('[data-ai-close]').addEventListener('click', function () { toggle(false); fab.focus(); });
        panel.querySelector('[data-ai-new]').addEventListener('click', function () {
            try { sessionStorage.removeItem('ms-ai-conv'); } catch (e) { /* ignore */ }
            chat.reset(); chat.focus();
        });
        panel.querySelector('[data-ai-open]').addEventListener('click', function () {
            window.location.href = cfg.page + (chat.id() ? '?c=' + chat.id() : '');
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { toggle(false); } });
        // Any element with data-ai-ask="question" (e.g. dashboard prompt buttons) opens the widget and asks it.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-ai-ask]');
            if (!btn) { return; }
            e.preventDefault();
            var question = btn.getAttribute('data-ai-ask');
            if (!loaded) {
                loaded = true;
                panel.hidden = false;
                fab.setAttribute('aria-expanded', 'true');
                chat.load(stored ? parseInt(stored, 10) : null).then(function () { chat.ask(question); });
            } else {
                toggle(true);
                chat.ask(question);
            }
        });
    });
})();
