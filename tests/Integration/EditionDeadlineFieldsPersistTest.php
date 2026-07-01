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
 * EditionAdminController::handleSave() — persistence of the two gate-deadline
 * fields added in Task 1.1/1.2 (`gate_deadline`, `post_gate_deadline`).
 *
 * Mirrors the EXISTING `selection_deadline` persist path exactly (both are
 * registered as EditionCPT 'type' => 'text' and both go through the same
 * `if (isset($fields[$field])) { $updateData[$field] = sanitize_text_field(...); }`
 * shape at EditionAdminController::handleSave()). No new persistence mechanism
 * introduced — this task only adds the two keys.
 *
 * Contract asserted here:
 *   1. Saving `gate_deadline` persists exactly the posted value.
 *   2. Saving `post_gate_deadline` persists exactly the posted value.
 *   3. A value containing HTML tags is stripped by sanitize_text_field
 *      (matches how EVERY other 'text' field on this save path is treated).
 *   4. Omitting the field on a subsequent save does NOT clobber the prior
 *      stored value (matches selection_deadline / all other conditional
 *      `isset($fields[$field])` fields on this path — updateMetaBatch only
 *      writes keys present in $updateData).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionDeadlineFieldsPersistTest
 */
final class EditionDeadlineFieldsPersistTest extends IntegrationTestCase
{
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

    /**
     * Drives the real save path: sets up nonce + capability + $_POST exactly
     * as the edition-admin sidebar form posts, then invokes handleSave().
     *
     * @param array<string, mixed> $fields
     */
    private function save(int $editionId, array $fields): void
    {
        wp_set_current_user((int) self::$testUserId);

        // Nonce is user-context-dependent — must be created AFTER the
        // current user is set, or wp_verify_nonce() fails.
        $_POST['stride_edition_nonce'] = wp_create_nonce(EditionAdminController::NONCE_SAVE);
        $_POST['ntdst_fields'] = $fields;

        $post = get_post($editionId);
        $this->controller()->handleSave($editionId, $post);

        unset($_POST['stride_edition_nonce'], $_POST['ntdst_fields']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard resolves
        // via map_meta_cap for the vad_edition CPT — the base fixture's user
        // is a plain subscriber, so it must be promoted to administrator
        // (matches the pattern used by every other admin-save integration
        // test in this suite, e.g. AdminIntegrationTest, AdminRolesIntegrationTest).
        wp_set_current_user((int) self::$testUserId);
        $user = wp_get_current_user();
        $user->set_role('administrator');
    }

    public function test_gate_deadline_is_persisted(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['gate_deadline' => '2026-08-01']);

        $this->assertSame(
            '2026-08-01',
            $this->repository()->getField($editionId, 'gate_deadline'),
            'gate_deadline must persist exactly the posted value',
        );
    }

    public function test_post_gate_deadline_is_persisted(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['post_gate_deadline' => '2026-09-15']);

        $this->assertSame(
            '2026-09-15',
            $this->repository()->getField($editionId, 'post_gate_deadline'),
            'post_gate_deadline must persist exactly the posted value',
        );
    }

    public function test_html_in_gate_deadline_is_stripped(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['gate_deadline' => '<script>2026-08-01']);

        $this->assertSame(
            '2026-08-01',
            $this->repository()->getField($editionId, 'gate_deadline'),
            'sanitize_text_field must strip HTML tags from gate_deadline',
        );
    }

    public function test_html_in_post_gate_deadline_is_stripped(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['post_gate_deadline' => '<b>2026-09-15</b>']);

        $this->assertSame(
            '2026-09-15',
            $this->repository()->getField($editionId, 'post_gate_deadline'),
            'sanitize_text_field must strip HTML tags from post_gate_deadline',
        );
    }

    public function test_omitted_gate_deadline_does_not_clobber_prior_value(): void
    {
        $editionId = $this->createTestEdition();

        // First save sets a value.
        $this->save($editionId, ['gate_deadline' => '2026-08-01']);

        // Second save omits gate_deadline entirely (e.g. a form submit that
        // doesn't touch this field) but posts an unrelated field.
        $this->save($editionId, ['venue' => 'Gent']);

        $this->assertSame(
            '2026-08-01',
            $this->repository()->getField($editionId, 'gate_deadline'),
            'omitting gate_deadline from a save must NOT clobber the prior stored value',
        );
    }

    public function test_omitted_post_gate_deadline_does_not_clobber_prior_value(): void
    {
        $editionId = $this->createTestEdition();

        $this->save($editionId, ['post_gate_deadline' => '2026-09-15']);
        $this->save($editionId, ['venue' => 'Gent']);

        $this->assertSame(
            '2026-09-15',
            $this->repository()->getField($editionId, 'post_gate_deadline'),
            'omitting post_gate_deadline from a save must NOT clobber the prior stored value',
        );
    }
}
