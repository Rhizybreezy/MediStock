/* Dashboard charts — data comes from GET /api/dashboard/charts (live MySQL figures). */
(function () {
    'use strict';
    var charts = [];

    function css(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function palette() {
        return {
            primary: css('--ms-primary'), accent: css('--ms-accent'), danger: css('--ms-danger'),
            text: css('--ms-text-muted'), grid: css('--ms-border'),
            series: ['#0f766e', '#0284c7', '#d97706', '#7c3aed', '#db2777', '#65a30d', '#475569', '#0891b2']
        };
    }

    /** Role dashboards show different charts; only draw the ones present on the page. */
    function has(key) {
        return !!document.querySelector('[data-chart="' + key + '"] canvas');
    }

    function isEmpty(values) {
        return !values.length || values.every(function (v) { return Number(v) === 0; });
    }

    function showEmpty(key, text) {
        var box = document.querySelector('[data-chart="' + key + '"]');
        if (!box) { return; }
        box.querySelector('canvas').hidden = true;
        var div = document.createElement('div');
        div.className = 'chart-empty';
        div.innerHTML = '<i class="bi bi-bar-chart" aria-hidden="true"></i>';
        var p = document.createElement('div');
        p.textContent = text;
        div.appendChild(p);
        box.appendChild(div);
    }

    function render(data) {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        document.querySelectorAll('.chart-empty').forEach(function (el) { el.remove(); });
        document.querySelectorAll('[data-chart] canvas').forEach(function (c) { c.hidden = false; });

        var p = palette();
        Chart.defaults.color = p.text;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        var fmt = function (v) { return MS.number(v, data.metric === 'value' ? 0 : 0); };
        var scales = {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: p.grid }, ticks: { callback: fmt } }
        };

        if (!has('consumption')) {
            /* not on this dashboard */
        } else if (isEmpty(data.consumption)) {
            showEmpty('consumption', 'No consumption recorded in the last 12 months.');
        } else {
            charts.push(new Chart(document.getElementById('chart-consumption'), {
                type: 'bar',
                data: { labels: data.labels, datasets: [{ label: 'Consumption', data: data.consumption, backgroundColor: p.primary, borderRadius: 4, maxBarThickness: 34 }] },
                options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: scales }
            }));
        }

        if (!has('categories')) {
            /* not on this dashboard */
        } else if (isEmpty(data.categories.data)) {
            showEmpty('categories', 'No stock on hand yet.');
        } else {
            charts.push(new Chart(document.getElementById('chart-categories'), {
                type: 'doughnut',
                data: { labels: data.categories.labels, datasets: [{ data: data.categories.data, backgroundColor: p.series, borderWidth: 0 }] },
                options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } } } }
            }));
        }

        if (!has('flow')) {
            /* not on this dashboard */
        } else if (isEmpty(data.inbound) && isEmpty(data.outbound)) {
            showEmpty('flow', 'No stock received or used in the last 12 months.');
        } else {
            charts.push(new Chart(document.getElementById('chart-flow'), {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        { label: 'Stock in', data: data.inbound, borderColor: p.primary, backgroundColor: p.primary, tension: 0.3, pointRadius: 2 },
                        { label: 'Stock out', data: data.outbound, borderColor: p.danger, backgroundColor: p.danger, tension: 0.3, pointRadius: 2 }
                    ]
                },
                options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, scales: scales }
            }));
        }
    }

    var cached = null;
    document.addEventListener('DOMContentLoaded', function () {
        if (!document.querySelector('[data-chart]')) { return; }
        MS.api('api/dashboard/charts').then(function (data) {
            cached = data;
            render(data);
        }).catch(function (err) {
            ['consumption', 'categories', 'flow'].forEach(function (k) { showEmpty(k, 'Could not load chart data.'); });
            MS.toast(err.message, 'danger');
        });
    });
    document.addEventListener('ms:themechange', function () { if (cached) { render(cached); } });
})();
