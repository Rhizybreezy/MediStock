/* =====================================================================
   MSChat — chat UI shared by the floating widget and the assistant page.
   Model replies arrive as SAFE HTML rendered on the server (escaped Markdown
   subset); user text is always inserted with textContent.
     MSChat.create(container, { conversationId, suggestions[], onConversation(id, title), compact })
   ===================================================================== */
(function () {
    'use strict';
    var TOOL_LABELS = {
        get_total_inventory: 'inventory summary', get_low_stock_items: 'low stock', get_out_of_stock_items: 'out of stock',
        get_expiring_batches: 'expiring batches', get_expired_batches: 'expired batches', get_department_stock: 'department stock',
        search_products: 'product search', get_stock_movements: 'stock ledger', get_inventory_value: 'valuation',
        get_consumption_analysis: 'consumption', get_slow_moving_items: 'slow movers', get_supplier_performance: 'suppliers',
        get_stockout_predictions: 'stock-out forecast', get_expiry_predictions: 'expiry forecast', get_anomalies: 'anomalies',
        prepare_purchase_request: 'draft purchase request', prepare_stock_transfer: 'transfer proposal'
    };
    var ESTIMATE_TOOLS = ['get_stockout_predictions', 'get_expiry_predictions', 'get_anomalies'];

    function create(root, opts) {
        opts = opts || {};
        var conversationId = opts.conversationId || null;
        root.classList.add('ai-chat');
        root.innerHTML = '<div class="ai-messages" aria-live="polite"></div><div class="ai-suggestions"></div>' +
            '<form class="ai-input" novalidate><textarea class="form-control" rows="' + (opts.compact ? 1 : 2) + '" maxlength="2000" placeholder="Ask about stock, expiry, consumption, suppliers…" aria-label="Message"></textarea>' +
            '<button class="btn btn-primary" type="submit" aria-label="Send"><i class="bi bi-send" aria-hidden="true"></i></button></form>' +
            '<div class="ai-disclaimer">Answers use live MediStock data. Forecasts are estimates. Proposals always need human approval.</div>';
        var list = root.querySelector('.ai-messages');
        var sugg = root.querySelector('.ai-suggestions');
        var form = root.querySelector('form');
        var input = form.querySelector('textarea');
        var button = form.querySelector('button');
        var busy = false;

        var scroll = function () { list.scrollTop = list.scrollHeight; };
        var addUser = function (text) {
            var d = document.createElement('div');
            d.className = 'ai-msg user';
            d.textContent = text;
            list.appendChild(d);
            scroll();
        };
        var addModel = function (m) {
            var d = document.createElement('div');
            d.className = 'ai-msg model' + (m.error ? ' error' : '');
            d.innerHTML = m.html || '';
            var meta = document.createElement('div');
            meta.className = 'ai-meta';
            var seen = {};
            (m.tools || []).forEach(function (t) {
                var key = t.name + t.status;
                if (seen[key]) { return; }
                seen[key] = true;
                var c = document.createElement('span');
                c.className = 'ai-chip' + (t.status !== 'ok' ? ' ' + t.status : (ESTIMATE_TOOLS.indexOf(t.name) >= 0 ? ' estimate' : ''));
                c.textContent = (t.status === 'ok' ? '' : t.status + ': ') + (TOOL_LABELS[t.name] || t.name) + (ESTIMATE_TOOLS.indexOf(t.name) >= 0 && t.status === 'ok' ? ' (estimate)' : '');
                c.title = 'Data source: ' + t.name;
                meta.appendChild(c);
            });
            if (meta.childNodes.length) { d.appendChild(meta); }
            (m.proposals || []).forEach(function (p) {
                var a = document.createElement('a');
                a.className = 'ai-proposal';
                a.href = p.url;
                a.innerHTML = '<i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>';
                a.appendChild(document.createTextNode((p.type === 'purchase_request' ? 'Draft purchase request ' : 'Transfer proposal ') + p.number + ' — review and approve →'));
                d.appendChild(a);
            });
            list.appendChild(d);
            scroll();
        };
        var showSuggestions = function () {
            sugg.textContent = '';
            if (list.children.length || !(opts.suggestions || []).length) { sugg.hidden = true; return; }
            sugg.hidden = false;
            opts.suggestions.forEach(function (q) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn btn-sm btn-outline-secondary';
                b.textContent = q;
                b.addEventListener('click', function () { send(q); });
                sugg.appendChild(b);
            });
        };
        var send = function (text) {
            text = String(text || '').trim();
            if (!text || busy) { return; }
            busy = true;
            button.disabled = true;
            sugg.hidden = true;
            addUser(text);
            input.value = '';
            var typing = document.createElement('div');
            typing.className = 'ai-typing';
            typing.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Checking MediStock data…';
            list.appendChild(typing);
            scroll();
            MS.api('api/ai/chat', { method: 'POST', body: { conversation_id: conversationId, message: text }, activity: true })
                .then(function (r) {
                    typing.remove();
                    var isNew = conversationId !== r.conversation_id;
                    conversationId = r.conversation_id;
                    addModel({ html: r.reply_html, tools: r.tools, proposals: r.proposals });
                    if (opts.onConversation && isNew) { opts.onConversation(conversationId, r.title); }
                })
                .catch(function (err) {
                    typing.remove();
                    addModel({ html: '<p></p>', error: true });
                    list.lastChild.querySelector('p').textContent = err.message || 'The assistant is unavailable right now.';
                })
                .finally(function () { busy = false; button.disabled = false; input.focus(); });
        };
        form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }
        });

        var api = {
            load: function (id) {
                conversationId = id;
                list.textContent = '';
                if (!id) { showSuggestions(); return Promise.resolve(); }
                return MS.api('api/ai/conversations/' + id).then(function (c) {
                    c.messages.forEach(function (m) { if (m.role === 'user') { addUser(m.text); } else { addModel(m); } });
                    showSuggestions();
                }).catch(function () { conversationId = null; showSuggestions(); });
            },
            render: function (messages) {
                list.textContent = '';
                (messages || []).forEach(function (m) { if (m.role === 'user') { addUser(m.text); } else { addModel(m); } });
                showSuggestions();
            },
            reset: function () { conversationId = null; list.textContent = ''; showSuggestions(); },
            focus: function () { input.focus(); },
            ask: function (text) { send(text); },
            id: function () { return conversationId; }
        };
        showSuggestions();
        return api;
    }
    window.MSChat = { create: create };
})();
