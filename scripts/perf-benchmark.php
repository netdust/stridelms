<?php
/**
 * Performance benchmark for key admin endpoints.
 *
 * Measures query count + wall time + slowest query for each of the
 * heavy admin endpoints. Use to spot N+1 problems and to set a
 * baseline that future changes can be compared against.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/perf-benchmark.php
 *
 * Optional env:
 *   HEAVY=1   Synthesise a 50-enrollment user before running
 *             (cleaned up after the benchmark)
 */

// (no strict_types — wp eval-file strips <?php and evals the rest)

if (!defined('SAVEQUERIES') || !SAVEQUERIES) {
    // Late-define so wpdb starts recording from now on.
    if (!defined('SAVEQUERIES')) {
        define('SAVEQUERIES', true);
    }
}

global $wpdb;
$wpdb->queries = [];

use Stride\Admin\AdminAPIController;

$controller = ntdst_get(AdminAPIController::class);

// ----------------------------------------------------------------
// Pick or synthesise a heavy user
// ----------------------------------------------------------------
function pick_heavy_user(): int
{
    global $wpdb;
    $row = $wpdb->get_row(
        "SELECT r.user_id, COUNT(*) as cnt
         FROM {$wpdb->prefix}vad_registrations r
         INNER JOIN {$wpdb->users} u ON r.user_id = u.ID
         GROUP BY r.user_id
         ORDER BY cnt DESC
         LIMIT 1"
    );
    return $row ? (int) $row->user_id : 0;
}

function synthesise_heavy_user(int $enrollments = 50): array
{
    global $wpdb;
    $email = 'perf_' . time() . '@perf.test';
    $userId = wp_create_user('perf_' . time(), 'perftest123', $email);
    if (is_wp_error($userId)) {
        throw new RuntimeException('Could not create perf user: ' . $userId->get_error_message());
    }

    // Find existing editions to attach to (uses what's there; no new editions).
    $editionIds = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='vad_edition' AND post_status='publish' LIMIT {$enrollments}"
    );

    $created = 0;
    $regTable = $wpdb->prefix . 'vad_registrations';
    foreach ($editionIds as $editionId) {
        $wpdb->insert($regTable, [
            'user_id' => $userId,
            'edition_id' => (int) $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => current_time('mysql'),
        ]);
        $created++;
    }

    return [$userId, $created];
}

function cleanup_perf_user(int $userId): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $wpdb->delete($wpdb->prefix . 'vad_registrations', ['user_id' => $userId]);
    wp_delete_user($userId);
}

// ----------------------------------------------------------------
// Benchmark runner
// ----------------------------------------------------------------
function bench(string $label, callable $fn): void
{
    global $wpdb;
    $wpdb->queries = [];
    $start = microtime(true);
    $mem0 = memory_get_usage();
    $fn();
    $elapsed = microtime(true) - $start;
    $memDelta = memory_get_usage() - $mem0;

    $queries = $wpdb->queries;
    $count = count($queries);

    // Find slowest query
    usort($queries, fn($a, $b) => $b[1] <=> $a[1]);
    $slowest = $queries[0] ?? null;

    // Detect potential N+1: same query pattern repeated >5 times
    $patterns = [];
    foreach ($queries as $q) {
        // Normalise: strip IDs/values to compare structurally
        $sig = preg_replace('/\d+/', 'N', $q[0]);
        $sig = preg_replace('/\s+/', ' ', $sig);
        $sig = substr($sig, 0, 80);
        $patterns[$sig] = ($patterns[$sig] ?? 0) + 1;
    }
    arsort($patterns);
    $hotspot = array_key_first($patterns);
    $hotspotCount = $patterns[$hotspot] ?? 0;

    echo "─── {$label} ───\n";
    printf("  queries:   %d\n", $count);
    printf("  wall time: %.1f ms\n", $elapsed * 1000);
    printf("  memory Δ:  %s\n", size_format($memDelta, 1));
    if ($slowest) {
        printf("  slowest:   %.1f ms — %s\n",
            (float) $slowest[1] * 1000,
            substr(preg_replace('/\s+/', ' ', $slowest[0]), 0, 100)
        );
    }
    if ($hotspotCount >= 3) {
        printf("  hotspot:   %d× — %s%s\n",
            $hotspotCount,
            $hotspot,
            $hotspotCount >= 10 ? '   ⚠️ likely N+1' : ''
        );
    }
    echo "\n";
}

// ----------------------------------------------------------------
// Setup
// ----------------------------------------------------------------
$synthesised = false;
if (getenv('HEAVY') === '1') {
    [$userId, $count] = synthesise_heavy_user(50);
    echo "Synthesised perf user $userId with $count registrations.\n\n";
    $synthesised = true;
} else {
    $userId = pick_heavy_user();
    if (!$userId) {
        echo "No registrations found; pass HEAVY=1 to synthesise.\n";
        return;
    }
    $regCount = (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}vad_registrations WHERE user_id = %d",
        $userId
    ));
    echo "Benchmarking against existing user $userId with $regCount registrations.\n";
    echo "(Pass HEAVY=1 to test against a synthesised 50-enrollment user.)\n\n";
}

wp_set_current_user($userId);  // not strictly required — admin endpoints check caps at REST layer
// Switch to an admin so the canViewAdmin checks pass
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!empty($admins)) {
    wp_set_current_user((int) $admins[0]);
}

// ----------------------------------------------------------------
// Run
// ----------------------------------------------------------------
echo "================================================\n";
echo "  Stride admin endpoint performance benchmark\n";
echo "================================================\n\n";

bench("getUserDetail (the heavy one)", function () use ($controller, $userId) {
    $request = new WP_REST_Request('GET');
    $request->set_url_params(['id' => $userId]);
    $controller->getUserDetail($request);
});

bench("getStats (dashboard KPIs)", function () use ($controller) {
    $request = new WP_REST_Request('GET');
    $controller->getStats($request);
});

bench("getEditions (agenda view, 20 rows)", function () use ($controller) {
    $request = new WP_REST_Request('GET');
    $request->set_query_params([
        'view' => 'agenda',
        'page' => 1,
        'per_page' => 20,
        'search' => '',
        'status' => '',
        'date_from' => '',
        'date_to' => '',
        'theme' => 0,
        'format' => 0,
        'tag' => 0,
    ]);
    $controller->getEditions($request);
});

bench("getQuotes (20 rows)", function () use ($controller) {
    $request = new WP_REST_Request('GET');
    $request->set_query_params([
        'page' => 1,
        'per_page' => 20,
        'search' => '',
        'status' => '',
        'edition_id' => 0,
    ]);
    $controller->getQuotes($request);
});

bench("getActivityFeed (limit 10)", function () use ($controller) {
    $request = new WP_REST_Request('GET');
    $request->set_query_params(['limit' => 10]);
    $controller->getActivityFeed($request);
});

// ----------------------------------------------------------------
// Cleanup
// ----------------------------------------------------------------
if ($synthesised) {
    cleanup_perf_user($userId);
    echo "Cleaned up synthesised perf user $userId.\n";
}

echo "Done.\n";
