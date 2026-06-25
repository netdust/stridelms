<?php

/**
 * Stride LMS — enrich some registrations with submitted enrollment_data stages,
 * so the Dossier "Ingediende gegevens" panel has content to show.
 *
 * The feature-matrix + bulk seeders create registrations WITHOUT enrollment_data
 * (that data is normally produced by the live enrollment flow — intake forms,
 * evaluations). With none of it, "Ingediende gegevens" is always empty. This
 * adds realistic intake + evaluation stages to a sample of confirmed/completed
 * registrations so the panel is demoable. NOT a product bug — purely seed data.
 *
 * Shape per AdminUserService::normalizeEnrollmentStages:
 *   { intake: {submitted_at, submitted_by, data:{...}}, evaluation:{...} }
 *
 * Run:   ddev exec wp eval-file scripts/seed-enrollment-data.php
 * Clean: re-running is idempotent (only fills regs that have empty data);
 *        the bulk-volume purge (seed-bulk-enrollments.php --purge) removes the
 *        bulk rows entirely, taking their data with them.
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/seed-enrollment-data.php\n";
    exit(1);
}
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: dev/local only!\n";
    exit(1);
}

global $wpdb;
$table = $wpdb->prefix . 'vad_registrations';

// Target confirmed/completed regs with no enrollment_data yet — those are the
// ones that realistically would have submitted an intake (and an evaluation
// once completed).
$rows = $wpdb->get_results(
    "SELECT id, status, registered_at FROM {$table}
     WHERE status IN ('confirmed','completed')
       AND (enrollment_data IS NULL OR enrollment_data = '' OR enrollment_data = 'null')
     ORDER BY registered_at DESC
     LIMIT 60",
    ARRAY_A,
);

if (empty($rows)) {
    echo "No confirmed/completed registrations without enrollment_data found.\n";
    return;
}

$byOptions = ['Imane', 'Lars', 'Fatima', 'Wout', 'Nora', 'Bram'];
$motivations = [
    'Wil mijn kennis rond jeugdgezondheid verdiepen.',
    'Aanbevolen door een collega; relevant voor mijn functie.',
    'Nodig voor de bijscholingsverplichting dit jaar.',
    'Interesse in de praktische aanpak en casussen.',
];
$diets = ['Geen', 'Vegetarisch', 'Veganistisch', 'Glutenvrij'];
$ratings = ['Zeer goed', 'Goed', 'Voldoende'];

$filled = 0;
foreach ($rows as $i => $row) {
    $regTs = strtotime($row['registered_at']) ?: time();
    $by = $byOptions[$i % count($byOptions)];

    // Intake: submitted a few days after registration.
    $intakeAt = date('d M Y · H:i', $regTs + 4 * DAY_IN_SECONDS);
    $data = [
        'intake' => [
            'submitted_at' => $intakeAt,
            'submitted_by' => $by,
            'data' => [
                'motivatie' => $motivations[$i % count($motivations)],
                'ervaring' => ['Beginner', 'Gemiddeld', 'Ervaren'][$i % 3],
                'dieet' => $diets[$i % count($diets)],
                'opmerkingen' => $i % 2 === 0 ? 'Graag materiaal vooraf.' : '',
            ],
        ],
    ];

    // Evaluation only for completed regs.
    if ($row['status'] === 'completed') {
        $evalAt = date('d M Y · H:i', $regTs + 30 * DAY_IN_SECONDS);
        $data['evaluation'] = [
            'submitted_at' => $evalAt,
            'submitted_by' => $by,
            'data' => [
                'algemene_beoordeling' => $ratings[$i % count($ratings)],
                'aanrader' => $i % 3 === 0 ? 'Nee' : 'Ja',
                'feedback' => 'Sterke praktijkvoorbeelden, goed tempo.',
            ],
        ];
    }

    $wpdb->update($table, ['enrollment_data' => wp_json_encode($data)], ['id' => (int) $row['id']]);
    $filled++;
}

echo "Filled enrollment_data on {$filled} registrations (intake + evaluation where completed).\n";
echo "Open a person's Dossier in the admin workspace — 'Ingediende gegevens' now shows the submitted stages.\n";
