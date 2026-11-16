/* Forecast detail: weekly consumption bars with the current daily-rate line (×7). */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('forecast-data');
        if (!el || !window.Chart) { return; }
        var data = JSON.parse(el.textContent);
        var css = function (n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); };
        Chart.defaults.color = css('--ms-text-muted');
        var datasets = [{ type: 'bar', label: 'Consumed per week', data: data.weeks.map(function (w) { return w.qty; }), backgroundColor: css('--ms-primary'), borderRadius: 3 }];
        if (data.adc) {
            datasets.push({ type: 'line', label: 'Current rate (per week, estimate)', data: data.weeks.map(function () { return data.adc * 7; }), borderColor: css('--ms-warning'), borderDash: [6, 4], pointRadius: 0 });
        }
        new Chart(document.getElementById('chart-forecast'), {
            data: { labels: data.weeks.map(function (w) { return w.label; }), datasets: datasets },
            options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: css('--ms-border') } } } }
        });
    });
})();
