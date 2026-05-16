<?php
/** @var \Stride\Modules\Reporting\AnnualReport $report */
$kpiLabels = [
    'enrollments'          => 'Inschrijvingen',
    'unique_participants'   => 'Unieke deelnemers',
    'unique_organisations'  => 'Organisaties bereikt',
    'completions'           => 'Voltooid',
    'completion_rate'       => 'Voltooiingsgraad',
    'training_hours'        => 'Vormingsuren',
    'editions_ran'          => 'Edities',
    'sessions_ran'          => 'Sessies',
];

$fmt = function ($v, string $key): string {
    if ($v === null) {
        return '—';
    }
    if ($key === 'completion_rate') {
        return $v . '%';
    }
    if ($key === 'training_hours') {
        return $v . ' u';
    }
    return is_numeric($v)
        ? number_format((float) $v, ($v == (int) $v ? 0 : 2), ',', '.')
        : (string) $v;
};
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>Jaarrapport <?php echo (int) $report->year; ?></title>
<style>
    body    { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1d2327; }
    h1      { font-size: 18pt; margin: 0 0 4pt; }
    h2      { font-size: 12pt; margin: 16pt 0 4pt; border-bottom: 1px solid #ccc; padding-bottom: 2pt; }
    .meta   { color: #50575e; margin-bottom: 16pt; }
    table   { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
    th, td  { border: 1px solid #dcdcde; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    th      { background: #f6f7f7; }
    .kpi-grid td { width: 25%; }
    .num    { text-align: right; }
</style>
</head>
<body>
    <h1>Jaarrapport <?php echo (int) $report->year; ?></h1>
    <p class="meta">
        Vergelijking met <?php echo (int) $report->previousYear; ?> —
        Gegenereerd op <?php echo esc_html($report->generatedAt); ?>
    </p>

    <h2>Kerncijfers</h2>
    <table class="kpi-grid">
        <?php
        $kpiKeys = array_keys($report->kpis);
        $count = count($kpiKeys);
        for ($i = 0; $i < $count; $i += 2):
            ?>
            <tr>
                <?php for ($j = 0; $j < 2; $j++):
                    $k = $kpiKeys[$i + $j] ?? null;
                    if ($k === null) {
                        echo '<td></td><td></td>';
                        continue;
                    }
                    $kpi   = $report->kpis[$k];
                    $label = $kpiLabels[$k] ?? $k;
                    ?>
                    <td><strong><?php echo esc_html($label); ?></strong></td>
                    <td class="num">
                        <?php echo esc_html($fmt($kpi['current'], $k)); ?>
                        <span style="color:#646970;">
                            (<?php echo (int) $report->previousYear; ?>:
                            <?php echo esc_html($fmt($kpi['previous'], $k)); ?>)
                        </span>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endfor; ?>
    </table>

    <?php foreach ($report->sections as $section): ?>
        <h2><?php echo esc_html($section->title); ?></h2>
        <?php if (empty($section->rows)): ?>
            <p style="color:#646970;">Geen gegevens.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($section->headers as $h): ?>
                            <th><?php echo esc_html((string) $h); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($section->rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $i => $cell): ?>
                                <td class="<?php echo $i === 0 ? '' : 'num'; ?>">
                                    <?php echo $cell === null ? '—' : esc_html((string) $cell); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
