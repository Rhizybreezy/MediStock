/* =====================================================================
   MediStock Intelligence — shared front-end behaviour
   - MS.api(): fetch wrapper (CSRF header, JSON, error handling)
   - MS.toast(): toast notifications
   - Confirmation dialogs for forms marked data-confirm
   - Sidebar (mobile), theme toggle, notification bell
   No inline scripts are used anywhere (strict Content-Security-Policy).
   ===================================================================== */
(function () {
    'use strict';

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    };

    var MS = window.MS = {
        base: meta('app-base'),
        csrf: meta('csrf-token'),

        url: function (path) {
            return MS.base + '/' + String(path).replace(/^\/+/, '');
        },

        /**
         * JSON request. Resolves with `data`; rejects with Error(message) where
         * error.fields holds validation messages when present.
         */
        api: function (path, options) {
            options = options || {};
            var headers = {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': MS.csrf
            };
            if (options.activity) { headers['X-Activity'] = '1'; }
            var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };
            if (options.body !== undefined) {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(options.body);
            }
            return fetch(MS.url(path), init).then(function (res) {
                return res.json().catch(function () { return { ok: false, error: { message: 'Unexpected server response.' } }; })
                    .then(function (payload) {
                        if (res.status === 401) {
                            window.location.href = MS.url('login');
                        }
                        if (!res.ok || !payload.ok) {
                            var err = new Error((payload.error && payload.error.message) || 'Request failed.');
                            err.fields = payload.error && payload.error.fields;
                            err.status = res.status;
                            throw err;
                        }
                        return payload.data;
                    });
            });
        },

        toast: function (message, type, timeout) {
            type = type || 'info';
            var icons = { success: 'bi-check-circle-fill', danger: 'bi-x-octagon-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
            var stack = document.getElementById('toast-stack');
            if (!stack) { return; }
            var toast = document.createElement('div');
            toast.className = 'ms-toast ' + type;
            toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
            var icon = document.createElement('i');
            icon.className = 'bi toast-icon ' + (icons[type] || icons.info);
            icon.setAttribute('aria-hidden', 'true');
            var text = document.createElement('div');
            text.textContent = message;
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.setAttribute('aria-label', 'Close');
            close.addEventListener('click', function () { toast.remove(); });
            toast.appendChild(icon);
            toast.appendChild(text);
            toast.appendChild(close);
            stack.appendChild(toast);
            var ms = timeout === undefined ? (type === 'danger' ? 9000 : 5000) : timeout;
            if (ms > 0) { setTimeout(function () { toast.remove(); }, ms); }
        },

        escape: function (value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        number: function (value, decimals) {
            var n = Number(value || 0);
            return n.toLocaleString(undefined, { minimumFractionDigits: decimals || 0, maximumFractionDigits: decimals === undefined ? 2 : decimals });
        }
    };

    // ---- Flash messages from the server → toasts ---------------------------
    function showFlash() {
        var el = document.getElementById('flash-data');
        if (!el) { return; }
        try {
            JSON.parse(el.textContent || '[]').forEach(function (m) { MS.toast(m[1], m[0]); });
        } catch (e) { /* ignore malformed */ }
    }

    // ---- Confirmation dialog ----------------------------------------------
    function setupConfirm() {
        var modalEl = document.getElementById('confirmModal');
        if (!modalEl || !window.bootstrap) { return; }
        var modal = new bootstrap.Modal(modalEl);
        var pendingForm = null;
        var okBtn = modalEl.querySelector('[data-confirm-ok]');

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches('form[data-confirm]') || form.dataset.confirmed === '1') { return; }
            event.preventDefault();
            pendingForm = form;
            modalEl.querySelector('[data-confirm-title]').textContent = form.dataset.confirmTitle || 'Please confirm';
            modalEl.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
            okBtn.textContent = form.dataset.confirmButton || 'Confirm';
            okBtn.className = 'btn btn-' + (form.dataset.confirmVariant || 'primary');
            modal.show();
        }, true);

        okBtn.addEventListener('click', function () {
            if (!pendingForm) { return; }
            pendingForm.dataset.confirmed = '1';
            modal.hide();
            if (pendingForm.requestSubmit) { pendingForm.requestSubmit(); } else { pendingForm.submit(); }
            pendingForm = null;
        });
    }

    // ---- Prevent accidental double submission -----------------------------
    function setupDoubleSubmitGuard() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (event.defaultPrevented || form.method.toLowerCase() !== 'post') { return; }
            setTimeout(function () {
                form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (btn) {
                    btn.disabled = true;
                    if (!btn.querySelector('.spinner-border')) {
                        var s = document.createElement('span');
                        s.className = 'spinner-border spinner-border-sm ms-2';
                        s.setAttribute('aria-hidden', 'true');
                        btn.appendChild(s);
                    }
                });
            }, 0);
        });
    }

    // ---- Sidebar (mobile) ---------------------------------------------------
    function setupSidebar() {
        var sidebar = document.querySelector('.sidebar');
        var backdrop = document.querySelector('.sidebar-backdrop');
        if (!sidebar) { return; }
        var toggle = function (open) {
            sidebar.classList.toggle('open', open);
            if (backdrop) { backdrop.classList.toggle('show', open); }
        };
        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () { toggle(!sidebar.classList.contains('open')); });
        });
        if (backdrop) { backdrop.addEventListener('click', function () { toggle(false); }); }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggle(false); } });
    }

    // ---- Theme toggle -------------------------------------------------------
    function setupTheme() {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            var sync = function () {
                var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                var icon = btn.querySelector('i');
                if (icon) { icon.className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars'); }
                btn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
            };
            sync();
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                try { localStorage.setItem('ms-theme', next); } catch (e) { /* ignore */ }
                sync();
                document.dispatchEvent(new CustomEvent('ms:themechange', { detail: next }));
            });
        });
    }

    // ---- Notification bell ------------------------------------------------
    function setupNotifications() {
        var bell = document.getElementById('notif-bell');
        if (!bell) { return; }
        var badge = document.getElementById('notif-count');
        var list = document.getElementById('notif-list');
        var markAll = document.getElementById('notif-mark-all');

        var render = function (data) {
            if (data.unread > 0) {
                badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
            bell.setAttribute('aria-label', 'Notifications (' + data.unread + ' unread)');
            list.textContent = '';
            if (!data.items.length) {
                var empty = document.createElement('div');
                empty.className = 'empty-state py-4';
                empty.innerHTML = '<i class="bi bi-bell-slash" aria-hidden="true"></i><p>You have no notifications.</p>';
                list.appendChild(empty);
                return;
            }
            data.items.forEach(function (n) {
                var a = document.createElement('a');
                a.className = 'notif-item' + (n.is_read ? '' : ' unread');
                a.href = n.link || MS.url('notifications');
                a.innerHTML = '<span class="notif-dot" aria-hidden="true"></span><span class="min-w-0">' +
                    '<span class="notif-title d-block">' + MS.escape(n.title) + '</span>' +
                    '<span class="d-block text-muted">' + MS.escape(n.message) + '</span>' +
                    '<span class="notif-time">' + MS.escape(n.ago) + '</span></span>';
                a.addEventListener('click', function (e) {
                    if (n.is_read) { return; }
                    e.preventDefault();
                    MS.api('api/notifications/' + n.id + '/read', { method: 'POST', body: {} })
                        .finally(function () { window.location.href = a.href; });
                });
                list.appendChild(a);
            });
        };

        var load = function () {
            MS.api('api/notifications').then(render).catch(function () { /* silent: bell is non-critical */ });
        };
        load();
        setInterval(load, 60000);
        bell.addEventListener('show.bs.dropdown', load);
        if (markAll) {
            markAll.addEventListener('click', function () {
                MS.api('api/notifications/read-all', { method: 'POST', body: {} }).then(load)
                    .catch(function (err) { MS.toast(err.message, 'danger'); });
            });
        }
    }

    // ---- Auto-submitting filter controls ----------------------------------
    function setupAutoSubmit() {
        document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
            el.addEventListener('change', function () { el.form && el.form.submit(); });
        });
        // Selects that reload the page with ?source=<value> (e.g. issue-from location on a request)
        document.querySelectorAll('[data-reload-source]').forEach(function (el) {
            el.addEventListener('change', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('source', el.value);
                window.location.href = url.toString();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        showFlash();
        setupConfirm();
        setupDoubleSubmitGuard();
        setupSidebar();
        setupTheme();
        setupNotifications();
        setupAutoSubmit();
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
        }
    });
})();
