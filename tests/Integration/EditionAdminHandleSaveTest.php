<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;

/**
 * EditionAdminController::handleSave() — the largest admin write surface.
 *
 * Pins the high-value, bug-prone write blocks that EditionDeadlineFieldsPersistTest
 * does NOT cover. Drives the REAL save path (nonce + capability guard + $_POST)
 * against real edition posts, reading back through the Data API (getField), and
 * leaves product code UNCHANGED.
 *
 * Contract asserted here (ground-truthed from source):
 *
 *   1. PRICE single-price dual-write (handleSave lines ~439-448). v1 has NO member
 *      tier — one price, discounts come from vouchers. Posting `price_non_member`
 *      writes the SAME cents to BOTH `price` AND `price_non_member` (equal). The
 *      back-compat path (posting only legacy `price`) also dual-writes both equal.
 *      Money is INTEGER CENTS (euro input × 100).
 *
 *   2. requires_session_selection DERIVE (lines ~491-512) — the subtle one. It is
 *      NOT posted directly; it's derived = true iff ANY posted session_slots entry
 *      has `required` truthy. Slots with an EMPTY `slot` key are skipped. The derive
 *      re-evaluates on every save (a required slot then a re-save with none required
 *      must flip the flag false).
 *
 *   3. SPEAKERS marker (lines ~468-484). The metabox posts a `speakers_present`
 *      marker so an emptied repeater still clears the meta: with the marker + no
 *      speakers[] rows, speakers persists as []. WITHOUT the marker, speakers is
 *      left untouched (isset-guard is on the marker, not the rows).
 *
 *   4. GUARD denial (lines ~387-391). A bad/missing nonce returns early → no write.
 *
 * Read-back: all asserted fields are declared in EditionCPT::getFields() and surface
 * through EditionRepository::getField() with per-schema typing — `float` reads cents
 * back as a float (so 15050 → 15050.0; asserted via (int) cast), `json`
 * (session_slots/speakers) reads back as an array, `boolean` reads back as bool.
 *
 * NOT re-tested here (already covered by EditionDeadlineFieldsPersistTest):
 * gate_deadline / post_gate_deadline persistence, HTML stripping, omit-no-clobber.
 * selection_deadline is added below as it was previously uncovered.
 *
 * Run: ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *   vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionAdminHandleSave'
 */
final class EditionAdminHandleSaveTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_edition CPT — the base fixture user is a plain
        // subscriber, so promote it to administrator (same pattern as every other
        // admin-save integration test, e.g. EditionDeadlineFieldsPersistTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    private function controller(): EditionAdminController
    {
        return new EditionAdminController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionRepository::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    private function repository(): EditionRepository
    {
        return ntdst_get(EditionRepository::class);
    }

    /** Read a schema field back through the Data API (typed per EditionCPT). */
    private function field(int $editionId, string $key, mixed $default = null): mixed
    {
        return $this->repository()->getField($editionId, $key, $default);
    }

    /**
     * Drive the real save path: set current user, create the nonce AFTER the user
     * is set (nonces are user-context-dependent), set $_POST['ntdst_fields'] plus
     * any top-level $_POST keys (e.g. stride_change_status), invoke handleSave(),
     * then clean up.
     *
     * @param array<string, mixed> $fields    goes into $_POST['ntdst_fields']
     * @param array<string, mixed> $topLevel  extra top-level $_POST keys
     * @param bool $validNonce  false posts a deliberately invalid nonce (guard test)
     */
    private function save(int $editionId, array $fields, array $topLevel = [], bool $validNonce = true): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST['stride_edition_nonce'] = $validNonce
            ? wp_create_nonce(EditionAdminController::NONCE_SAVE)
            : 'not-a-real-nonce';
        $_POST['ntdst_fields'] = $fields;
        foreach ($topLevel as $key => $value) {
            $_POST[$key] = $value;
        }

        $this->controller()->handleSave($editionId, get_post($editionId));

        unset($_POST['stride_edition_nonce'], $_POST['ntdst_fields']);
        foreach (array_keys($topLevel) as $key) {
            unset($_POST[$key]);
        }
    }

    // === 1. PRICE single-price dual-write ================================

    public function test_price_non_member_dual_writes_both_keys_equal_in_cents(): void
    {
        $editionId = $this->createTestEdition();

        // Post the canonical v1 key as a euro string with cents.
        $this->save($editionId, ['price_non_member' => '150.50']);

        $priceNonMember = (int) $this->field($editionId, 'price_non_member');
        $price          = (int) $this->field($editionId, 'price');

        $this->assertSame(
            15050,
            $priceNonMember,
            'price_non_member must store euro input × 100 as integer cents (150.50 → 15050)',
        );
        // The load-bearing single-price contract: price is SYNCED equal to
        // price_non_member. There is no member/non-member differentiation in v1.
        $this->assertSame(
            $priceNonMember,
            $price,
            'price must be dual-written EQUAL to price_non_member (single-price contract)',
        );
    }

    public function test_legacy_price_key_also_dual_writes_both_equal(): void
    {
        $editionId = $this->createTestEdition();

        // Back-compat path: a caller posts ONLY the legacy `price` key, no
        // price_non_member. Both must still end up equal.
        $this->save($editionId, ['price' => '200']);

        $price          = (int) $this->field($editionId, 'price');
        $priceNonMember = (int) $this->field($editionId, 'price_non_member');

        $this->assertSame(20000, $price, 'legacy price=200 must store as 20000 cents');
        $this->assertSame(
            $price,
            $priceNonMember,
            'legacy price key must dual-write price_non_member EQUAL to price',
        );
    }

    // === 2. requires_session_selection DERIVE ============================

    public function test_required_slot_derives_requires_session_selection_true_and_persists_slots(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, [
            'session_slots' => [
                ['slot' => 'week1', 'label' => 'Week 1', 'max_selections' => 2, 'required' => 1],
            ],
        ]);

        $this->assertTrue(
            (bool) $this->field($editionId, 'requires_session_selection'),
            'a slot marked required=1 must DERIVE requires_session_selection = true',
        );

        $slots = $this->field($editionId, 'session_slots');
        $this->assertIsArray($slots, 'session_slots must persist as an array');
        $this->assertCount(1, $slots, 'exactly the one posted slot must persist');
        $this->assertSame('week1', $slots[0]['slot']);
        $this->assertSame('Week 1', $slots[0]['label']);
        $this->assertSame(2, (int) $slots[0]['max_selections']);
        $this->assertTrue((bool) $slots[0]['required'], 'the persisted slot must carry required=true');
    }

    public function test_no_required_slot_derives_requires_session_selection_false(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, [
            'session_slots' => [
                ['slot' => 'week1', 'label' => 'Week 1', 'max_selections' => 1, 'required' => 0],
                ['slot' => 'week2', 'label' => 'Week 2', 'max_selections' => 1], // no required key
            ],
        ]);

        $this->assertFalse(
            (bool) $this->field($editionId, 'requires_session_selection'),
            'slots present but NONE required must DERIVE requires_session_selection = false',
        );
        $this->assertCount(2, $this->field($editionId, 'session_slots'), 'both non-required slots persist');
    }

    public function test_slot_with_empty_slot_key_is_skipped(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, [
            'session_slots' => [
                ['slot' => '', 'label' => 'Ghost', 'required' => 1],        // empty slot key → skipped
                ['slot' => 'week1', 'label' => 'Week 1', 'required' => 1],   // valid, required
            ],
        ]);

        $slots = $this->field($editionId, 'session_slots');
        $this->assertIsArray($slots);
        $this->assertCount(1, $slots, 'the empty-slot-key entry must be skipped; only the valid one persists');
        $this->assertSame('week1', $slots[0]['slot']);
        $this->assertTrue(
            (bool) $this->field($editionId, 'requires_session_selection'),
            'the surviving required slot still derives requires_session_selection = true',
        );
    }

    public function test_requires_session_selection_reevaluates_and_flips_false_on_resave(): void
    {
        $editionId = $this->createTestEdition();

        // First save: one required slot → true.
        $this->save($editionId, [
            'session_slots' => [
                ['slot' => 'week1', 'label' => 'Week 1', 'required' => 1],
            ],
        ]);
        $this->assertTrue(
            (bool) $this->field($editionId, 'requires_session_selection'),
            'precondition: required slot derives true',
        );

        // Re-save: same slot but NOT required → the derive must re-evaluate and
        // flip the stored flag back to false (this is the bug-prone path).
        $this->save($editionId, [
            'session_slots' => [
                ['slot' => 'week1', 'label' => 'Week 1', 'required' => 0],
            ],
        ]);
        $this->assertFalse(
            (bool) $this->field($editionId, 'requires_session_selection'),
            'a re-save with no required slot must FLIP requires_session_selection back to false',
        );
    }

    // === 3. SPEAKERS marker clear ========================================

    public function test_speakers_present_marker_persists_rows(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, [
            'speakers_present' => 1,
            'speakers' => [
                ['name' => 'Ada Lovelace', 'role' => 'Docent'],
                ['name' => 'Alan Turing', 'role' => 'Gastspreker'],
            ],
        ]);

        $speakers = $this->field($editionId, 'speakers');
        $this->assertIsArray($speakers);
        $this->assertCount(2, $speakers, 'both posted speaker rows must persist');
        $this->assertSame('Ada Lovelace', $speakers[0]['name']);
        $this->assertSame('Docent', $speakers[0]['role']);
        $this->assertSame('Alan Turing', $speakers[1]['name']);
    }

    public function test_speakers_present_marker_with_no_rows_clears_to_empty(): void
    {
        $editionId = $this->createTestEdition();

        // Seed some speakers first.
        $this->save($editionId, [
            'speakers_present' => 1,
            'speakers' => [['name' => 'Ada Lovelace', 'role' => 'Docent']],
        ]);
        $this->assertCount(1, $this->field($editionId, 'speakers'), 'precondition: one speaker seeded');

        // Re-save with the marker but NO speakers[] rows (emptied repeater).
        $this->save($editionId, ['speakers_present' => 1]);

        $this->assertSame(
            [],
            $this->field($editionId, 'speakers'),
            'the speakers_present marker with no rows must CLEAR speakers to []',
        );
    }

    public function test_without_marker_speakers_is_left_untouched(): void
    {
        $editionId = $this->createTestEdition();

        // Seed speakers with the marker.
        $this->save($editionId, [
            'speakers_present' => 1,
            'speakers' => [['name' => 'Ada Lovelace', 'role' => 'Docent']],
        ]);
        $this->assertCount(1, $this->field($editionId, 'speakers'), 'precondition: one speaker seeded');

        // Save an UNRELATED field with NO speakers_present marker — speakers must
        // NOT be cleared (the write is guarded on the marker, not the rows).
        $this->save($editionId, ['venue' => 'Gent']);

        $this->assertCount(
            1,
            $this->field($editionId, 'speakers'),
            'without the speakers_present marker, an unrelated save must NOT clobber speakers',
        );
    }

    // === selection_deadline (previously uncovered) =======================

    public function test_selection_deadline_persists_sanitized(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['selection_deadline' => '<b>2026-10-01</b>']);

        $this->assertSame(
            '2026-10-01',
            $this->field($editionId, 'selection_deadline'),
            'selection_deadline must persist as sanitized text (HTML stripped)',
        );
    }

    // === 4. GUARD denial =================================================

    public function test_bad_nonce_is_denied_no_write(): void
    {
        $editionId = $this->createTestEdition();

        // Attempt to set a distinctive venue with a deliberately invalid nonce.
        $this->save($editionId, ['venue' => 'ShouldNotPersist'], [], validNonce: false);

        $this->assertNotSame(
            'ShouldNotPersist',
            $this->field($editionId, 'venue'),
            'a bad nonce must cause handleSave to return early with NO write',
        );
    }
}
