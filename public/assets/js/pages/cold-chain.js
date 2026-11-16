/* Cold chain: temperature chart with allowed range lines; live out-of-range hint on the form. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var css = function (n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); };
        var el = document.getElementById('temp-data');
        if (el && window.Chart) {
            var data = JSON.parse(el.textContent);
            var box = document.getElementById('chart-temp').parentNode;
            if (!data.series.length) {
                box.innerHTML = '<div class="chart-empty"><i class="bi bi-thermometer" aria-hidden="true"></i><div>No readings in the last 14 days.</div></div>';
            } else {
                Chart.defaults.color = css('--ms-text-muted');
                var labels = data.series.map(function (r) { return r.recorded_at.slice(5, 16); });
                var ds = [{ label: 'Temperature °C', data: data.series.map(function (r) { return Number(r.temperature_c); }), borderColor: css('--ms-accent'), tension: 0.25,
                    pointBackgroundColor: data.series.map(function (r) { return Number(r.is_out_of_range) ? css('--ms-danger') : css('--ms-accent'); }), pointRadius: 3 }];
                if (data.min !== null) { ds.push({ label: 'Min', data: labels.map(function () { return Number(data.min); }), borderColor: css('--ms-danger'), borderDash: [5, 4], pointRadius: 0 }); }
                if (data.max !== null) { ds.push({ label: 'Max', data: labels.map(function () { return Number(data.max); }), borderColor: css('--ms-danger'), borderDash: [5, 4], pointRadius: 0 }); }
                new Chart(document.getElementById('chart-temp'), { type: 'line', data: { labels: labels, datasets: ds },
                    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, scales: { x: { grid: { display: false } }, y: { grid: { color: css('--ms-border') } } } } });
            }
        }
        var loc = document.getElementById('location_id');
        var temp = document.getElementById('temperature_c');
        var hint = document.getElementById('range-hint');
        if (loc && temp && hint) {
            var check = function () {
                var opt = loc.selectedOptions[0];
                var v = parseFloat(temp.value);
                if (isNaN(v) || !opt) { hint.textContent = ''; return; }
                var out = (opt.dataset.min !== '' && v < parseFloat(opt.dataset.min)) || (opt.dataset.max !== '' && v > parseFloat(opt.dataset.max));
                hint.textContent = out ? 'Out of range (' + opt.dataset.min + '–' + opt.dataset.max + ' °C): describe the corrective action.' : 'Within range.';
                hint.classList.toggle('text-danger', out);
                document.getElementById('corrective_action').required = out;
            };
            temp.addEventListener('input', check);
            loc.addEventListener('change', check);
        }
    });
})();
