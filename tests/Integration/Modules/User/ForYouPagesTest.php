<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\User;

use IntegrationTestCase;
use Stride\Modules\User\ProfileTypeService;
use Stride\Modules\User\UserDashboardService;

/**
 * RED contract for T11 (docs/plans/2026-07-05-profiletype-visibility-filter.md).
 *
 * Concept C — dashboard "Voor jou" curated links: additive, per-user, NOT access
 * control. A WP `page` promotes a profile type if its `_stride_dashboard_profiletypes`
 * meta contains the user's STORED type slug. Pages are never hidden or gated — this
 * only SURFACES the right links to the right people.
 *
 * Contract source: threat-model M6 (§4) + §6.4 (assembler) + §8 flow G.
 *
 * The load-bearing assertion is the per-user DATA-SCOPING NEGATIVE: a user of type Y
 * must NOT see a page promoted only to type X. The type is resolved server-side from
 * usermeta (ProfileTypeService::getUserType) — getForYouPages takes ONLY $userId, so
 * no request/client channel can select another type's promoted links.
 *
 * Tier A (per-user data-scoping read; the wrong-type isolation negative is the
 * denial-equivalent). Authored RED-first, before the implementation exists —
 * getForYouPages ships as a sentinel `return []` shell so the failure is BEHAVIORAL.
 * This test is IMMUTABLE to the implementer: green it without weakening.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ForYouPages'
 */
final class ForYouPagesTest extends IntegrationTestCase
{
    private const TYPE_X = 'foryou-type-x';
    private const TYPE_Y = 'foryou-type-y';
    private const META_KEY = '_stride_dashboard_profiletypes';

    private UserDashboardService $dashboard;
    private ProfileTypeService $profileTypes;

    /** @var array<int> */
    private array $createdPages = [];
    /** @var array<int> */
    private array $createdUsers = [];

    /** @var mixed */
    private $savedTypesOption;

    private int $pageA; // promotes X only
    private int $pageB; // promotes Y only
    private int $pageC; // promotes X and Y
    private int $pageD; // promotes nobody (normal published page)

    private int $userX;    // stored type X
    private int $userY;    // stored type Y
    private int $userNone; // no profile type

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard    = ntdst_get(UserDashboardService::class);
        $this->profileTypes = ntdst_get(ProfileTypeService::class);

