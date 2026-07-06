<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\User;

use IntegrationTestCase;
use Stride\Modules\User\DashboardPageMetabox;
use Stride\Modules\User\ProfileTypeService;

/**
 * T10 (plan §7 / §6.4 / §8 flow H) — RED-first contract for the page dashboard
 * "Voor jou" curated-link metabox save (concept C, §1). The `page` gets a metabox
 * whose chosen profile-type slugs persist to the page meta
 * `_stride_dashboard_profiletypes`. This is a WP admin-write + capability boundary
 * (threat model M5, flow H), so the denial paths — bad nonce, non-`edit_page`
 * user, unknown slug — are MANDATORY, not optional.
 *
 * ⚠️ This is a DIFFERENT surface from the enrollable rules metabox (T9): the `page`
 * CPT is NOT ntdst_data-registered (no repository, no `_ntdst_` prefix). The key
 * is the hand-chosen literal `_stride_dashboard_profiletypes`, used consistently on
 * the save side (T10) and the read side (T11). Read-back is via raw get_post_meta,
 * NOT a repository getField().
 *
 * Drives the REAL admin save path — DashboardPageMetabox::handleSave($pageId, $post)
 * with a user-context nonce in $_POST + wp_set_current_user — mirroring
 * VoucherAdminHandleSaveTest. Contract points asserted:
 *   1. Round-trip: valid slugs (valid nonce + edit_page user) -> exact array back;
 *      clearing all -> empty/removed.
 *   2. DENIAL — bad/missing nonce -> meta NOT written (M5).
 *   3. DENIAL — user WITHOUT edit_page cap -> meta NOT written (M5).
 *   4. Unknown slug (not in ProfileTypeService::getTypes()) -> dropped (M5 allowlist).
 *   5. Sanitize — stored value is a clean array of slug strings (sanitize_key).
 *   6. register_post_meta -> the key is registered on `page`.
 *
 * RED (test-author): DashboardPageMetabox is a signature shell — handleSave()
 * throws 'not implemented', so every persistence assertion fails BEHAVIORALLY
 * (meta reads back empty), not "class not found". The implementer greens this
 * WITHOUT weakening it. This test is IMMUTABLE to the implementer.
 *
 * Run:
 *   ddev exec bash -c 'STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DashboardPageMetabox'
 */
final class DashboardPageMetaboxTest extends IntegrationTestCase
{
    private const OPTION_KEY = 'stride_profile_types';

    /** Known profile-type slugs seeded into the option for the allowlist. */
    private const KNOWN_SLUGS = ['apotheker', 'arts', 'verpleegkundige'];

    private int $pageId;

