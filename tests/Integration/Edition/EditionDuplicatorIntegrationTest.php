<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionDuplicator;

/**
 * Integration: full WP cycle.
 *
 * Rule A: copy-all-meta. We seed an arbitrary, made-up meta key on the
 * source and assert it lands on the copy. This is the regression guard
 * against future form fields being silently dropped.
 *
 * Rule B: reset list. We seed `notes` on the source and assert the copy
 * has empty notes.
 */
class EditionDuplicatorIntegrationTest extends IntegrationTestCase
{
    private int $sourceEditionId;
    private array $sessionIds = [];
    private ?int $newEditionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);

        $this->sourceEditionId = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'Source Edition',
            'post_status' => 'publish',
        ]);

        // Standard form fields (preserved by Rule A).
        update_post_meta($this->sourceEditionId, '_ntdst_enrollment_form', 'with_rrn');
        update_post_meta($this->sourceEditionId, '_ntdst_requires_questionnaire', true);
        update_post_meta($this->sourceEditionId, '_ntdst_price', 4500);
        update_post_meta($this->sourceEditionId, '_ntdst_course_id', 123);

        // Hypothetical future form field — proves Rule A.
        update_post_meta($this->sourceEditionId, '_ntdst_arbitrary_pilot_field', 'pilot-value');

        // Reset-list keys.
        update_post_meta($this->sourceEditionId, '_ntdst_notes', ['Jan ill day 2', 'Venue confirmed']);
        update_post_meta($this->sourceEditionId, '_ntdst_documents', [100, 200]);
        update_post_meta($this->sourceEditionId, '_ntdst_selection_open', true);
        update_post_meta($this->sourceEditionId, '_enrollment_count', 7);

        // One session so we know the copy path runs cleanly.
        $this->sessionIds[] = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Day 1',
            'post_status' => 'publish',
            'post_parent' => $this->sourceEditionId,
        ]);
        update_post_meta($this->sessionIds[0], '_ntdst_edition_id', $this->sourceEditionId);
        update_post_meta($this->sessionIds[0], '_ntdst_date', '2020-01-15');
        update_post_meta($this->sessionIds[0], '_ntdst_start_time', '09:00');
        update_post_meta($this->sessionIds[0], '_ntdst_end_time', '17:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            wp_delete_post($id, true);
        }
        wp_delete_post($this->sourceEditionId, true);
        if ($this->newEditionId) {
            wp_delete_post($this->newEditionId, true);
            // Also reap the copied session(s) — they have a different parent.
            $kids = get_posts([
                'post_type'   => 'vad_session',
                'post_status' => 'any',
                'meta_key'    => '_ntdst_edition_id',
                'meta_value'  => $this->newEditionId,
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            foreach ($kids as $kid) {
                wp_delete_post($kid, true);
            }
        }
        parent::tearDown();
    }

    public function testDuplicateCreatesDraftCopyWithKopieSuffix(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);

        $newId = $duplicator->duplicate($this->sourceEditionId);

        self::assertIsInt($newId, 'duplicate() should return an int new ID');
        $this->newEditionId = $newId;

        $newPost = get_post($newId);
        self::assertSame('vad_edition', $newPost->post_type);
        self::assertSame('draft', $newPost->post_status);
        self::assertSame('Source Edition (kopie)', $newPost->post_title);
    }

    public function testDuplicatePreservesArbitraryMetaKey(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        self::assertSame('pilot-value', get_post_meta($newId, '_ntdst_arbitrary_pilot_field', true));
        self::assertSame('with_rrn', get_post_meta($newId, '_ntdst_enrollment_form', true));
        self::assertSame('123', (string) get_post_meta($newId, '_ntdst_course_id', true));
    }

    public function testDuplicateAppliesResetList(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        self::assertSame([], get_post_meta($newId, '_ntdst_notes', true));
        self::assertSame([], get_post_meta($newId, '_ntdst_documents', true));
        self::assertSame('', (string) get_post_meta($newId, '_ntdst_selection_open', true), 'selection_open should be false-y on the copy');
        self::assertSame('', (string) get_post_meta($newId, '_enrollment_count', true), '_enrollment_count should be absent from the copy');
    }

    public function testDuplicateCopiesSessionsWithDatesResetToToday(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        $newSessions = get_posts([
            'post_type'   => 'vad_session',
            'post_status' => 'any',
            'meta_key'    => '_ntdst_edition_id',
            'meta_value'  => $newId,
            'numberposts' => -1,
        ]);

        self::assertCount(1, $newSessions, 'one session should be copied');
        $copy = $newSessions[0];
        self::assertSame(date('Y-m-d'), get_post_meta($copy->ID, '_ntdst_date', true));
        self::assertSame('09:00', get_post_meta($copy->ID, '_ntdst_start_time', true));
        self::assertSame('17:00', get_post_meta($copy->ID, '_ntdst_end_time', true));
    }

    public function testDuplicateDoesNotTouchSourceEdition(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        $source = get_post($this->sourceEditionId);
        self::assertSame('publish', $source->post_status);
        self::assertSame('Source Edition', $source->post_title);
        self::assertSame(['Jan ill day 2', 'Venue confirmed'], get_post_meta($this->sourceEditionId, '_ntdst_notes', true));
    }
}
