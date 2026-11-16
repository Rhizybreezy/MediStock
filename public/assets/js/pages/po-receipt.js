/* PO receipt: duplicate a line for an additional lot of the same item. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('po-receipt-lines');
        if (!table) { return; }
        var renumber = function () {
            table.querySelectorAll('tbody tr[data-row]').forEach(function (row, i) {
                row.querySelectorAll('[name^="lines["]').forEach(function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); });
            });
        };
        table.addEventListener('click', function (e) {
            var add = e.target.closest('[data-add-lot]');
            var remove = e.target.closest('[data-remove-lot]');
            if (add) {
                var row = add.closest('tr');
                var copy = row.cloneNode(true);
                copy.querySelectorAll('input:not([type="hidden"])').forEach(function (el) { el.value = ''; });
                copy.cells[0].querySelector('.cell-sub').textContent = 'Additional lot';
                copy.cells[1].textContent = '';
                var btn = copy.querySelector('[data-add-lot]');
                btn.textContent = 'remove';
                btn.removeAttribute('data-add-lot');
                btn.setAttribute('data-remove-lot', '');
                btn.className = 'btn btn-sm btn-link text-danger';
                btn.setAttribute('aria-label', 'Remove this lot');
                row.after(copy);
                renumber();
                copy.querySelector('[name$="[lot_number]"]').focus();
            } else if (remove) {
                remove.closest('tr').remove();
                renumber();
            }
        });
    });
})();