    /** A user with NO edit_page capability (for the cap-denial assertion). */
    private static ?int $noCapUserId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // A separate subscriber-level user that CANNOT edit_page — used to prove
        // the cap denial. Kept out of the shared $testUserId (which we promote to
        // administrator for the positive path).
        $u = wp_create_user(
            'dashmeta_nocap_' . wp_generate_password(6, false, false),
            'testpass123',
            'dashmeta_nocap_' . wp_generate_password(6, false, false) . '@test.local',
        );
        if (is_wp_error($u)) {
            throw new \RuntimeException('nocap user create failed: ' . $u->get_error_message());
        }
        self::$noCapUserId = (int) $u;
        (new \WP_User(self::$noCapUserId))->set_role('subscriber');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$noCapUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$noCapUserId);
            self::$noCapUserId = null;
        }

        delete_option(self::OPTION_KEY);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Seed known profile types so the allowlist (ProfileTypeService::getTypes())
        // has slugs to validate against. The service memoises getTypes() into a
        // per-instance cache, so reset it after writing the option (per the service's
        // own resetCache() contract) or a warmed cache leaks / masks the allowlist.
        update_option(self::OPTION_KEY, array_map(
            static fn (string $slug): array => [
                'slug' => $slug,
                'label' => ucfirst($slug),
                'description' => '',
                'color' => '',
                'icon' => '',
                'order' => 0,
            ],
            self::KNOWN_SLUGS,
        ));
        ntdst_get(ProfileTypeService::class)->resetCache();

        // Positive path acts as an administrator (has edit_page). Same promotion
        // pattern as VoucherAdminHandleSaveTest.
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');

        // A real page, tracked for suite cleanup.
        $this->pageId = (int) wp_insert_post([
            'post_title' => 'Voor-jou test page ' . wp_generate_password(4, false),
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($this->pageId) || $this->pageId === 0) {
            throw new \RuntimeException('test page create failed');
        }
        self::$testPosts[] = $this->pageId;
    }

    protected function tearDown(): void
    {
        unset(
            $_POST[DashboardPageMetabox::NONCE_FIELD],
            $_POST[DashboardPageMetabox::META_KEY],
            $_POST['dashboard_profiletypes'],
        );

        parent::tearDown();
    }

    private function metabox(): DashboardPageMetabox
    {
        return new DashboardPageMetabox(ntdst_get(ProfileTypeService::class));
    }

    /**
     * Drive the real save path: create a user-context nonce, set the posted slug
     * list, invoke handleSave(), then clean $_POST. The posted key mirrors the
     * meta key (the checkbox field name); a shell that reads a different field name
     * still fails these assertions, which is the point of a contract test.
     *
     * @param array<int, string> $slugs     Posted profile-type slugs.
     * @param bool                $withNonce Set false to simulate a bad/missing nonce.
     * @param int|null            $asUser    Act as this user (default: the admin).
     */
    private function save(array $slugs, bool $withNonce = true, ?int $asUser = null): void
    {
        wp_set_current_user($asUser ?? (int) self::$testUserId);

        if ($withNonce) {
            $_POST[DashboardPageMetabox::NONCE_FIELD] =
                wp_create_nonce(DashboardPageMetabox::NONCE_SAVE);
        }
        // Provide the slug list under both the meta-key name and a plain field name
        // so the contract is "the handler persists the POSTed allowlisted slugs",
        // regardless of which input name the implementer chooses for the checkboxes.
        $_POST[DashboardPageMetabox::META_KEY] = $slugs;
        $_POST['dashboard_profiletypes'] = $slugs;

        $this->metabox()->handleSave($this->pageId, get_post($this->pageId));

        unset(
            $_POST[DashboardPageMetabox::NONCE_FIELD],
            $_POST[DashboardPageMetabox::META_KEY],
            $_POST['dashboard_profiletypes'],
        );
    }

    /** Read the persisted slug array back via RAW get_post_meta (no repository). */
    private function stored(): mixed
    {
        return get_post_meta($this->pageId, DashboardPageMetabox::META_KEY, true);
    }

    // -----------------------------------------------------------------
    // 1. ROUND-TRIP — valid slugs persist exactly; clearing removes them
    // -----------------------------------------------------------------

    public function testValidSlugsRoundTripToPageMeta(): void
    {
        $this->save(['apotheker', 'arts']);

        $this->assertEqualsCanonicalizing(
            ['apotheker', 'arts'],
            (array) $this->stored(),
            'a valid set of profile-type slugs (valid nonce + edit_page admin) must '
            . 'persist to _stride_dashboard_profiletypes as exactly those slugs',
        );
    }

    public function testClearingAllSlugsEmptiesTheMeta(): void
    {
        // Precondition: a stored set.
        $this->save(['apotheker', 'arts']);
        $this->assertNotEmpty((array) $this->stored(), 'precondition: slugs stored');

        // Clearing all checkboxes -> empty array / removed meta.
        $this->save([]);

        $this->assertEmpty(
            (array) $this->stored(),
            'clearing all profile-type checkboxes must empty/remove the meta (not leave stale slugs)',
        );
    }

    // -----------------------------------------------------------------
    // 2. DENIAL — bad/missing nonce writes NOTHING (M5, MANDATORY)
    // -----------------------------------------------------------------

    public function testBadNonceWritesNothing(): void
    {
        // Establish a known-good baseline so we prove the second save is REJECTED,
        // not merely that the meta was never set.
        $this->save(['apotheker']);
        $this->assertEqualsCanonicalizing(['apotheker'], (array) $this->stored(), 'baseline stored');

        // Now attempt a change WITHOUT a valid nonce — must short-circuit, no write.
        $this->save(['arts', 'verpleegkundige'], withNonce: false);

        $this->assertEqualsCanonicalizing(
            ['apotheker'],
            (array) $this->stored(),
            'a bad/missing nonce must short-circuit the save (M5) — prior value untouched',
        );
    }

    // -----------------------------------------------------------------
    // 3. DENIAL — user without edit_page cap writes NOTHING (M5, MANDATORY)
    // -----------------------------------------------------------------

    public function testUserWithoutEditPageCapWritesNothing(): void
    {
        // Baseline written by the admin.
        $this->save(['apotheker']);
        $this->assertEqualsCanonicalizing(['apotheker'], (array) $this->stored(), 'baseline stored');

        // Sanity: the denial user genuinely lacks edit_page on this page.
        $this->assertFalse(
            user_can(self::$noCapUserId, 'edit_page', $this->pageId),
            'precondition: the nocap user must NOT have edit_page on the test page',
        );

        // A subscriber WITH a valid nonce but WITHOUT edit_page must be rejected —
        // the cap check, not just the nonce, is the boundary.
        $this->save(['arts', 'verpleegkundige'], asUser: self::$noCapUserId);

        $this->assertEqualsCanonicalizing(
            ['apotheker'],
            (array) $this->stored(),
            'a user without edit_page must be rejected by current_user_can(edit_page) '
            . '(M5) — meta NOT written even with a valid nonce',
        );
    }

    // -----------------------------------------------------------------
    // 4. ALLOWLIST — unknown slug is dropped (M5, mirrors T9)
    // -----------------------------------------------------------------

    public function testUnknownSlugIsDropped(): void
    {
        $this->save(['apotheker', 'not_a_real_profile_type', 'arts']);

        $stored = (array) $this->stored();

        $this->assertEqualsCanonicalizing(
            ['apotheker', 'arts'],
            $stored,
            'a POSTed slug not in ProfileTypeService::getTypes() must be dropped '
            . '(M5 allowlist) — only known slugs survive',
        );
        $this->assertNotContains(
            'not_a_real_profile_type',
            $stored,
            'the unknown slug must not be persisted',
        );
    }

    // -----------------------------------------------------------------
    // 5. SANITIZE — stored value is a clean array of slug strings
    // -----------------------------------------------------------------

    public function testStoredValueIsACleanArrayOfSlugStrings(): void
    {
        // A messy slug variant of a KNOWN type: sanitize_key lowercases + strips
        // to [a-z0-9_-]. 'Apotheker' / ' arts ' must reduce to their known slugs
        // and survive the allowlist; injection markup must never survive.
        $this->save(['Apotheker', ' arts ', '<script>alert(1)</script>']);

        $stored = (array) $this->stored();

        $this->assertContainsOnly(
            'string',
            $stored,
            'the stored value must be an array of clean slug strings',
        );
        foreach ($stored as $slug) {
            $this->assertSame(
                sanitize_key($slug),
                $slug,
                "stored slug '{$slug}' must already be sanitize_key-clean (no injection, no whitespace)",
            );
            $this->assertContains(
                $slug,
                self::KNOWN_SLUGS,
                "stored slug '{$slug}' must be an allowlisted known type",
            );
        }
        $this->assertNotContains(
            '<script>alert(1)</script>',
            $stored,
            'raw injection markup must never be persisted',
        );
    }

    // -----------------------------------------------------------------
    // 6. REGISTER_POST_META — the key is registered on `page`
    // -----------------------------------------------------------------

    public function testMetaKeyIsRegisteredOnPage(): void
    {
        $this->metabox()->registerMeta();

        $registered = get_registered_meta_keys('post', 'page');

        $this->assertArrayHasKey(
            DashboardPageMetabox::META_KEY,
            $registered,
            'register_post_meta(page, _stride_dashboard_profiletypes, ...) must register '
            . 'the key so it round-trips in the block editor',
        );
    }
}
