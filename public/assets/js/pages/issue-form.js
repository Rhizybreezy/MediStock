/* Issue form: shows availability and a live FEFO allocation preview for each line. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('issue-form');
        if (!form || !window.MSLineItems) { return; }
        var source = form.querySelector('#source_location_id');
        var dest = form.querySelector('#destination_location_id'); // absent on the consumption form
        var timers = new WeakMap();

        var preview = function (row) {
            var hint = row.querySelector('[data-hint]');
            var list = row.querySelector('[data-alloc]');
            var batchSelect = row.querySelector('[name$="[batch_id]"]');
            var p = row.product;
            list.textContent = '';
            hint.classList.remove('text-danger');
            if (!p) { hint.textContent = ''; return; }
            var qty = row.querySelector('[name$="[quantity]"]').value;
            var unit = row.querySelector('[name$="[unit_id]"]').value;
            var params = new URLSearchParams({ product_id: p.id, location_id: source.value, quantity: qty || 0, unit_id: unit || 0 });
            MS.api('api/stock/allocation?' + params.toString()).then(function (data) {
                var msgs = ['Available: ' + MS.number(data.available, 3) + ' ' + p.unit];
                var destOpt = dest ? dest.options[dest.selectedIndex] : null;
                if ((p.storage === 'refrigerated' || p.storage === 'frozen') && destOpt && destOpt.value && destOpt.dataset.cold !== '1') {
                    msgs.push('Needs a cold-chain destination.');
                    hint.classList.add('text-danger');
                }
                // Batch dropdown (manual override)
                var chosen = batchSelect.value || row.dataset.wantBatch || '';
                delete row.dataset.wantBatch;
                batchSelect.textContent = '';
                var auto = document.createElement('option');
                auto.value = '';
                auto.textContent = 'Automatic (FEFO)';
                batchSelect.appendChild(auto);
                data.batches.forEach(function (b) {
                    var opt = document.createElement('option');
                    opt.value = b.batch_id;
                    opt.textContent = b.batch_code + ' · exp ' + (b.expiry_date || 'none') + ' · ' + MS.number(b.available, 3);
                    if (String(b.batch_id) === chosen) { opt.selected = true; }
                    batchSelect.appendChild(opt);
                });
                if (data.error && !batchSelect.value) {
                    msgs.push(data.error);
                    hint.classList.add('text-danger');
                } else if (!batchSelect.value) {
                    data.allocation.forEach(function (a) {
                        var li = document.createElement('li');
                        var soon = a.expiry_date && ((new Date(a.expiry_date) - new Date()) / 86400000) <= data.critical_days;
                        li.innerHTML = '<i class="bi bi-arrow-return-right text-muted" aria-hidden="true"></i>';
                        var span = document.createElement('span');
                        span.textContent = MS.number(a.quantity, 3) + ' from ' + a.batch_code + (a.expiry_date ? ' (exp ' + a.expiry_date + ')' : '');
                        if (soon) { span.className = 'text-warning'; }
                        li.appendChild(span);
                        list.appendChild(li);
                    });
                }
                hint.textContent = msgs.join(' · ');
            }).catch(function (err) { hint.textContent = err.message; hint.classList.add('text-danger'); });
        };

        var schedule = function (row) {
            clearTimeout(timers.get(row));
            timers.set(row, setTimeout(function () { preview(row); }, 300));
        };

        var items = MSLineItems.init('issue', {
            searchParams: function () { return { location_id: source.value }; },
            onSelect: function (row, product) {
                MSLineItems.fillUnits(row.querySelector('[name$="[unit_id]"]'), product);
                preview(row);
                row.querySelector('[name$="[quantity]"]').focus();
            },
            onClear: function (row) { preview(row); },
            onRowChange: schedule
        });
        form.addEventListener('input', function (e) {
            var row = e.target.closest('tr[data-row]');
            if (row && e.target.name && /\[quantity\]$/.test(e.target.name)) { schedule(row); }
        });
        source.addEventListener('change', function () { items.rows().forEach(function (r) { r.querySelector('[name$="[batch_id]"]').value = ''; preview(r); }); });
        if (dest) { dest.addEventListener('change', function () { items.rows().forEach(preview); }); }

        // Scanning: product labels add a line; batch labels add a line with that batch selected.
        var addFromScan = function (r) {
            if (r.type === 'unknown') { MS.toast('Code not recognised: ' + r.code, 'warning'); return; }
            if (r.batch && r.batch.blocked) { MS.toast(r.batch.warning || 'This batch cannot be used.', 'danger'); return; }
            if (!r.product.is_active) { MS.toast(r.product.name + ' is inactive.', 'warning'); return; }
            var row = items.prefillNew({ product: r.product, values: {} });
            if (r.batch) { row.dataset.wantBatch = String(r.batch.id); }
            preview(row);
            MS.toast('Added ' + r.product.name + (r.batch ? ' (batch ' + r.batch.code + ')' : ''), 'success', 2500);
        };
        var scanBtn = form.querySelector('[data-scan-add]');
        if (scanBtn && window.MSScanner) {
            scanBtn.addEventListener('click', function () { MSScanner.open({ title: 'Scan to add', continuous: true, onResult: addFromScan }); });
        }
        var scanParam = new URLSearchParams(window.location.search).get('scan');
        if (scanParam && window.MSScanner) { MSScanner.lookup(scanParam).then(addFromScan).catch(function (e) { MS.toast(e.message, 'danger'); }); }

        form.addEventListener('submit', function (e) {
            var ok = Array.prototype.some.call(items.rows(), function (r) { return !!r.product; });
            if (!ok) { e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Add at least one product.', 'warning'); return; }
            if (dest && !dest.value) { e.preventDefault(); e.stopImmediatePropagation(); MS.toast('Choose where to deliver the stock.', 'warning'); }
        }, true);
    });
})();
