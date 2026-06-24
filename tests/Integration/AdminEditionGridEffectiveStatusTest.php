<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Task C1 (INV-7): the edition admin grid must emit EFFECTIVE status, not raw
 * stored status.
 *
 * The bug this pins: getEditions (LIST) + getEditionsAgendaView (AGENDA) emitted
 * the raw `_ntdst_status ?: 'open'` meta, while the typeahead
 * (getEditionOptions) already emits the EFFECTIVE status via
 * EditionService::getEffectiveStatuses() (INV-7: stored + dates + session
 * count). So an edition whose STORED status is 'open' but whose effective status
 * is Completed (past end_date) rendered "open" in the grid while the typeahead
 * showed "completed" — a divergence at the status convergence point.
 *
 * The stored<>effective fixture: stored '_ntdst_status' = 'open', no start_date
 * (so the LIST default-scope NULL-permitting predicate keeps it), '_ntdst_end_date'
 * in the past -> getEffectiveStatusFromPrefetched derives Completed. For the
 * agenda path the same edition carries a session dated today (so it surfaces in
 * the 2-days-ago default scope), while the effective status is still derived
 * from the EDITION's past end_date, not the session date.
 *
 * RED on pre-C1 code (grid emits 'open'); GREEN after (grid emits 'completed'),
 * and the grid now AGREES with the typeahead for the same edition.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionGridEffectiveStatusTest
 */
final class AdminEditionGridEffectiveStatusTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = wp_insert_user([
            'user_login' => 'admin_effstatus_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12, false),
            'user_email' => 'admin_effstatus_' . wp_generate_password(6, false) . '@example.test',
            'role'       => 'administrator',
        ]);

        if (is_wp_error($admin)) {
            throw new \RuntimeException('Failed to create admin user: ' . $admin->get_error_message());
        }

        $this->actingAs((int) $admin);
    }

    private function controller(): \Stride\Admin\AdminAPIController
    {
        return ntdst_get(\Stride\Admin\AdminAPIController::class);
    }

    /**
     * Seed an edition whose STORED status is 'open' but whose EFFECTIVE status is
     * Completed (past end_date, no start_date). Returns the edition id.
     */
    private function seedStoredOpenButEffectivelyCompletedEdition(): int
    {
        // No _ntdst_start_date (NULL-permitting LIST scope keeps it); past
        // _ntdst_end_date drives the effective Completed derivation. course_id 0
        // -> not classroom, so the Announcement rule does not apply.
        return $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'open',
            '_ntdst_end_date'  => '2020-01-01',
            '_ntdst_course_id' => 0,
        ]]);
    }

    /**
     * LIST view: a stored-open / effectively-completed edition emits the
     * EFFECTIVE status ('completed'), NOT the raw 'open'.
     */
    public function test_list_view_emits_effective_status_not_raw(): void
    {
        $editionId = $this->seedStoredOpenButEffectivelyCompletedEdition();

        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $req->set_param('view', 'list');
        $req->set_param('per_page', 200);

        $res = $this->controller()->getEditions($req);
        $data = $res->get_data();

        $item = $this->findItem($data['items'], $editionId);
        $this->assertNotNull($item, 'seeded edition must appear in the LIST grid');
        $this->assertSame(
            'completed',
            $item['status'],
            'LIST grid must emit the EFFECTIVE status (completed, derived from past end_date), not the raw stored open',
        );
    }

    /**
     * AGENDA view: the same stored-open / effectively-completed edition (with a
     * session dated today so it surfaces) emits the EFFECTIVE status.
     */
    public function test_agenda_view_emits_effective_status_not_raw(): void
    {
        $editionId = $this->seedStoredOpenButEffectivelyCompletedEdition();
        $sessionId = wp_insert_post([
            'post_title'  => 'EffStatus Session ' . wp_generate_password(4, false),
            'post_type'   => 'vad_session',
            'post_status' => 'publish',
        ]);
        update_post_meta((int) $sessionId, '_ntdst_edition_id', $editionId);
        update_post_meta((int) $sessionId, '_ntdst_date', current_time('Y-m-d'));
        update_post_meta((int) $sessionId, '_ntdst_start_time', '09:00');

        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $req->set_param('view', 'agenda');
        $req->set_param('per_page', 200);

        $res = $this->controller()->getEditions($req);
        $data = $res->get_data();

        $item = $this->findItem($data['items'], $editionId);
        $this->assertNotNull($item, 'seeded edition must appear in the AGENDA grid');
        $this->assertSame(
            'completed',
            $item['status'],
            'AGENDA grid must emit the EFFECTIVE status (completed), not the raw stored open',
        );
    }

    /**
     * The whole point of C1: grid and typeahead now AGREE on the status for the
     * same edition. The typeahead (getEditionOptions, scope=all) already emits
     * effective status; the LIST grid must report the SAME value.
     */
    public function test_grid_and_typeahead_agree_on_status(): void
    {
        $editionId = $this->seedStoredOpenButEffectivelyCompletedEdition();

        $gridReq = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $gridReq->set_param('view', 'list');
        $gridReq->set_param('per_page', 200);
        $gridItem = $this->findItem($this->controller()->getEditions($gridReq)->get_data()['items'], $editionId);

        // scope=all so a terminal/completed edition is NOT dropped from the
        // typeahead — we want to compare the SAME edition's status on both surfaces.
        $optReq = new WP_REST_Request('GET', '/stride/v1/admin/editions/options');
        $optReq->set_param('scope', 'all');
        $optReq->set_param('per_page', 100);
        $optItems = $this->controller()->getEditionOptions($optReq)->get_data()['items'];
        $optItem = null;
        foreach ($optItems as $o) {
            if ((int) $o['id'] === $editionId) {
                $optItem = $o;
                break;
            }
        }

        $this->assertNotNull($gridItem, 'edition present in grid');
        $this->assertNotNull($optItem, 'edition present in typeahead (scope=all)');
        $this->assertSame(
            $optItem['effective_status'],
            $gridItem['status'],
            'grid status and typeahead effective_status must AGREE for the same edition (INV-7 convergence)',
        );
        $this->assertSame('completed', $gridItem['status']);
    }

    /**
     * The C1 second-site fix (found at gate review by the invariant-auditor):
     * getEdition() — the single-edition detail endpoint feeding the slide-over
     * Info-tab badge — must ALSO emit effective status + label, so opening a
     * grid row can't show a different status than the row itself. Closes the
     * last INV-7 edition-status-display bypass.
     */
    public function test_get_edition_detail_emits_effective_status_and_label(): void
    {
        $editionId = $this->seedStoredOpenButEffectivelyCompletedEdition();

        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions/' . $editionId);
        $req->set_param('id', $editionId);
        $data = $this->controller()->getEdition($req)->get_data();

        // Stored is 'open'; effective (past end_date) is 'completed'.
        $this->assertSame('completed', $data['status'], 'getEdition must emit EFFECTIVE status, not raw stored (INV-7)');
        // The badge text falls back to the raw value when status_label is absent —
        // emit the effective label so the slide-over shows the right Dutch text.
        $this->assertArrayHasKey('status_label', $data, 'getEdition must emit status_label so the badge does not fall back to the raw value');
        $this->assertNotSame('', $data['status_label']);
        $this->assertNotSame('open', $data['status'], 'the stored-open edition must NOT render as open in the detail panel');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function findItem(array $items, int $editionId): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $editionId) {
                return $item;
            }
        }

        return null;
    }
}
