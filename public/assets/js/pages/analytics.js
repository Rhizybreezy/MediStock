/* Analytics charts: each [data-analytics-chart] box has a JSON spec {type, labels, series[], stacked?}. */
(function () {
    'use strict';
    var charts = [];
    function css(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
    var palette = ['#0f766e', '#0284c7', '#d97706', '#7c3aed', '#db2777', '#65a30d', '#475569', '#0891b2', '#b91c1c', '#ca8a04'];
    function render() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        if (!window.Chart) { return; }
        Chart.defaults.color = css('--ms-text-muted');
        document.querySelectorAll('[data-analytics-chart]').forEach(function (box) {
            var specEl = document.querySelector('[data-chart-for="' + box.dataset.analyticsChart + '"]');
            if (!specEl) { return; }
            var spec = JSON.parse(specEl.textContent);
            var empty = !spec.labels || !spec.labels.length || spec.series.every(function (s) { return s.data.every(function (v) { return !v; }); });
            if (empty) {
                box.innerHTML = '<div class="chart-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i><div>No data for this period.</div></div>';
                return;
            }
            var doughnut = spec.type === 'doughnut';
            var datasets = spec.series.map(function (s, i) {
                return {
                    label: s.label, data: s.data,
                    backgroundColor: doughnut ? palette : palette[i % palette.length],
                    borderColor: doughnut ? 0 : palette[i % palette.length],
                    borderWidth: doughnut ? 0 : 2, borderRadius: spec.type === 'bar' ? 3 : 0, tension: 0.3, pointRadius: 2, maxBarThickness: 40
                };
            });
            var opts = { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } } };
            if (!doughnut) {
                opts.scales = {
                    x: { stacked: !!spec.stacked, grid: { display: false }, ticks: { autoSkip: true, maxRotation: 45 } },
                    y: { stacked: !!spec.stacked, beginAtZero: true, grid: { color: css('--ms-border') }, ticks: { callback: function (v) { return MS.number(v, 0); } } }
                };
                opts.interaction = { mode: 'index', intersect: false };
            } else {
                opts.cutout = '60%';
            }
            charts.push(new Chart(box.querySelector('canvas'), { type: spec.type, data: { labels: spec.labels, datasets: datasets }, options: opts }));
        });
    }
    document.addEventListener('DOMContentLoaded', render);
    document.addEventListener('ms:themechange', function () { setTimeout(render, 0); });
})();
