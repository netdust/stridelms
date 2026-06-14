<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Admin getEditions (list view) must enumerate dateless editions in the default
 * scope. A dateless edition has no sessions -> no start_date meta, so the old
 * INNER JOIN on _ntdst_start_date silently dropped it. The fix LEFT-JOINs that
 * meta and permits NULL in the default-scope predicate.
 *
 * Denial path: the date_from/date_to range filter is a deliberate dated-intent
 * filter and must STILL exclude dateless editions (NULL meta_value fails the
 * range comparison on the LEFT JOIN).
 *
 * Mirrors Admin Workspace spec section 10.7 intent.
 *
 * Plan: docs/plans/2026-06-14-dateless-editions-catalog.md (Task 6).
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionsDatelessTest
 */
final class AdminEditionsDatelessTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The list-view query is admin-scoped; act as an administrator. (The
        // method is invoked directly here — capability is enforced at route
        // registration via permission_callback, not inside getEditions.)
        $admin = self::factory_admin();
        $this->actingAs($admin);
    }

    private static function factory_admin(): int
    {
        $id = wp_insert_user([
            'user_login' => 'admin_dateless_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12, false),
            'user_email' => 'admin_dateless_' . wp_generate_password(6, false) . '@example.test',
            'role'       => 'administrator',
        ]);

        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to create admin user: ' . $id->get_error_message());
        }

        return (int) $id;
    }

    /** Dateless: no start_date / end_date meta written at all. */
    private function makeDatelessEdition(): int
    {
        return $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'announcement',
            '_ntdst_course_id' => 0,
        ]]);
    }

    private function makeDatedEdition(string $start): int
    {
        return $this->createTestEdition(['meta' => [
            '_ntdst_status'     => 'open',
            '_ntdst_course_id'  => 0,
            '_ntdst_start_date' => $start,
            '_ntdst_end_date'   => $start,
        ]]);
    }

    /**
     * @return list<int> edition ids returned by the list view
     *
     * A large per_page is used so the assertion is about query INCLUSION, not
     * page-1 placement — dateless rows sort NULL-last and would otherwise fall
     * to a later page in a DB that already holds dated editions.
     */
    private function listIds(array $params): array
    {
        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $req->set_param('view', 'list');
        $req->set_param('per_page', 200);
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }

        $res = ntdst_get(\Stride\Admin\AdminAPIController::class)->getEditions($req);
        $data = $res->get_data();

        return array_map(static fn($e) => (int) ($e['id'] ?? 0), $data['items'] ?? []);
    }

    public function test_dateless_edition_appears_in_default_list(): void
    {
        $id = $this->makeDatelessEdition();
        $this->assertContains($id, $this->listIds([]), 'Dateless edition must list in the default admin scope');
    }

    public function test_dateless_edition_excluded_when_date_range_supplied(): void
    {
        $id = $this->makeDatelessEdition();
        $ids = $this->listIds(['date_from' => date('Y-m-d', strtotime('-7 days'))]);
        $this->assertNotContains($id, $ids, 'A date range implies dated intent — dateless excluded');
    }

    public function test_dated_edition_still_lists_in_default_scope(): void
    {
        // Regression: the inclusion fix must not drop dated editions and the
        // NULL-last ordering must not error.
        $soon = date('Y-m-d', strtotime('+5 days'));
        $id = $this->makeDatedEdition($soon);
        $this->assertContains($id, $this->listIds([]), 'Dated edition must still list (no NULL-ordering crash)');
    }
}
