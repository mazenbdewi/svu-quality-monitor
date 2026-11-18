    <script>
        window.historicalSpcChart = (key) => ({
            key,
            instance: null,
            init() {
                const render = (payload) => {
                    const chart = this.$wire.charts?.[this.key];

                    if (! chart || ! window.Chart || ! this.$refs.canvas) {
                        return;
                    }

                    if (this.instance) {
                        this.instance.destroy();
                    }

                    const points = JSON.parse(payload || '{}')[this.key] || [];
                    const labels = points.map((point) => point.label);
                    const line = (field, fallback) => points.map((point) => point[field] ?? fallback ?? null);
                    const actual = points.map((point) => point.value ?? null);
                    const colors = points.map((point) => point.is_out_of_control ? '#dc2626' : '#2563eb');

                    this.instance = new Chart(this.$refs.canvas, {
                        type: 'line',
                        data: {
                            labels,
                            datasets: [
                                {
                                    label: @js(__('performance_analysis.chart_actual')),
                                    data: actual,
                                    borderColor: '#2563eb',
                                    backgroundColor: 'rgba(37, 99, 235, 0.12)',
                                    pointBackgroundColor: colors,
                                    pointBorderColor: colors,
                                    pointRadius: points.map((point) => point.is_out_of_control ? 6 : 3),
                                    pointStyle: points.map((point) => point.is_out_of_control ? 'triangle' : 'circle'),
                                    pointBorderWidth: points.map((point) => point.is_out_of_control ? 2 : 1),
                                    pointHoverRadius: 8,
                                    borderWidth: 2,
                                    tension: 0,
                                    spanGaps: false,
                                },
                                {
                                    label: @js(__('performance_analysis.chart_center_line')),
                                    data: line('center_line', chart.center_line),
                                    borderColor: '#16a34a',
                                    borderDash: [6, 5],
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    spanGaps: false,
                                },
                                {
                                    label: @js(__('performance_analysis.chart_ucl')),
                                    data: line('ucl', chart.ucl),
                                    borderColor: '#dc2626',
                                    borderDash: [8, 5],
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    spanGaps: false,
                                },
                                {
                                    label: @js(__('performance_analysis.chart_lcl')),
                                    data: line('lcl', chart.lcl),
                                    borderColor: '#f59e0b',
                                    borderDash: [8, 5],
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    spanGaps: false,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            interaction: {mode: 'index', intersect: false},
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    rtl: @js(app()->getLocale() === 'ar'),
                                    textDirection: @js(app()->getLocale() === 'ar' ? 'rtl' : 'ltr'),
                                },
                                tooltip: {
                                    rtl: @js(app()->getLocale() === 'ar'),
                                    textDirection: @js(app()->getLocale() === 'ar' ? 'rtl' : 'ltr'),
                                    callbacks: {
                                        title: (items) => labels[items[0]?.dataIndex] || '',
                                        label: (item) => `${item.dataset.label}: ${item.formattedValue}`,
                                        afterBody: (items) => {
                                            const point = points[items[0]?.dataIndex];

                                            if (! point) {
                                                return [];
                                            }

                                            const details = [
                                                `${@js(__('performance_analysis.tooltip_status'))}: ${point.is_out_of_control ? @js(__('performance_analysis.point_signal')) : @js(__('performance_analysis.point_normal'))}`,
                                            ];
                                            if (point.sample_size !== null && point.sample_size !== undefined) {
                                                details.push(`${@js(__('performance_analysis.tooltip_sample_size'))}: ${point.sample_size}`);
                                            }
                                            if (point.problematic_count !== null && point.problematic_count !== undefined) {
                                                details.push(`${@js(__('performance_analysis.tooltip_problematic'))}: ${point.problematic_count}`);
                                            }

                                            return details;
                                        },
                                    },
                                },
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        maxRotation: 0,
                                        minRotation: 0,
                                        autoSkip: true,
                                        maxTicksLimit: 12,
                                        callback(value) {
                                            const label = this.getLabelForValue(value);
                                            const parts = String(label).split(' ');

                                            return parts.length > 1 ? [parts[0], parts[1]] : label;
                                        },
                                    },
                                    grid: {display: false},
                                },
                                y: {beginAtZero: true, grid: {color: 'rgba(148, 163, 184, 0.18)'}},
                            },
                        },
                    });
                };

                const renderWhenReady = (payload) => {
                    if (window.Chart) {
                        render(payload);
                    } else {
                        window.setTimeout(() => renderWhenReady(payload), 100);
                    }
                };

                this.$wire.$watch('chartPayload', (payload) => renderWhenReady(payload));
                renderWhenReady(this.$wire.chartPayload);
            },
        });
    </script>
