/* Reservation form: single product picker with availability at the chosen location. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('reservation-form');
        if (!form) { return; }
        var wrap = form.querySelector('[data-picker]');
        var input = wrap.querySelector('input[type="search"]');
        var hidden = wrap.querySelector('input[type="hidden"]');
        var hint = form.querySelector('[data-hint]');
        var location = form.querySelector('#location_id');
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
                MS.api('api/products/search?q=' + encodeURIComponent(q) + '&location_id=' + encodeURIComponent(location.value)).then(function (data) {
                    menu.textContent = '';
                    data.forEach(function (p) {
                        var opt = document.createElement('div');
                        opt.className = 'picker-option';
                        opt.textContent = p.name + ' (' + p.code + ')';
                        var d = document.createElement('span');
                        d.className = 'opt-detail';
                        d.textContent = 'Available here: ' + MS.number(p.available || 0, 3) + ' ' + p.unit;
                        opt.appendChild(d);
                        opt.addEventListener('mousedown', function (e) {
                            e.preventDefault();
                            hidden.value = p.id;
                            input.value = p.name;
                            hint.textContent = 'Available here: ' + MS.number(p.available || 0, 3) + ' ' + p.unit;
                            MSLineItems.fillUnits(form.querySelector('#unit_id'), p);
                            menu.hidden = true;
                        });
                        menu.appendChild(opt);
                    });
                    if (!data.length) { menu.innerHTML = '<div class="picker-empty">No products found.</div>'; }
                    menu.hidden = false;
                });
            }, 250);
        });
        input.addEventListener('blur', function () { setTimeout(function () { menu.hidden = true; }, 150); });
        form.addEventListener('submit', function (e) {
            if (!hidden.value) { e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Choose a product from the list.', 'warning'); }
        }, true);
    });
})();
