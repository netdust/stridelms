<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for RegistrationRepository::findChildRegistrationIdsByTrajectory().
 *
 * Admin Workspace Phase 2a, Task 2a.8 (B2 security fix). This method is the
 * reusable, MULTI-USER, trajectory-scoped parent->child child-set primitive the
 * trajectory-roster bulk needs for its CM-1 per-row scope. It was extracted from
 * the inline join in buildGridFilters() (the queryForGrid trajectory filter,
 * Phase-1B Task 1.4b) precisely BECAUSE findEditionsByTrajectory() is per-USER
 * (`WHERE child.user_id = %d`) and cannot serve a multi-user bulk scope.
 *
 * The corpus mirrors RegistrationGridQueryTest's trajectory fixture so the two
 * join sites (the inline grid filter + this method) stay provably equivalent
 * (the §676 sibling-audit):
 *   - T1 parent (edition_id NULL) — the trajectory PARENT row, must be EXCLUDED.
 *   - T1 cascade child A (parent-linked, trajectory_id NULL) — user 1, IN.
 *   - T1 cascade child B (parent-linked, trajectory_id NULL) — user 2, IN
 *     (the MULTI-USER pin: a DIFFERENT user than child A).
 *   - T1 legacy pre-cascade child (trajectory_id=T1, no parent link) — user 3, IN.
 *   - T2 parent + T2 cascade child — the foil, must NEVER leak into a T1 result.
 *   - A plain non-trajectory edition reg — must NEVER appear under T1.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryTrajectoryChildSet
 */
final class RegistrationRepositoryTrajectoryChildSetTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdUserIds = [];

    /** @var array<int> */
    private array $createdEditionIds = [];

    // Trajectory ids (any int; only used as the trajectory_id column value).
    private int $t1Id = 0;
    private int $t2Id = 0;

    private int $t1ParentRegId = 0;
    private int $t1ChildARegId = 0;
    private int $t1ChildBRegId = 0;
    private int $t1LegacyChildRegId = 0;
    private int $t2ParentRegId = 0;
    private int $t2ChildRegId = 0;
    private int $plainRegId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->seedCorpus();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        foreach ($this->createdEditionIds as $editionId) {
            wp_delete_post($editionId, true);
        }
        $this->createdEditionIds = [];

        parent::tearDown();
    }

    /**
     * The MULTI-USER correctness pin + leak-check (B2). For trajectory T1, the
     * method returns ALL users' child edition-rows (cascade A by user 1, cascade B
     * by user 2, legacy by user 3) and EXCLUDES the parent row (edition_id NULL)
     * and another trajectory's rows (T2) and a plain non-trajectory reg.
     *
     * @test
     */
    public function returnsAllUsersChildRowsAndExcludesParentAndForeignTrajectory(): void
    {
        $ids = $this->repo->findChildRegistrationIdsByTrajectory($this->t1Id);

        sort($ids);
        $expected = [$this->t1ChildARegId, $this->t1ChildBRegId, $this->t1LegacyChildRegId];
        sort($expected);

        $this->assertSame(
            $expected,
            $ids,
            'findChildRegistrationIdsByTrajectory(T1) must return exactly T1\'s child '
            . 'edition-rows across ALL users (cascade A user1, cascade B user2, legacy user3).',
        );

        // Leak-check: the parent row and the foil trajectory must NOT be present.
        $this->assertNotContains($this->t1ParentRegId, $ids, 'T1 parent (edition_id NULL) must be excluded');
        $this->assertNotContains($this->t2ParentRegId, $ids, 'T2 parent must be excluded');
        $this->assertNotContains($this->t2ChildRegId, $ids, 'T2 child must NOT leak into a T1 result');
        $this->assertNotContains($this->plainRegId, $ids, 'plain non-trajectory reg must NOT appear under T1');
    }

    /**
     * Spans more than one user — the explicit B2 multi-user assertion: child A and
     * child B belong to two DIFFERENT users, and BOTH are in the set. The per-user
     * findEditionsByTrajectory() would return only one user's rows; this method
     * must not.
     *
     * @test
     */
    public function setSpansMultipleUsers(): void
    {
        $ids = $this->repo->findChildRegistrationIdsByTrajectory($this->t1Id);

        $this->assertContains($this->t1ChildARegId, $ids, 'user 1\'s cascade child must be present');
        $this->assertContains($this->t1ChildBRegId, $ids, 'user 2\'s cascade child must be present (multi-user)');

        // Sanity: the two children genuinely belong to different users.
        $childA = $this->repo->find($this->t1ChildARegId);
        $childB = $this->repo->find($this->t1ChildBRegId);
        $this->assertNotSame(
            (int) $childA->user_id,
            (int) $childB->user_id,
            'fixture guard: child A and child B must be owned by different users',
        );
    }

    /**
     * A trajectory with no child edition-rows returns an empty array, not a row
     * for the parent.
     *
     * @test
     */
    public function emptyForTrajectoryWithNoChildren(): void
    {
        $lonelyTrajId = $this->t2Id + 9999;
        $parentOnly = $this->createRegistration([
            'user_id'         => self::$testUserId,
            'trajectory_id'   => $lonelyTrajId,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);

        $this->assertSame(
            [],
            $this->repo->findChildRegistrationIdsByTrajectory($lonelyTrajId),
            'a trajectory with only a parent row (edition_id NULL) returns no child ids',
        );
        $this->assertNotContains($parentOnly, $this->repo->findChildRegistrationIdsByTrajectory($lonelyTrajId));
    }

    private function seedCorpus(): void
    {
        $editionA = $this->createEdition();
        $editionB = $this->createEdition();
        $editionLegacy = $this->createEdition();
        $editionT2Child = $this->createEdition();
        $editionPlain = $this->createEdition();

        $user1 = self::$testUserId;
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        // Use the edition ids as trajectory id values — any distinct ints work.
        $this->t1Id = $editionA + 100000;
        $this->t2Id = $editionA + 200000;

        // T1 parent (edition_id NULL).
        $this->t1ParentRegId = $this->createRegistration([
            'user_id'         => $user1,
            'trajectory_id'   => $this->t1Id,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);

        // T1 cascade child A — user 1, parent-linked, trajectory_id NULL.
        $this->t1ChildARegId = $this->createRegistration([
            'user_id'                => $user1,
            'edition_id'             => $editionA,
            'parent_registration_id' => $this->t1ParentRegId,
            'status'                 => RegistrationStatus::Confirmed->value,
            'enrollment_path'        => 'trajectory',
        ]);

        // T1 cascade child B — user 2 (the MULTI-USER pin), parent-linked.
        $this->t1ChildBRegId = $this->createRegistration([
            'user_id'                => $user2,
            'edition_id'             => $editionB,
            'parent_registration_id' => $this->t1ParentRegId,
            'status'                 => RegistrationStatus::Waitlist->value,
            'enrollment_path'        => 'trajectory',
        ]);

        // T1 legacy pre-cascade child — user 3, trajectory_id=T1, no parent link.
        $this->t1LegacyChildRegId = $this->createRegistration([
            'user_id'         => $user3,
            'edition_id'      => $editionLegacy,
            'trajectory_id'   => $this->t1Id,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);

        // T2 parent (the foil).
        $this->t2ParentRegId = $this->createRegistration([
            'user_id'         => $user2,
            'trajectory_id'   => $this->t2Id,
            'status'          => RegistrationStatus::Confirmed->value,
            'enrollment_path' => 'trajectory',
        ]);

        // T2 cascade child — must never leak into a T1 result.
        $this->t2ChildRegId = $this->createRegistration([
            'user_id'                => $user3,
            'edition_id'             => $editionT2Child,
            'parent_registration_id' => $this->t2ParentRegId,
            'status'                 => RegistrationStatus::Confirmed->value,
            'enrollment_path'        => 'trajectory',
        ]);

        // Plain non-trajectory edition reg.
        $this->plainRegId = $this->createRegistration([
            'user_id'    => $user1,
            'edition_id' => $editionPlain,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
    }

    private function createEdition(): int
    {
        $id = $this->createTestEdition();
        $this->createdEditionIds[] = $id;
        return $id;
    }

    private function createTestUser(): int
    {
        $username = 'traj_childset_' . wp_generate_password(8, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        if (is_wp_error($userId)) {
            $this->fail('createTestUser failed: ' . $userId->get_error_message());
        }
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRegistration(array $data): int
    {
        $result = $this->repo->create($data);
        if (is_wp_error($result)) {
            $this->fail('createRegistration failed: ' . $result->get_error_message());
        }
        $this->createdRegistrationIds[] = $result;
        return $result;
    }
}
