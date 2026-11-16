/* Adjustment form: batch list for the chosen location; sign guidance from the reason. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('adjustment-form');
        if (!form) { return; }
        var location = form.querySelector('#location_id');
        var reason = form.querySelector('#reason_code');
        var tbody = form.querySelector('#adj-lines tbody');
        var template = document.getElementById('adj-row');
        var batches = [];

        var help = function () {
            var dir = reason.options[reason.selectedIndex].dataset.direction;
            form.querySelector('#reason-help').textContent = dir === 'out' ? 'Reduces stock: enter negative quantities.' : dir === 'in' ? 'Adds stock: enter positive quantities.' : 'Negative to reduce, positive to add.';
        };
        var fill = function (row) {
            var select = row.querySelector('[data-batch]');
            var current = select.value;
            select.textContent = '';
            var first = document.createElement('option');
            first.value = '';
            first.textContent = batches.length ? 'Select batch…' : (location.value ? 'No stock at this location' : 'Choose a location first');
            select.appendChild(first);
            var onlyExpired = reason.value === 'expired';
            batches.forEach(function (b) {
                if (onlyExpired && !b.expired) { return; }
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.label + (b.expiry ? ' · exp ' + b.expiry : '') + (b.expired ? ' (EXPIRED)' : '') + (b.status !== 'active' ? ' [' + b.status + ']' : '');
                opt.dataset.qty = b.quantity;
                opt.dataset.unit = b.unit;
                if (String(b.id) === current) { opt.selected = true; }
                select.appendChild(opt);
            });
            onhand(row);
        };
        var onhand = function (row) {
            var opt = row.querySelector('[data-batch]').selectedOptions[0];
            row.querySelector('[data-onhand]').textContent = opt && opt.dataset.qty ? MS.number(opt.dataset.qty, 3) + ' ' + opt.dataset.unit : '—';
        };
        var renumber = function () {
            tbody.querySelectorAll('tr[data-row]').forEach(function (row, i) {
                row.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/^lines\[(__i__|\d+)\]/, 'lines[' + i + ']'); });
            });
        };
        var add = function () {
            var row = template.content.firstElementChild.cloneNode(true);
            tbody.appendChild(row);
            row.querySelector('[data-batch]').addEventListener('change', function () { onhand(row); });
            fill(row);
            renumber();
        };
        var load = function () {
            batches = [];
            if (!location.value) { tbody.querySelectorAll('tr').forEach(fill); return; }
            MS.api('api/stock/batches?location_id=' + encodeURIComponent(location.value)).then(function (data) {
                batches = data;
                tbody.querySelectorAll('tr[data-row]').forEach(fill);
            }).catch(function (err) { MS.toast(err.message, 'danger'); });
        };
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('[data-remove]')) {
                e.target.closest('tr').remove();
                if (!tbody.querySelector('tr')) { add(); }
                renumber();
            }
        });
        form.querySelector('#adj-add').addEventListener('click', add);
        location.addEventListener('change', load);
        reason.addEventListener('change', function () { help(); tbody.querySelectorAll('tr[data-row]').forEach(fill); });
        help();
        add();
        if (location.value) { load(); }
    });
})();
