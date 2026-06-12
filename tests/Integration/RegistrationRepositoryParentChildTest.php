<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for the parent_registration_id plumbing on RegistrationRepository.
 *
 * Covers Stap 2 of plans/2026-05-20-trajectory-cascade-enrollment.md:
 *  - create() accepts parent_registration_id
 *  - update() can set/clear parent_registration_id via the whitelist
 *  - findByParent() returns child rows in registered_at order
 *  - cancelChildren() bulk-cancels and is idempotent
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter RegistrationRepositoryParentChild
 */
final class RegistrationRepositoryParentChildTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
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

        parent::tearDown();
    }

    /** @test */
    public function createAcceptsParentRegistrationId(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();

        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $editionA,
        ]);

        $child = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $editionB,
            'parent_registration_id' => $parent,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);

        $row = $this->repo->find($child);
        $this->assertNotNull($row);
        $this->assertSame($parent, (int) $row->parent_registration_id);
    }

    /** @test */
    public function updateCanSetAndClearParentRegistrationId(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();

        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $editionA,
        ]);

        $orphan = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $editionB,
        ]);

        $this->assertTrue($this->repo->update($orphan, ['parent_registration_id' => $parent]));
        $row = $this->repo->find($orphan);
        $this->assertSame($parent, (int) $row->parent_registration_id);

        $this->assertTrue($this->repo->update($orphan, ['parent_registration_id' => null]));
        $row = $this->repo->find($orphan);
        $this->assertNull($row->parent_registration_id);
    }

    /** @test */
    public function findByParentReturnsChildrenInRegistrationOrder(): void
    {
        $parentEdition = $this->createTestEdition();
        $childEditionA = $this->createTestEdition();
        $childEditionB = $this->createTestEdition();

        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $parentEdition,
        ]);

        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        $childA = $this->createRegistration([
            'user_id' => $userA,
            'edition_id' => $childEditionA,
            'parent_registration_id' => $parent,
        ]);
        $childB = $this->createRegistration([
            'user_id' => $userB,
            'edition_id' => $childEditionB,
            'parent_registration_id' => $parent,
        ]);

        $children = $this->repo->findByParent($parent);
        $this->assertCount(2, $children);

        $childIds = array_map(fn($row) => (int) $row->id, $children);
        $this->assertSame([$childA, $childB], $childIds, 'children returned in registered_at ASC');

        $this->assertSame($parent, (int) $children[0]->parent_registration_id);
    }

    /** @test */
    public function findByParentReturnsEmptyArrayWhenNoChildren(): void
    {
        $edition = $this->createTestEdition();
        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
        ]);

        $this->assertSame([], $this->repo->findByParent($parent));
    }

    /** @test */
    public function cancelChildrenTransitionsActiveChildrenAndIsIdempotent(): void
    {
        $parentEdition = $this->createTestEdition();
        $childEditionA = $this->createTestEdition();
        $childEditionB = $this->createTestEdition();

        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $parentEdition,
        ]);

        $userA = $this->createTestUser();
        $userB = $this->createTestUser();

        $this->createRegistration([
            'user_id' => $userA,
            'edition_id' => $childEditionA,
            'parent_registration_id' => $parent,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
        $this->createRegistration([
            'user_id' => $userB,
            'edition_id' => $childEditionB,
            'parent_registration_id' => $parent,
            'status' => RegistrationStatus::Confirmed->value,
        ]);

        $cancelled = $this->repo->cancelChildren($parent);
        $this->assertSame(2, $cancelled, 'first call cancels both confirmed children');

        foreach ($this->repo->findByParent($parent) as $child) {
            $this->assertSame(RegistrationStatus::Cancelled->value, $child->status);
            $this->assertNotEmpty($child->cancelled_at);
        }

        $this->assertSame(0, $this->repo->cancelChildren($parent), 'second call is a no-op');
    }

    /** @test */
    public function cancelChildrenDoesNotTouchOrphanedRegistrations(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();

        $parent = $this->createRegistration([
            'user_id' => self::$testUserId,
            'edition_id' => $editionA,
        ]);

        $userB = $this->createTestUser();
        $unrelated = $this->createRegistration([
            'user_id' => $userB,
            'edition_id' => $editionB,
        ]);

        $this->assertSame(0, $this->repo->cancelChildren($parent));

        $row = $this->repo->find($unrelated);
        $this->assertSame(RegistrationStatus::Confirmed->value, $row->status);
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

    private function createTestUser(): int
    {
        $username = 'cascade_test_' . wp_generate_password(8, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        if (is_wp_error($userId)) {
            $this->fail('createTestUser failed: ' . $userId->get_error_message());
        }
        $this->createdUserIds[] = $userId;
        return $userId;
    }
}
