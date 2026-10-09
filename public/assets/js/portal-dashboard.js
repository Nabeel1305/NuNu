// Draws the settled-per-day chart from the data-chart attribute (no inline script: CSP).
(function () {
    var el = document.getElementById('settledChart');
    if (!el || !window.Chart) return;
    var d = JSON.parse(el.dataset.chart);
    var css = getComputedStyle(document.documentElement);
    var accent = css.getPropertyValue('--brand-accent').trim() || '#14b8a6';
    Chart.defaults.color = css.getPropertyValue('--text-muted').trim();
    Chart.defaults.borderColor = css.getPropertyValue('--border').trim();
    new Chart(el, {
        type: 'bar',
        data: { labels: d.labels, datasets: [{ label: 'Settled ' + (d.currency || ''), data: d.amounts, backgroundColor: accent, borderRadius: 4, maxBarThickness: 28 }] },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: function (c) { return d.counts[c.dataIndex] + ' payment(s)'; } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v.toLocaleString(); } } }, x: { grid: { display: false } } }
        }
    });
})();
