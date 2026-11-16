/* Purchase request form: product rows, running estimated total. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('pr-form');
        if (!form || !window.MSLineItems) { return; }
        var items;
        var total = function () {
            if (!items) { return; }
            var sum = 0;
            items.rows().forEach(function (r) {
                sum += (parseFloat(r.querySelector('[name$="[quantity]"]').value) || 0) * (parseFloat(r.querySelector('[name$="[estimated_unit_cost]"]').value) || 0);
            });
            document.getElementById('pr-total').textContent = MS.number(sum, 2);
        };
        items = MSLineItems.init('pr', {
            onSelect: function (row, product) {
                MSLineItems.fillUnits(row.querySelector('[name$="[unit_id]"]'), product);
                row.querySelector('[data-hint]').textContent = product.detail || '';
                row.querySelector('[name$="[quantity]"]').focus();
            },
            onChange: total
        });
        form.querySelectorAll('button[name="action"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var h = form.querySelector('input[name="action"]') || document.createElement('input');
                h.type = 'hidden'; h.name = 'action'; h.value = btn.value; form.appendChild(h);
            });
        });
        form.addEventListener('submit', function (e) {
            if (!Array.prototype.some.call(items.rows(), function (r) { return !!r.product; })) {
                e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Add at least one product.', 'warning');
            }
        }, true);
    });
})();
