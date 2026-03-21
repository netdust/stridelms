<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Integration test for edition cascade delete.
 *
 * Verifies that deleting an edition also removes its child sessions
 * and registrations from the database.
 */
class EditionCascadeDeleteTest extends IntegrationTestCase
{
    private int $editionId;
    private array $sessionIds = [];
    private array $registrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);

        // Create a test edition
        $this->editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Cascade Delete Test',
            'post_status' => 'publish',
        ]);

        // Create child sessions
        $this->sessionIds[] = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Session 1',
            'post_status' => 'publish',
            'post_parent' => $this->editionId,
        ]);

        $this->sessionIds[] = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Session 2',
            'post_status' => 'publish',
            'post_parent' => $this->editionId,
        ]);

        // Create a registration
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'edition_id' => $this->editionId,
            'user_id' => self::$testUserId,
            'status' => 'confirmed',
            'registered_at' => current_time('mysql'),
        ]);
        $this->registrationIds[] = (int) $wpdb->insert_id;
    }

    protected function tearDown(): void
    {
        // Clean up anything left (in case test fails)
        foreach ($this->sessionIds as $id) {
            wp_delete_post($id, true);
        }
        if ($this->editionId) {
            wp_delete_post($this->editionId, true);
        }
        global $wpdb;
        foreach ($this->registrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }

        parent::tearDown();
    }

    public function testDeletingEditionDeletesChildSessions(): void
    {
        // Verify sessions exist before delete
        foreach ($this->sessionIds as $sessionId) {
            $this->assertNotNull(get_post($sessionId), "Session #$sessionId should exist before deletion");
        }

        // Delete the edition (triggers before_delete_post hook)
        wp_delete_post($this->editionId, true);

        // Sessions should be gone
        foreach ($this->sessionIds as $sessionId) {
            $this->assertNull(get_post($sessionId), "Session #$sessionId should be deleted after edition deletion");
        }
    }

    public function testDeletingEditionDeletesRegistrations(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        // Verify registration exists
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d",
            $this->editionId
        ));
        $this->assertGreaterThan(0, $count, 'Registration should exist before deletion');

        // Delete the edition
        wp_delete_post($this->editionId, true);

        // Registrations should be gone
        $countAfter = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d",
            $this->editionId
        ));
        $this->assertEquals(0, $countAfter, 'Registrations should be deleted after edition deletion');
    }

    public function testTrashingEditionDeletesChildSessions(): void
    {
        // Verify sessions exist
        foreach ($this->sessionIds as $sessionId) {
            $this->assertNotNull(get_post($sessionId));
        }

        // Trash the edition (triggers wp_trash_post hook)
        wp_trash_post($this->editionId);

        // Sessions should be gone (permanently, not trashed — sessions have no meaning without edition)
        foreach ($this->sessionIds as $sessionId) {
            $this->assertNull(get_post($sessionId), "Session should be permanently deleted when edition is trashed");
        }
    }

    public function testNonEditionDeleteDoesNotCascade(): void
    {
        // Create a regular post
        $postId = wp_insert_post([
            'post_type' => 'post',
            'post_title' => 'Regular Post',
            'post_status' => 'publish',
        ]);

        // Verify sessions still exist
        wp_delete_post($postId, true);

        foreach ($this->sessionIds as $sessionId) {
            $this->assertNotNull(get_post($sessionId), 'Sessions should not be affected by non-edition deletion');
        }
    }
}
