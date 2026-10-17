@php
    $chartPrefix = $chartPrefix ?? 'analytics';
    $monthlyTrend = $monthlyTrend ?? [
        'labels' => [],
        'rates' => [],
        'totals' => [],
        'suppressed' => [],
        'unsuppressed' => [],
        'target' => 95,
    ];
    $cohortBreakdown = $cohortBreakdown ?? [
        'categories' => ['Pediatric (<15 yrs)', 'Adult (15+ yrs)', 'PMTCT (Maternal)'],
        'suppressed' => [0, 0, 0],
        'unsuppressed' => [0, 0, 0],
        'totals' => [0, 0, 0],
        'rates' => [0, 0, 0],
        'overall' => ['suppressed' => 0, 'unsuppressed' => 0, 'total' => 0, 'rate' => 0],
    ];
@endphp

<!-- Visual Analytics & Trend Charts Section -->
<div class="analytics-charts-wrapper" style="margin-bottom: 24px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <h2 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>📈</span> Visual Clinical Analytics & Epidemiological Trends
        </h2>
        <span style="font-size: 0.8rem; color: #64748b;">
            Dynamic trends filtered by selected State, County & Facility
        </span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(440px, 1fr)); gap: 20px;">
        
        <!-- 1. Monthly Viral Load Suppression Rate Trend Chart -->
        <div class="card" style="padding: 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--border); background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                <div>
                    <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <span>📈</span> Viral Load Suppression Rate Trend (Monthly)
                    </h3>
                    <p style="font-size: 0.78rem; color: #64748b; margin: 3px 0 0 0;">
                        Monthly % of suppressed viral load tests (<1,000 c/ml) vs UNAIDS 95% Target
                    </p>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="badge" style="background: #ecfdf5; color: #059669; font-weight: 700; font-size: 0.75rem; border: 1px solid #a7f3d0;">
                        Target: 95%
                    </span>
                </div>
            </div>

            <!-- Chart Canvas Container -->
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="{{ $chartPrefix }}-trend-chart"></canvas>
            </div>

            <!-- Footer summary pill -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #f1f5f9; font-size: 0.78rem; color: #64748b;">
                <span>Benchmark: UNAIDS Fast-Track Third 95</span>
                <span>🟢 Emerald Line = Observed % &bull; 🔵 Dashed Line = 95% Target</span>
            </div>
        </div>

        <!-- 2. Breakdown Chart by Age Cohort (Suppressed vs Unsuppressed) -->
        <div class="card" style="padding: 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--border); background: #ffffff;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px;">
                <div>
                    <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <span>📊</span> Suppression Breakdown by Age Cohort
                    </h3>
                    <p style="font-size: 0.78rem; color: #64748b; margin: 3px 0 0 0;">
                        Suppressed (<1,000 c/ml) vs Unsuppressed (≥1,000 c/ml) patients
                    </p>
                </div>

                <!-- View Toggle: Bar vs Pie -->
                <div style="display: inline-flex; background: #f1f5f9; border-radius: var(--radius-sm); padding: 2px;">
                    <button type="button" id="{{ $chartPrefix }}-btn-bar" onclick="switchCohortChart('{{ $chartPrefix }}', 'bar')" style="border: none; background: #ffffff; color: #0f172a; font-size: 0.72rem; font-weight: 700; padding: 4px 10px; border-radius: 4px; cursor: pointer; box-shadow: var(--shadow-sm);">
                        Bar
                    </button>
                    <button type="button" id="{{ $chartPrefix }}-btn-pie" onclick="switchCohortChart('{{ $chartPrefix }}', 'pie')" style="border: none; background: transparent; color: #64748b; font-size: 0.72rem; font-weight: 600; padding: 4px 10px; border-radius: 4px; cursor: pointer;">
                        Pie
                    </button>
                </div>
            </div>

            <!-- Bar Chart Canvas -->
            <div id="{{ $chartPrefix }}-bar-container" style="position: relative; height: 260px; width: 100%;">
                <canvas id="{{ $chartPrefix }}-cohort-bar-chart"></canvas>
            </div>

            <!-- Pie Chart Canvas (Hidden by default) -->
            <div id="{{ $chartPrefix }}-pie-container" style="position: relative; height: 260px; width: 100%; display: none;">
                <canvas id="{{ $chartPrefix }}-cohort-pie-chart"></canvas>
            </div>

            <!-- Footer summary pill -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #f1f5f9; font-size: 0.78rem;">
                <div style="display: flex; gap: 12px;">
                    <span style="color: #059669; font-weight: 600;">
                        ● Pediatric: {{ $cohortBreakdown['rates'][0] ?? 0 }}% suppressed
                    </span>
                    <span style="color: #0284c7; font-weight: 600;">
                        ● Adult: {{ $cohortBreakdown['rates'][1] ?? 0 }}% suppressed
                    </span>
                </div>
                <span style="color: #64748b;">
                    Total tests: {{ $cohortBreakdown['overall']['total'] ?? 0 }}
                </span>
            </div>
        </div>

    </div>
</div>

