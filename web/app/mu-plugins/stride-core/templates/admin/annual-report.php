<?php
/**
 * Annual Report admin page template.
 *
 * Variables in scope: none. JS reads window.StrideAnnualReport (localized
 * by AnnualReportPage::enqueueAssets()).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap stride-annual-report" x-data="strideAnnualReport()" x-init="init()">
    <div class="sar-header">
        <h1><?php esc_html_e('Jaarrapport', 'stride'); ?></h1>
        <div class="sar-controls">
            <label for="sar-year"><?php esc_html_e('Jaar', 'stride'); ?></label>
            <select id="sar-year" x-model.number="year" @change="changeYear($event.target.value)">
                <template x-for="y in availableYears" :key="y">
                    <option :value="y" x-text="y"></option>
                </template>
            </select>
            <a class="button button-secondary" :href="csvAllUrl()"><?php esc_html_e('CSV (alles)', 'stride'); ?></a>
            <a class="button button-primary" :href="pdfUrl"><?php esc_html_e('Download PDF', 'stride'); ?></a>
        </div>
    </div>

    <p class="sar-meta">
        <?php esc_html_e('Gegenereerd op', 'stride'); ?>
        <span x-text="report.generatedAt"></span>
        — <?php esc_html_e('vergelijking met', 'stride'); ?>
        <span x-text="report.previousYear"></span>
    </p>

    <section class="sar-kpis">
        <template x-for="(kpi, key) in report.kpis" :key="key">
            <div class="sar-kpi">
                <div class="sar-kpi-label" x-text="kpiLabel(key)"></div>
                <div class="sar-kpi-current" x-text="fmt(kpi.current, key)"></div>
                <div class="sar-kpi-previous">
                    <span x-text="report.previousYear + ':'"></span>
                    <span x-text="kpi.previous === null ? '—' : fmt(kpi.previous, key)"></span>
                    <span class="sar-kpi-delta" x-show="kpiDelta(key) !== null" x-text="kpiDelta(key)"></span>
                </div>
            </div>
        </template>
    </section>

    <section class="sar-chart">
        <h2><?php esc_html_e('Inschrijvingen per cursus', 'stride'); ?></h2>
        <canvas id="sar-chart-courses" height="80"></canvas>
    </section>

    <template x-for="section in report.sections" :key="section.id">
        <section class="sar-section">
            <header class="sar-section-head">
                <h2 x-text="section.title"></h2>
                <a class="button button-small" :href="csvUrl(section.id)"><?php esc_html_e('CSV', 'stride'); ?></a>
            </header>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <template x-for="h in section.headers" :key="h">
                            <th x-text="h"></th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, idx) in section.rows" :key="idx">
                        <tr>
                            <template x-for="(cell, ci) in row" :key="ci">
                                <td x-text="cell === null ? '—' : cell"></td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="section.rows.length === 0">
                        <td :colspan="section.headers.length"><?php esc_html_e('Geen gegevens.', 'stride'); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </template>
</div>
