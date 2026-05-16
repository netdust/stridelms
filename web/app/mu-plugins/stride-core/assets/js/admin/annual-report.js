/* global Chart */
/**
 * Annual Report admin page Alpine.js component.
 *
 * Reads the localized payload from window.StrideAnnualReport, renders a
 * Chart.js bar chart of enrollments-by-course, and provides helpers for
 * KPI formatting, year switching, and CSV/PDF URL building.
 */
function strideAnnualReport() {
    const cfg = window.StrideAnnualReport || {};
    return {
        year: cfg.year,
        availableYears: cfg.availableYears || [],
        report: cfg.report || {
            kpis: {},
            sections: [],
            previousYear: (cfg.year || new Date().getFullYear()) - 1,
            generatedAt: '',
        },
        pdfUrl: cfg.pdfUrl,
        csvBaseUrl: cfg.csvBaseUrl,
        csvNonce: cfg.csvNonce,
        chart: null,

        init() {
            this.$nextTick(() => this.renderChart());
        },

        changeYear(y) {
            const url = new URL(window.location.href);
            url.searchParams.set('year', y);
            window.location.href = url.toString();
        },

        kpiLabel(key) {
            const map = {
                enrollments: 'Inschrijvingen',
                unique_participants: 'Unieke deelnemers',
                unique_organisations: 'Organisaties bereikt',
                completions: 'Voltooid',
                completion_rate: 'Voltooiingsgraad',
                training_hours: 'Vormingsuren',
                editions_ran: 'Edities',
                sessions_ran: 'Sessies',
            };
            return map[key] || key;
        },

        fmt(v, key) {
            if (v === null || v === undefined) return '—';
            if (key === 'completion_rate') return v + '%';
            if (key === 'training_hours') return v + ' u';
            return new Intl.NumberFormat('nl-BE').format(v);
        },

        kpiDelta(key) {
            const kpi = this.report.kpis[key];
            if (!kpi || kpi.current === null || kpi.previous === null || kpi.previous === 0) {
                return null;
            }
            const pct = Math.round(((kpi.current - kpi.previous) / kpi.previous) * 1000) / 10;
            return (pct >= 0 ? '+' : '') + pct + '%';
        },

        csvUrl(sectionId) {
            return this.csvBaseUrl
                + '&section=' + encodeURIComponent(sectionId)
                + '&year=' + this.year
                + '&_wpnonce=' + this.csvNonce;
        },

        csvAllUrl() {
            return this.csvBaseUrl
                + '&section=all'
                + '&year=' + this.year
                + '&_wpnonce=' + this.csvNonce;
        },

        renderChart() {
            const section = this.report.sections.find(s => s.id === 'enrollments_by_course');
            if (!section || !section.rows.length) return;
            const ctx = document.getElementById('sar-chart-courses');
            if (!ctx || typeof Chart === 'undefined') return;

            const labels = section.rows.map(r => r[0]);
            const current = section.rows.map(r => r[1]);
            const previous = section.rows.map(r => r[2] === null ? 0 : r[2]);

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: String(this.year), data: current, backgroundColor: '#2271b1' },
                        { label: String(this.report.previousYear), data: previous, backgroundColor: '#c3c4c7' },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                },
            });
        },
    };
}

window.strideAnnualReport = strideAnnualReport;