<!-- Chart.js Offline script loader & Initializer -->
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
<script>
(function() {
    const trendData = @json($monthlyTrend);
    const cohortData = @json($cohortBreakdown);
    const prefix = '{{ $chartPrefix }}';
    // Narrow screens: legend below the chart and larger axis labels
    const isNarrow = window.matchMedia('(max-width: 767px)').matches;
    const legendTop = isNarrow ? 'bottom' : 'top';
    const tickY = isNarrow ? 12 : 10;

    function initCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not yet loaded, retrying...');
            setTimeout(initCharts, 100);
            return;
        }

        // 1. Line Chart: Monthly Trend
        const trendCanvas = document.getElementById(prefix + '-trend-chart');
        if (trendCanvas) {
            const ctx = trendCanvas.getContext('2d');
            const targetLine = Array(trendData.labels.length).fill(95);

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendData.labels,
                    datasets: [
                        {
                            label: 'Suppression Rate (%)',
                            data: trendData.rates,
                            borderColor: '#059669',
                            backgroundColor: 'rgba(5, 150, 105, 0.12)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 3,
                            pointBackgroundColor: '#059669',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointHitRadius: 10,
                        },
                        {
                            label: 'UNAIDS Target (95%)',
                            data: targetLine,
                            borderColor: '#3b82f6',
                            borderWidth: 2,
                            borderDash: [6, 4],
                            fill: false,
                            pointRadius: 0,
                            pointHoverRadius: 0,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: legendTop,
                            labels: {
                                boxWidth: 12,
                                font: { size: 11, weight: '600' },
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleFont: { size: 12, weight: '700' },
                            bodyFont: { size: 12 },
                            padding: 10,
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    if (context.datasetIndex === 0) {
                                        const idx = context.dataIndex;
                                        const total = trendData.totals[idx] || 0;
                                        const supp = trendData.suppressed[idx] || 0;
                                        const rate = context.parsed.y;
                                        if (total === 0) {
                                            return 'Suppression Rate: No tests recorded';
                                        }
                                        return `Suppression: ${rate}% (${supp}/${total} tests suppressed)`;
                                    }
                                    return `Target: ${context.parsed.y}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 0,
                            max: 100,
                            ticks: {
                                stepSize: 20,
                                callback: function(val) { return val + '%'; },
                                font: { size: tickY },
                                color: '#64748b'
                            },
                            grid: {
                                color: '#f1f5f9'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11, weight: '500' },
                                color: '#475569'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // 2. Bar Chart: Cohort Breakdown (Suppressed vs Unsuppressed)
        const barCanvas = document.getElementById(prefix + '-cohort-bar-chart');
        if (barCanvas) {
            const ctx = barCanvas.getContext('2d');
            window[prefix + 'BarChartInstance'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: cohortData.categories,
                    datasets: [
                        {
                            label: 'Suppressed (<1,000 c/ml)',
                            data: cohortData.suppressed,
                            backgroundColor: '#10b981',
                            borderRadius: 4,
                            barPercentage: 0.65,
                            categoryPercentage: 0.8,
                        },
                        {
                            label: 'Unsuppressed (≥1,000 c/ml)',
                            data: cohortData.unsuppressed,
                            backgroundColor: '#f43f5e',
                            borderRadius: 4,
                            barPercentage: 0.65,
                            categoryPercentage: 0.8,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: legendTop,
                            labels: {
                                boxWidth: 12,
                                font: { size: 11, weight: '600' },
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleFont: { size: 12, weight: '700' },
                            bodyFont: { size: 12 },
                            padding: 10,
                            cornerRadius: 6,
                            callbacks: {
                                afterBody: function(tooltipItems) {
                                    const idx = tooltipItems[0].dataIndex;
                                    const total = cohortData.totals[idx] || 0;
                                    const rate = cohortData.rates[idx] || 0;
                                    return `Total: ${total} patients | Rate: ${rate}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0,
                                font: { size: tickY },
                                color: '#64748b'
                            },
                            grid: {
                                color: '#f1f5f9'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11, weight: '500' },
                                color: '#475569'
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // 3. Pie / Doughnut Chart: Overall & Cohort Suppression Ratio
        const pieCanvas = document.getElementById(prefix + '-cohort-pie-chart');
        if (pieCanvas) {
            const ctx = pieCanvas.getContext('2d');
            const totalSuppressed = cohortData.overall.suppressed || 0;
            const totalUnsuppressed = cohortData.overall.unsuppressed || 0;

            window[prefix + 'PieChartInstance'] = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Suppressed (<1,000 c/ml)', 'Unsuppressed (≥1,000 c/ml)'],
                    datasets: [{
                        data: [totalSuppressed, totalUnsuppressed],
                        backgroundColor: ['#10b981', '#f43f5e'],
                        borderColor: '#ffffff',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                font: { size: 11, weight: '600' },
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            callbacks: {
                                label: function(context) {
                                    const total = totalSuppressed + totalUnsuppressed;
                                    const val = context.parsed;
                                    const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                    return ` ${context.label}: ${val} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }
})();

function switchCohortChart(prefix, type) {
    const barContainer = document.getElementById(prefix + '-bar-container');
    const pieContainer = document.getElementById(prefix + '-pie-container');
    const btnBar = document.getElementById(prefix + '-btn-bar');
    const btnPie = document.getElementById(prefix + '-btn-pie');

    if (!barContainer || !pieContainer || !btnBar || !btnPie) return;

    if (type === 'bar') {
        barContainer.style.display = 'block';
        pieContainer.style.display = 'none';
        btnBar.style.background = '#ffffff';
        btnBar.style.color = '#0f172a';
        btnBar.style.boxShadow = 'var(--shadow-sm)';
        btnPie.style.background = 'transparent';
        btnPie.style.color = '#64748b';
        btnPie.style.boxShadow = 'none';
    } else {
        barContainer.style.display = 'none';
        pieContainer.style.display = 'block';
        btnPie.style.background = '#ffffff';
        btnPie.style.color = '#0f172a';
        btnPie.style.boxShadow = 'var(--shadow-sm)';
        btnBar.style.background = 'transparent';
        btnBar.style.color = '#64748b';
        btnBar.style.boxShadow = 'none';
    }
}
</script>
