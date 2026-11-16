/* =====================================================================
   Line-item editor with an accessible product picker.
   Markup contract:
     <table data-line-items="name"> <tbody></tbody> </table>
     <template id="name-row"> <tr data-row> ... [data-picker] ... </tr> </template>
     <button data-add-line="name">
   Field names use "lines[__i__][field]" and are renumbered automatically.
   Server-side validation always applies; this only improves data entry.
   ===================================================================== */
(function () {
    'use strict';

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function setupPicker(row, options) {
        var wrap = row.querySelector('[data-picker]');
        if (!wrap) { return; }
        var input = wrap.querySelector('input[type="search"]');
        var hidden = wrap.querySelector('input[type="hidden"]');
        var menu = document.createElement('div');
        menu.className = 'picker-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');
        menu.id = 'picker-' + Math.random().toString(36).slice(2);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', menu.id);
        input.setAttribute('aria-expanded', 'false');
        wrap.appendChild(menu);
        var results = [];
        var active = -1;

        var close = function () { menu.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; };
        var highlight = function (i) {
            var opts = menu.querySelectorAll('.picker-option');
            opts.forEach(function (o, j) { o.classList.toggle('active', j === i); o.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
            active = i;
            if (opts[i]) { opts[i].scrollIntoView({ block: 'nearest' }); }
        };
        var choose = function (product) {
            hidden.value = product.id;
            input.value = product.name + ' (' + product.code + ')';
            row.product = product;
            close();
            if (options.onSelect) { options.onSelect(row, product); }
        };

        var search = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { close(); return; }
            var params = new URLSearchParams({ q: q });
            var extra = options.searchParams ? options.searchParams(row) : {};
            Object.keys(extra).forEach(function (k) { if (extra[k]) { params.set(k, extra[k]); } });
            MS.api('api/products/search?' + params.toString()).then(function (data) {
                results = data;
                menu.textContent = '';
                if (!data.length) {
                    var empty = document.createElement('div');
                    empty.className = 'picker-empty';
                    empty.textContent = 'No active products match "' + q + '".';
                    menu.appendChild(empty);
                } else {
                    data.forEach(function (p, i) {
                        var opt = document.createElement('div');
                        opt.className = 'picker-option';
                        opt.setAttribute('role', 'option');
                        var title = document.createElement('strong');
                        title.textContent = p.name;
                        var code = document.createElement('span');
                        code.className = 'text-muted ms-1';
                        code.textContent = p.code;
                        var detail = document.createElement('span');
                        detail.className = 'opt-detail';
                        var bits = [p.detail, 'Unit: ' + p.unit];
                        if (p.available !== undefined) { bits.push('Available here: ' + MS.number(p.available, 3) + ' ' + p.unit); }
                        detail.textContent = bits.filter(Boolean).join(' · ');
                        opt.appendChild(title);
                        opt.appendChild(code);
                        opt.appendChild(detail);
                        opt.addEventListener('mousedown', function (e) { e.preventDefault(); choose(p); });
                        opt.addEventListener('mouseenter', function () { highlight(i); });
                        menu.appendChild(opt);
                    });
                }
                menu.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                highlight(data.length ? 0 : -1);
            }).catch(function (err) { MS.toast(err.message, 'danger'); });
        }, 250);

        input.addEventListener('input', function () {
            if (hidden.value) {
                hidden.value = '';
                row.product = null;
                if (options.onClear) { options.onClear(row); }
            }
            search();
        });
        input.addEventListener('keydown', function (e) {
            if (menu.hidden) { return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(active + 1, results.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(active - 1, 0)); }
            else if (e.key === 'Enter') { if (active >= 0 && results[active]) { e.preventDefault(); choose(results[active]); } }
            else if (e.key === 'Escape') { close(); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
    }

    function init(name, options) {
        options = options || {};
        var table = document.querySelector('[data-line-items="' + name + '"]');
        var template = document.getElementById(name + '-row');
        if (!table || !template) { return null; }
        var tbody = table.querySelector('tbody');

        var renumber = function () {
            tbody.querySelectorAll('tr[data-row]').forEach(function (row, i) {
                row.querySelectorAll('[name]').forEach(function (el) {
                    el.name = el.name.replace(/^lines\[(__i__|\d+)\]/, 'lines[' + i + ']');
                });
                var num = row.querySelector('[data-line-number]');
                if (num) { num.textContent = i + 1; }
            });
            if (options.onChange) { options.onChange(); }
        };

        var addRow = function () {
            var row = template.content.firstElementChild.cloneNode(true);
            tbody.appendChild(row);
            setupPicker(row, options);
            row.querySelectorAll('input, select').forEach(function (el) {
                el.addEventListener('change', function () { if (options.onRowChange) { options.onRowChange(row); } if (options.onChange) { options.onChange(); } });
                el.addEventListener('input', function () { if (options.onChange) { options.onChange(); } });
            });
            renumber();
            if (options.onAdd) { options.onAdd(row); }
            return row;
        };

        tbody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-remove-line]');
            if (!btn) { return; }
            btn.closest('tr').remove();
            if (!tbody.querySelector('tr[data-row]')) { addRow(); }
            renumber();
        });
        document.querySelectorAll('[data-add-line="' + name + '"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = addRow();
                var input = row.querySelector('[data-picker] input[type="search"]');
                if (input) { input.focus(); }
            });
        });

        // Optional prefill: options.initial = [{product: {...}, values: {quantity: 5, unit_id: 3, ...}}]
        var prefill = function (row, item) {
            var wrap = row.querySelector('[data-picker]');
            wrap.querySelector('input[type="hidden"]').value = item.product.id;
            wrap.querySelector('input[type="search"]').value = item.product.name + ' (' + item.product.code + ')';
            row.product = item.product;
            if (options.onSelect) { options.onSelect(row, item.product, true); }
            Object.keys(item.values || {}).forEach(function (key) {
                var el = row.querySelector('[name$="[' + key + ']"]');
                if (el && item.values[key] !== null && item.values[key] !== undefined) { el.value = item.values[key]; }
            });
        };
        if (options.initial && options.initial.length) {
            options.initial.forEach(function (item) { prefill(addRow(), item); });
        } else {
            addRow();
        }
        if (options.onChange) { options.onChange(); }
        var prefillNew = function (item) {
            var empty = Array.prototype.find.call(tbody.querySelectorAll('tr[data-row]'), function (r) { return !r.product; });
            var row = empty || addRow();
            prefill(row, item);
            return row;
        };
        return { table: table, rows: function () { return tbody.querySelectorAll('tr[data-row]'); }, addRow: addRow, prefillNew: prefillNew };
    }

    /** Fill a unit <select> from product.units (base unit first). */
    function fillUnits(select, product) {
        select.textContent = '';
        product.units.forEach(function (u) {
            var opt = document.createElement('option');
            opt.value = u.unit_id;
            opt.textContent = u.is_base ? u.code : u.code + ' (×' + MS.number(u.factor, 3) + ')';
            opt.dataset.factor = u.factor;
            select.appendChild(opt);
        });
    }

    window.MSLineItems = { init: init, fillUnits: fillUnits };
})();
