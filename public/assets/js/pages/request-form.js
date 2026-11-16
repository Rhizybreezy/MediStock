/* Stock request form: product rows with unit selection. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('request-form');
        if (!form || !window.MSLineItems) { return; }
        var items = MSLineItems.init('request', {
            onSelect: function (row, product) {
                MSLineItems.fillUnits(row.querySelector('[name$="[unit_id]"]'), product);
                row.querySelector('[data-hint]').textContent = product.detail || '';
                row.querySelector('[name$="[quantity]"]').focus();
            }
        });
        // Keep track of which button submitted (name/value is lost when the submit guard disables buttons).
        form.querySelectorAll('button[name="action"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var hidden = form.querySelector('input[name="action"]') || document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'action';
                hidden.value = btn.value;
                form.appendChild(hidden);
            });
        });
        form.addEventListener('submit', function (e) {
            if (!Array.prototype.some.call(items.rows(), function (r) { return !!r.product; })) {
                e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Add at least one product.', 'warning');
            }
        }, true);
    });
})();
