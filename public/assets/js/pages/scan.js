/* Scan page: render the lookup result with warnings, stock and quick actions. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var mountEl = document.getElementById('scan-mount');
        if (!mountEl || !window.MSScanner) { return; }
        var cfg = JSON.parse(document.getElementById('scan-config').textContent);
        var out = document.getElementById('scan-result');
        var esc = MS.escape;

        var render = function (r) {
            if (r.type === 'unknown') {
                out.innerHTML = '<div class="alert alert-warning"><i class="bi bi-question-circle me-1" aria-hidden="true"></i><strong>Code not recognised:</strong> ' + esc(r.code) + '<br><span class="small">' + esc(r.message) + '</span></div>';
                return;
            }
            var p = r.product, b = r.batch;
            var html = '<div class="card scan-result"><div class="card-body">';
            if (b && b.warning) {
                html += '<div class="alert ' + (b.blocked ? 'alert-danger' : 'alert-warning') + ' d-flex gap-2 align-items-start"><i class="bi ' + (b.blocked ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill') + ' fs-5" aria-hidden="true"></i><div class="fw-semibold">' + esc(b.warning) + '</div></div>';
            }
            if (!p.is_active) { html += '<div class="alert alert-secondary small">This product is inactive.</div>'; }
            html += '<div class="d-flex gap-3 align-items-start">';
            html += '<img class="code-img" alt="QR code" src="' + esc(b ? MS.url('codes/batch/' + b.id) : MS.url('codes/product/' + p.id)) + '">';
            html += '<div class="min-w-0"><div class="small text-muted">' + (b ? 'Batch' : 'Product') + ' · matched by ' + esc(r.matched_by) + '</div>';
            html += '<h2 class="h5 fw-bold mb-1"><a href="' + esc(p.url) + '">' + esc(p.name) + '</a></h2>';
            html += '<div class="small text-muted mb-2">' + esc(p.code) + (p.detail ? ' · ' + esc(p.detail) : '') + ' · unit ' + esc(p.unit) + '</div>';
            if (b) {
                html += '<dl class="dl-grid small mb-0"><dt>Batch</dt><dd><a href="' + esc(b.url) + '" class="code-chip">' + esc(b.code) + '</a></dd><dt>Lot</dt><dd>' + esc(b.lot) + '</dd>' +
                    '<dt>Expiry</dt><dd>' + (b.expiry ? esc(b.expiry) + (b.days_to_expiry !== null ? ' (' + (b.days_to_expiry < 0 ? 'expired ' + Math.abs(b.days_to_expiry) + ' days ago' : b.days_to_expiry + ' days left') + ')' : '') : 'none') + '</dd>' +
                    '<dt>Status</dt><dd>' + esc(b.status) + '</dd></dl>';
            }
            html += '</div></div>';
            if (r.stock !== null) {
                html += '<h3 class="h6 fw-semibold mt-3">' + (b ? 'Where this batch is' : 'Stock in your departments') + '</h3>';
                if (r.stock.length) {
                    html += '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th scope="col">Location</th><th scope="col" class="num">Usable</th><th scope="col" class="num">On hand</th></tr></thead><tbody>';
                    r.stock.forEach(function (s) {
                        html += '<tr><td>' + esc(s.location) + '<span class="cell-sub">' + esc(s.department) + (s.type === 'quarantine' ? ' · quarantine' : s.type === 'transit' ? ' · in transit' : '') + '</span></td><td class="num">' + MS.number(s.usable, 3) + '</td><td class="num">' + MS.number(s.quantity, 3) + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="small text-muted mb-0">No stock in the locations you can see.</p>';
                }
            }
            var code = encodeURIComponent(b ? 'MSB:' + b.code : 'MSP:' + p.code);
            var actions = [];
            if (cfg.canIssue && !(b && b.blocked)) { actions.push('<a class="btn btn-sm btn-primary" href="' + esc(cfg.urls.issue) + '?scan=' + code + '"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Issue</a>'); }
            if (cfg.canConsume && !(b && b.blocked)) { actions.push('<a class="btn btn-sm btn-outline-primary" href="' + esc(cfg.urls.consume) + '?scan=' + code + '"><i class="bi bi-clipboard-pulse me-1" aria-hidden="true"></i>Record use</a>'); }
            if (cfg.canReceive && !b) { actions.push('<a class="btn btn-sm btn-outline-secondary" href="' + esc(cfg.urls.receive) + '?scan=' + code + '"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receive</a>'); }
            actions.push('<a class="btn btn-sm btn-outline-secondary" href="' + esc(cfg.urls.forecast + p.id) + '"><i class="bi bi-graph-down-arrow me-1" aria-hidden="true"></i>Forecast</a>');
            if (cfg.canPrint) { actions.push('<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="' + esc(cfg.urls.labels) + '?' + (b ? 'batches=' + b.id : 'products=' + p.id) + '"><i class="bi bi-printer me-1" aria-hidden="true"></i>Label</a>'); }
            html += '<div class="d-flex flex-wrap gap-2 mt-3">' + actions.join('') + '</div>';
            html += '</div></div>';
            out.innerHTML = html;
        };

        MSScanner.mount(mountEl, { continuous: true, onResult: render });
    });
})();
