/* Stock count: scanning a batch label jumps to its row so the counter can type the quantity. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.querySelector('[data-count-scan]');
        if (!btn || !window.MSScanner) { return; }
        btn.addEventListener('click', function () {
            MSScanner.open({
                title: 'Scan a batch label', continuous: false,
                onResult: function (r) {
                    if (r.type !== 'batch') {
                        MS.toast(r.type === 'product' ? 'That is a product label; scan the batch label to count a specific batch.' : 'Code not recognised.', 'warning');
                        return;
                    }
                    var input = document.querySelector('input[data-batch-code="' + CSS.escape(r.batch.code) + '"]');
                    if (!input) {
                        MS.toast('Batch ' + r.batch.code + ' is not recorded at this location. Count it separately and report it as found stock.', 'warning', 8000);
                        return;
                    }
                    document.querySelectorAll('.count-highlight').forEach(function (el) { el.classList.remove('count-highlight'); });
                    var row = input.closest('tr');
                    row.classList.add('count-highlight');
                    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    MS.toast(r.product.name + ' · ' + r.batch.code + ': enter the counted quantity.', 'info', 3000);
                    setTimeout(function () { input.focus(); input.select(); }, 650); // after the modal has closed
                }
            });
        });
    });
})();