        // Seed two real profile types into the option, resetting the DI-singleton
        // memo so getUserType() resolves them (see ProfileTypeService::resetCache).
        $this->savedTypesOption = get_option('stride_profile_types', false);
        update_option('stride_profile_types', [
            ['slug' => self::TYPE_X, 'label' => 'FoorYou X', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::TYPE_Y, 'label' => 'FoorYou Y', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        $this->profileTypes->resetCache();

        // Pages: A→[X], B→[Y], C→[X,Y], D→normal page, no promotion meta.
        $this->pageA = $this->createPromotedPage('Voor jou A (X)', [self::TYPE_X]);
        $this->pageB = $this->createPromotedPage('Voor jou B (Y)', [self::TYPE_Y]);
        $this->pageC = $this->createPromotedPage('Voor jou C (X+Y)', [self::TYPE_X, self::TYPE_Y]);
        $this->pageD = $this->createPromotedPage('Gewone pagina D', null);

        // Users: X-typed, Y-typed, and a typeless user.
        $this->userX    = $this->createUserWithType([self::TYPE_X]);
        $this->userY    = $this->createUserWithType([self::TYPE_Y]);
        $this->userNone = $this->createUserWithType(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPages as $pageId) {
            wp_delete_post($pageId, true);
        }
        $this->createdPages = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUsers as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUsers = [];

        if ($this->savedTypesOption === false) {
            delete_option('stride_profile_types');
        } else {
            update_option('stride_profile_types', $this->savedTypesOption);
        }
        $this->profileTypes->resetCache();

        parent::tearDown();
    }

    /** @test */
    public function userXSeesPagesPromotingXAndNotYOnly(): void
    {
        $ids = $this->pageIds($this->dashboard->getForYouPages($this->userX));

        $this->assertContains($this->pageA, $ids, 'userX must see page A (promoted to X)');
        $this->assertContains($this->pageC, $ids, 'userX must see page C (promoted to X and Y)');
        $this->assertNotContains($this->pageB, $ids, 'userX must NOT see page B (promoted to Y only)');
    }

    /**
     * MANDATORY per-user data-scoping negative: a Y-typed user must not be able to
     * see another type's (X-only) promoted link. This is the load-bearing isolation
     * assertion — the whole reason T11 is `split`.
     *
     * @test
     */
    public function userYDoesNotSeeXOnlyPage(): void
    {
        $ids = $this->pageIds($this->dashboard->getForYouPages($this->userY));

        $this->assertNotContains(
            $this->pageA,
            $ids,
            'DATA-SCOPING: a type-Y user must NOT see a page promoted only to type X',
        );
        // Positive control so this isn't vacuously true: userY does see their own.
        $this->assertContains($this->pageB, $ids, 'userY must see page B (promoted to Y)');
        $this->assertContains($this->pageC, $ids, 'userY must see page C (promoted to X and Y)');
    }

    /** @test */
    public function userWithNoTypeSeesNothing(): void
    {
        $this->assertSame(
            [],
            $this->dashboard->getForYouPages($this->userNone),
            'a user with no stored profile type gets an empty "Voor jou" set',
        );
    }

    /** @test */
    public function userWithTypeButNoPromotedPagesSeesNothing(): void
    {
        // A freshly-typed user whose type promotes no pages. Seed a 3rd type
        // with zero promoting pages and assign it.
        update_option('stride_profile_types', array_merge(
            (array) get_option('stride_profile_types', []),
            [['slug' => 'foryou-type-z', 'label' => 'Z', 'description' => '', 'color' => '', 'icon' => '', 'order' => 3]],
        ));
        $this->profileTypes->resetCache();
        $userZ = $this->createUserWithType(['foryou-type-z']);

        $this->assertSame(
            [],
            $this->dashboard->getForYouPages($userZ),
            'a user whose type promotes no pages gets an empty set (section absent, not an empty shell)',
        );
    }

    /** @test */
    public function nonPromotedPageIsReturnedForNobodyButStaysReachable(): void
    {
        // Concept-C boundary: page D promotes nobody → not surfaced to anyone…
        foreach ([$this->userX, $this->userY, $this->userNone] as $userId) {
            $ids = $this->pageIds($this->dashboard->getForYouPages($userId));
            $this->assertNotContains($this->pageD, $ids, 'page D promotes nobody, must not be surfaced');
        }

        // …but curation never gates: D is still a normal, published, reachable page.
        $post = get_post($this->pageD);
        $this->assertNotNull($post, 'page D still exists');
        $this->assertSame('publish', $post->post_status, 'page D remains a normal published page (never hidden by curation)');
    }

    /** @test */
    public function returnedCardsHaveIdTitleAndPermalinkUrl(): void
    {
        $cards = $this->dashboard->getForYouPages($this->userX);
        $this->assertNotEmpty($cards, 'userX has promoted pages');

        foreach ($cards as $card) {
            $this->assertArrayHasKey('id', $card);
            $this->assertArrayHasKey('title', $card);
            $this->assertArrayHasKey('url', $card);
            $this->assertIsInt($card['id']);
            $this->assertNotSame('', (string) $card['title'], 'card title is populated');
            $this->assertSame(
                get_permalink((int) $card['id']),
                $card['url'],
                'card url is the page permalink',
            );
        }
    }

    /**
     * Seam / wiring assertion: getHomeData() exposes the curated links under
     * `for_you`, populated for a promoted user and empty for a typeless one — so
     * the partial can gate on non-empty and stay absent (no empty shell).
     *
     * @test
     */
    public function getHomeDataWiresForYouKeyPopulatedAndEmpty(): void
    {
        $homeX = $this->dashboard->getHomeData($this->userX);
        $this->assertArrayHasKey('for_you', $homeX, 'getHomeData exposes a for_you key');
        $this->assertNotEmpty($homeX['for_you'], 'for_you is populated for a promoted user (userX)');
        $this->assertSame(
            $this->pageIds($this->dashboard->getForYouPages($this->userX)),
            $this->pageIds($homeX['for_you']),
            'getHomeData for_you mirrors getForYouPages',
        );

        $homeNone = $this->dashboard->getHomeData($this->userNone);
        $this->assertArrayHasKey('for_you', $homeNone);
        $this->assertSame([], $homeNone['for_you'], 'for_you is empty for a typeless user (section absent)');
    }

    // === Helpers ===

    /**
     * @param array<int, string>|null $promotedSlugs null ⇒ a plain page with no meta
     */
    private function createPromotedPage(string $title, ?array $promotedSlugs): int
    {
        $pageId = wp_insert_post([
            'post_type'   => 'page',
            'post_title'  => $title . ' ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($pageId)) {
            $this->fail('createPromotedPage failed: ' . $pageId->get_error_message());
        }

        if ($promotedSlugs !== null) {
            // Stored as a serialized array under the manual page-meta key (§6.4).
            update_post_meta($pageId, self::META_KEY, $promotedSlugs);
        }

        $this->createdPages[] = $pageId;

        return $pageId;
    }

    /**
     * @param array<int, string>|null $slugs null ⇒ no profile type stored
     */
    private function createUserWithType(?array $slugs): int
    {
        $username = 'foryou_user_' . wp_generate_password(6, false);
        $userId   = wp_create_user($username, 'testpass123', $username . '@test.local');
        if (is_wp_error($userId)) {
            $this->fail('createUserWithType failed: ' . $userId->get_error_message());
        }

        if ($slugs !== null) {
            // Mirrors ProfileTypeService::setUserType storage (array under _stride_profile_type).
            update_user_meta($userId, '_stride_profile_type', $slugs);
        }

        $this->createdUsers[] = $userId;

        return $userId;
    }

    /**
     * @param array<int, array{id: int, title: string, url: string}> $cards
     * @return array<int, int>
     */
    private function pageIds(array $cards): array
    {
        return array_map(static fn (array $c): int => (int) $c['id'], $cards);
    }
}
