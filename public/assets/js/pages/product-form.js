/* Product form: add/remove pack rows; category pre-selects expiry tracking for new products. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('product-form');
        if (!form) { return; }
        var tbody = form.querySelector('#pack-table tbody');

        var renumber = function () {
            tbody.querySelectorAll('tr[data-row]').forEach(function (row, i) {
                row.querySelectorAll('[name^="packs["]').forEach(function (el) {
                    el.name = el.name.replace(/^packs\[\d+\]/, 'packs[' + i + ']');
                });
            });
        };

        form.querySelector('#add-pack').addEventListener('click', function () {
            var template = tbody.querySelector('tr[data-row]');
            var row = template.cloneNode(true);
            row.querySelectorAll('input, select').forEach(function (el) {
                if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; }
            });
            tbody.appendChild(row);
            renumber();
            row.querySelector('select').focus();
        });

        tbody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-remove-row]');
            if (!btn) { return; }
            var rows = tbody.querySelectorAll('tr[data-row]');
            var row = btn.closest('tr');
            if (rows.length === 1) {
                row.querySelectorAll('input, select').forEach(function (el) { if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; } });
            } else {
                row.remove();
            }
            renumber();
        });

        var isNew = !/\/edit$/.test(window.location.pathname);
        var category = form.querySelector('#category_id');
        category.addEventListener('change', function () {
            if (!isNew) { return; }
            var opt = category.options[category.selectedIndex];
            if (opt && opt.dataset.requiresExpiry !== undefined) {
                form.querySelector('#is_expiry_tracked').checked = opt.dataset.requiresExpiry === '1';
            }
        });
    });
})();
