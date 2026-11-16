/* Purchase order form: product rows, supplier quote prefill, live totals, editing existing drafts. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('po-form');
        if (!form || !window.MSLineItems) { return; }
        var supplier = form.querySelector('#supplier_id');
        var items;
        var initial = [];
        try { initial = JSON.parse(document.getElementById('po-initial').textContent || '[]'); } catch (e) { initial = []; }

        var totals = function () {
            if (!items) { return; }
            var sum = 0;
            items.rows().forEach(function (r) {
                var line = (parseFloat(r.querySelector('[name$="[quantity]"]').value) || 0) * (parseFloat(r.querySelector('[name$="[unit_price]"]').value) || 0);
                r.querySelector('[data-line-total]').textContent = MS.number(line, 2);
                sum += line;
            });
            var tax = parseFloat(form.querySelector('#tax_amount').value) || 0;
            document.getElementById('po-subtotal').textContent = MS.number(sum, 2);
            document.getElementById('po-total').textContent = MS.number(sum + tax, 2);
        };
        var priceFromQuote = function (row) {
            var p = row.product;
            var unitSel = row.querySelector('[name$="[unit_id]"]');
            var opt = unitSel.selectedOptions[0];
            var priceInput = row.querySelector('[name$="[unit_price]"]');
            if (p && p.quote !== undefined && p.quote !== null && opt) {
                priceInput.value = (p.quote * parseFloat(opt.dataset.factor || 1)).toFixed(4).replace(/\.?0+$/, '');
                row.querySelector('[data-hint]').textContent = 'Supplier quote: ' + p.quote + ' per ' + p.unit;
            }
            totals();
        };
        items = MSLineItems.init('po', {
            initial: initial,
            searchParams: function () { return { supplier_id: supplier.value }; },
            onSelect: function (row, product, isPrefill) {
                MSLineItems.fillUnits(row.querySelector('[name$="[unit_id]"]'), product);
                if (!isPrefill) {
                    priceFromQuote(row);
                    row.querySelector('[name$="[quantity]"]').focus();
                }
            },
            onRowChange: function (row) {
                // Changing the order unit re-applies the quote (per base unit × pack factor).
                if (document.activeElement && /\[unit_id\]$/.test(document.activeElement.name || '')) { priceFromQuote(row); }
            },
            onChange: totals
        });
        form.querySelector('#tax_amount').addEventListener('input', totals);
        totals();
        form.addEventListener('submit', function (e) {
            if (!Array.prototype.some.call(items.rows(), function (r) { return !!r.product; })) {
                e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Add at least one product.', 'warning');
            }
        }, true);
    });
})();
