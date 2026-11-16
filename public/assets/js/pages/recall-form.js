/* Recall form: batch search picker. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('recall-form');
        if (!form) { return; }
        var wrap = form.querySelector('[data-picker]');
        var input = wrap.querySelector('input[type="search"]');
        var hidden = wrap.querySelector('input[type="hidden"]');
        var hint = form.querySelector('[data-hint]');
        var menu = document.createElement('div');
        menu.className = 'picker-menu';
        menu.hidden = true;
        wrap.appendChild(menu);
        var timer;
        input.addEventListener('input', function () {
            hidden.value = '';
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) { menu.hidden = true; return; }
            timer = setTimeout(function () {
                MS.api('api/batches/search?q=' + encodeURIComponent(q)).then(function (data) {
                    menu.textContent = '';
                    data.forEach(function (b) {
                        var opt = document.createElement('div');
                        opt.className = 'picker-option';
                        opt.textContent = b.label;
                        var d = document.createElement('span');
                        d.className = 'opt-detail';
                        d.textContent = 'On hand: ' + MS.number(b.on_hand, 3) + (b.expiry ? ' · exp ' + b.expiry : '') + ' · ' + b.status;
                        opt.appendChild(d);
                        opt.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            hidden.value = b.id;
                            input.value = b.label;
                            hint.textContent = d.textContent;
                            menu.hidden = true;
                        });
                        menu.appendChild(opt);
                    });
                    if (!data.length) { menu.innerHTML = '<div class="picker-empty">No batches found.</div>'; }
                    menu.hidden = false;
                });
            }, 250);
        });
        input.addEventListener('blur', function () { setTimeout(function () { menu.hidden = true; }, 150); });
        form.addEventListener('submit', function (e) {
            if (!hidden.value) { e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Choose a batch from the list.', 'warning'); }
        }, true);
    });
})();
