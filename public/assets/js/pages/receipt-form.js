/* Receive stock form: product picker rows, cold-chain hint, expiry hint and running total. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('receipt-form');
        if (!form || !window.MSLineItems) { return; }
        var location = form.querySelector('#receiving_location_id');
        var today = new Date().toISOString().slice(0, 10);

        var hint = function (row) {
            var el = row.querySelector('[data-hint]');
            var p = row.product;
            el.classList.remove('text-danger');
            if (!p) { el.textContent = ''; return; }
            var msgs = [];
            var cold = location.options[location.selectedIndex] && location.options[location.selectedIndex].dataset.cold === '1';
            if ((p.storage === 'refrigerated' || p.storage === 'frozen') && !cold) {
                msgs.push('Needs cold storage: choose a cold-chain location.');
                el.classList.add('text-danger');
            }
            var expiry = row.querySelector('[name$="[expiry_date]"]').value;
            if (p.expiry && !expiry) { msgs.push('Expiry date required.'); }
            if (expiry && expiry <= today) { msgs.push('This lot is expired and cannot be received.'); el.classList.add('text-danger'); }
            if (p.batch_tracked) { msgs.push('Lot number required.'); }
            el.textContent = msgs.join(' ');
        };

        var total = function () {
            var sum = 0, lines = 0;
            items.rows().forEach(function (row) {
                var q = parseFloat(row.querySelector('[name$="[quantity]"]').value) || 0;
                var c = parseFloat(row.querySelector('[name$="[unit_cost]"]').value) || 0;
                if (row.product) { lines++; }
                sum += q * c;
            });
            document.getElementById('receipt-total').textContent = MS.number(sum, 2);
            document.getElementById('line-count').textContent = lines;
        };

        var items = MSLineItems.init('receipt', {
            onSelect: function (row, product) {
                MSLineItems.fillUnits(row.querySelector('[name$="[unit_id]"]'), product);
                var expiry = row.querySelector('[name$="[expiry_date]"]');
                expiry.required = product.expiry;
                hint(row);
                row.querySelector('[name$="[lot_number]"]').focus();
            },
            onClear: function (row) { hint(row); },
            onRowChange: hint,
            onChange: function () { if (items) { total(); } }
        });
        location.addEventListener('change', function () { items.rows().forEach(hint); });

        // Scanning: manufacturer barcodes / product labels add a line; a batch label also fills lot and expiry.
        var addFromScan = function (r) {
            if (r.type === 'unknown') { MS.toast('Code not recognised: ' + r.code + '. Register the barcode on the product (pack units) or search manually.', 'warning'); return; }
            if (!r.product.is_active) { MS.toast(r.product.name + ' is inactive.', 'warning'); return; }
            var values = {};
            if (r.batch) {
                if (r.batch.blocked) { MS.toast(r.batch.warning, 'danger'); return; }
                values.lot_number = r.batch.lot;
                if (r.batch.expiry) { values.expiry_date = r.batch.expiry; }
            }
            var row = items.prefillNew({ product: r.product, values: values });
            hint(row);
            MS.toast('Added ' + r.product.name, 'success', 2500);
        };
        var scanBtn = form.querySelector('[data-scan-add]');
        if (scanBtn && window.MSScanner) {
            scanBtn.addEventListener('click', function () { MSScanner.open({ title: 'Scan to add', continuous: true, onResult: addFromScan }); });
        }
        var scanParam = new URLSearchParams(window.location.search).get('scan');
        if (scanParam && window.MSScanner) { MSScanner.lookup(scanParam).then(addFromScan).catch(function (e) { MS.toast(e.message, 'danger'); }); }

        form.addEventListener('submit', function (e) {
            var hasLine = Array.prototype.some.call(items.rows(), function (r) { return !!r.product; });
            if (!hasLine) {
                e.preventDefault();
                e.stopImmediatePropagation();
                MS.toast('Add at least one product.', 'warning');
            }
        }, true);
    });
})();
