/*
 * Dashboard chart section. All figures arrive in window.vilocareDashboardCharts (built server-side
 * by App\Services\DashboardChartsService); nothing is hard-coded here. No external plugins are loaded,
 * so the page works offline: data labels and the donut centre text are drawn by the two small plugins below.
 */
(function () {
    'use strict';

    var payload = window.vilocareDashboardCharts;
    if (typeof Chart === 'undefined' || !payload) {
        return;
    }

    var INK = '#243447';
    var MUTED = '#526276';
    var GRID = '#e5edf3';
    var FONT = "'Manrope', sans-serif";

    Chart.defaults.font.family = FONT;
    Chart.defaults.font.size = 12;
    Chart.defaults.color = MUTED;
    Chart.defaults.animation.duration = 300;

    function fmt(n) {
        return Number(n).toLocaleString();
    }

    /** Darker shade of a #rrggbb colour, so value labels stay readable on white. */
    function darken(hex, amount) {
        var n = parseInt(hex.slice(1), 16);
        var f = 1 - amount;
        var r = Math.round(((n >> 16) & 255) * f);
        var g = Math.round(((n >> 8) & 255) * f);
        var b = Math.round((n & 255) * f);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    /** Split a long axis label into lines (Chart.js draws array labels on several lines). */
    function wrap(text, max) {
        var words = String(text).split(' ');
        var lines = [];
        var line = '';
        words.forEach(function (word) {
            if (line && (line + ' ' + word).length > max) {
                lines.push(line);
                line = word;
            } else {
                line = line ? line + ' ' + word : word;
            }
        });
        if (line) {
            lines.push(line);
        }
        return lines;
    }

    /* Value label next to every line point. Labels try above/below the point and never overlap each other. */
    var pointLabels = {
        id: 'pointLabels',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            var area = chart.chartArea;
            var placed = [];
            var h = 13;

            ctx.save();
            ctx.font = '700 11px ' + FONT;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            chart.data.datasets.forEach(function (ds, di) {
                if (!chart.isDatasetVisible(di)) {
                    return;
                }
                var below = ds.labelPosition === 'below';
                chart.getDatasetMeta(di).data.forEach(function (pt, i) {
                    var text = String(ds.data[i]);
                    var w = ctx.measureText(text).width + 4;
                    var dirs = below ? [1, -1] : [-1, 1];
                    var spot = null;

                    for (var round = 0; round < 3 && !spot; round++) {
                        for (var d = 0; d < dirs.length && !spot; d++) {
                            var cy = pt.y + dirs[d] * (11 + round * h);
                            var box = { l: pt.x - w / 2, r: pt.x + w / 2, t: cy - h / 2, b: cy + h / 2 };
                            var inside = box.t >= area.top - 6 && box.b <= area.bottom + 4;
                            var clash = placed.some(function (p) {
                                return box.l < p.r && box.r > p.l && box.t < p.b && box.b > p.t;
                            });
                            if (inside && !clash) {
                                spot = { cx: pt.x, cy: cy, box: box };
                            }
                        }
                    }
                    if (!spot) {
                        return; // no free room: the tooltip still shows the value
                    }
                    placed.push(spot.box);
                    ctx.lineWidth = 3;
                    ctx.strokeStyle = '#ffffff';
                    ctx.strokeText(text, spot.cx, spot.cy);
                    ctx.fillStyle = darken(ds.borderColor, 0.25);
                    ctx.fillText(text, spot.cx, spot.cy);
                });
            });
            ctx.restore();
        }
    };

    /* Value label at the end of each horizontal bar (dataset.valueLabels[i]); inside the bar if there is no room. */
    var barLabels = {
        id: 'barLabels',
        afterDatasetsDraw: function (chart) {
            var ctx = chart.ctx;
            ctx.save();
            ctx.font = '700 12px ' + FONT;
            ctx.textBaseline = 'middle';

            chart.data.datasets.forEach(function (ds, di) {
                chart.getDatasetMeta(di).data.forEach(function (bar, i) {
                    var text = ds.valueLabels[i];
                    var w = ctx.measureText(text).width;
                    if (bar.x + 6 + w <= chart.width - 2) {
                        ctx.textAlign = 'left';
                        ctx.fillStyle = INK;
                        ctx.fillText(text, bar.x + 6, bar.y);
                    } else {
                        ctx.textAlign = 'right';
                        ctx.fillStyle = '#ffffff';
                        ctx.fillText(text, bar.x - 6, bar.y);
                    }
                });
            });
            ctx.restore();
        }
    };

    /* Text in the middle of a donut: opts.lines = [{text, size, weight, color, dy}]. */
    var centerText = {
        id: 'centerText',
        afterDraw: function (chart, args, opts) {
            if (!opts || !opts.lines) {
                return;
            }
            var a = chart.chartArea;
            var cx = (a.left + a.right) / 2;
            var cy = (a.top + a.bottom) / 2;
            var ctx = chart.ctx;
            var maxWidth = (a.right - a.left) * 0.58;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            opts.lines.forEach(function (line) {
                var size = line.size;
                ctx.font = (line.weight || 700) + ' ' + size + 'px ' + FONT;
                while (size > 9 && ctx.measureText(line.text).width > maxWidth) {
                    size -= 1;
                    ctx.font = (line.weight || 700) + ' ' + size + 'px ' + FONT;
                }
                ctx.fillStyle = line.color;
                ctx.fillText(line.text, cx, cy + line.dy);
            });
            ctx.restore();
        }
    };

    var tooltipStyle = {
        backgroundColor: '#243447',
        titleFont: { family: FONT, weight: '700' },
        bodyFont: { family: FONT },
        padding: 10,
        cornerRadius: 6
    };

    // 1. Monthly VL due vs samples collected and tested ------------------------------------------
    (function () {
        var el = document.getElementById('vlActivityChart');
        var line = payload.line;
        if (!el || !line) {
            return;
        }
        var months = line.months;
        var last = months.length - 1;
        var positions = { vl_due: 'above', samples_collected: 'below', results_tested: 'below' };

        new Chart(el, {
            type: 'line',
            data: {
                labels: line.labels,
                datasets: line.series.map(function (s) {
                    return {
                        label: s.label,
                        data: s.values,
                        borderColor: s.color,
                        backgroundColor: s.color,
                        pointBackgroundColor: s.color,
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        borderWidth: 2.5,
                        tension: 0.3,
                        labelPosition: positions[s.key] || 'above',
                        // The last segment (month to date) is dashed: that month is not finished yet.
                        segment: { borderDash: function (c) { return c.p1DataIndex === last ? [6, 4] : undefined; } }
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 6, right: 10 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16, color: INK }
                    },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            title: function (items) {
                                var m = months[items[0].dataIndex];
                                return m.label + (m.is_current ? ' (month to date)' : '');
                            },
                            label: function (c) {
                                return ' ' + c.dataset.label + ': ' + fmt(c.parsed.y);
                            }
                        }
                    })
                },
                scales: {
                    y: { beginAtZero: true, grace: '20%', ticks: { precision: 0 }, grid: { color: GRID }, border: { display: false } },
                    x: { grid: { display: false } }
                }
            },
            plugins: [pointLabels]
        });
    })();

    // Doughnut helper ------------------------------------------------------------------------------
    function donut(el, slices, centerLines, tooltipLabel) {
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: slices.map(function (s) { return s.label; }),
                datasets: [{
                    data: slices.map(function (s) { return s.count; }),
                    backgroundColor: slices.map(function (s) { return s.color; }),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                layout: { padding: 6 },
                plugins: {
                    legend: { display: false },
                    centerText: { lines: centerLines },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: { label: function (c) { return ' ' + tooltipLabel(slices[c.dataIndex]); } }
                    })
                }
            },
            plugins: [centerText]
        });
    }

    // 2. Viral load suppression --------------------------------------------------------------------
    (function () {
        var el = document.getElementById('suppressionChart');
        var s = payload.suppression;
        if (!el || !s) {
            return;
        }
        donut(el, s.slices, [
            { text: s.rate + '%', size: 26, weight: 800, color: INK, dy: -8 },
            { text: 'Suppressed', size: 12, weight: 600, color: MUTED, dy: 14 },
            { text: '(<1,000)', size: 11, weight: 600, color: MUTED, dy: 29 }
        ], function (slice) {
            return slice.label + ': ' + fmt(slice.count) + ' patients (' + slice.percent + '%)';
        });
    })();

    // 4. EAC episode progress ----------------------------------------------------------------------
    (function () {
        var el = document.getElementById('eacChart');
        var e = payload.eac;
        if (!el || !e) {
            return;
        }
        donut(el, e.slices, [
            { text: fmt(e.total), size: 28, weight: 800, color: INK, dy: -8 },
            { text: e.center_label, size: 11, weight: 600, color: MUTED, dy: 16 }
        ], function (slice) {
            return slice.label + ': ' + fmt(slice.count) + ' (' + slice.percent + '%)';
        });
    })();

    // Horizontal bar helper -------------------------------------------------------------------------
    function hbar(el, bars, valueLabel, tooltipLabel) {
        var max = Math.max.apply(null, bars.map(function (b) { return b.count; }).concat([1]));

        new Chart(el, {
            type: 'bar',
            data: {
                labels: bars.map(function (b) { return wrap(b.label, 17); }),
                datasets: [{
                    data: bars.map(function (b) { return b.count; }),
                    valueLabels: bars.map(valueLabel),
                    backgroundColor: bars.map(function (b) { return b.color; }),
                    borderRadius: 4,
                    maxBarThickness: 28
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { right: 8 } },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            title: function (items) { return bars[items[0].dataIndex].label; },
                            label: function (c) { return ' ' + tooltipLabel(bars[c.dataIndex]); }
                        }
                    })
                },
                scales: {
                    x: { beginAtZero: true, suggestedMax: max * 1.55, ticks: { precision: 0, maxTicksLimit: 5 }, grid: { color: GRID }, border: { display: false } },
                    y: { grid: { display: false }, ticks: { color: INK, font: { weight: '600' } } }
                }
            },
            plugins: [barLabels]
        });
    }

    // 3. Today's workload (unit on every bar) -------------------------------------------------------
    (function () {
        var el = document.getElementById('workloadChart');
        var w = payload.workload;
        if (!el || !w) {
            return;
        }
        hbar(el, w.bars, function (b) { return fmt(b.count) + ' ' + b.unit; }, function (b) { return fmt(b.count) + ' ' + b.unit; });
    })();

    // 5. Patients by age & cohort ---------------------------------------------------------------------
    (function () {
        var el = document.getElementById('cohortChart');
        var c = payload.cohorts;
        if (!el || !c) {
            return;
        }
        hbar(el, c.bars, function (b) { return fmt(b.count) + ' (' + b.percent + '%)'; }, function (b) {
            return fmt(b.count) + ' patients (' + b.percent + '% of ' + fmt(c.total) + ')';
        });
    })();
})();
