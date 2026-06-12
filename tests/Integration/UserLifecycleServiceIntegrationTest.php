<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserLifecycleService;

/**
 * Integration tests for GDPR user anonymisation.
 *
 * Verifies:
 * - PII stripping on wp_users + wp_usermeta
 * - Idempotency
 * - Admin-account refusal
 * - Registrations are PRESERVED (FK intact) — that's the whole point
 * - isAnonymised marker semantics
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter UserLifecycleService
 */
class UserLifecycleServiceIntegrationTest extends IntegrationTestCase
{
    private UserLifecycleService $service;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(UserLifecycleService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->testRegistrationIds as $regId) {
            if ($regId) {
                $this->deleteTestRegistration($regId);
            }
        }
        $this->testRegistrationIds = [];

        parent::tearDown();
    }

    private function createTestUser(array $meta = []): int
    {
        $unique = uniqid('', true);
        $email = 'pii-test-' . $unique . '@test.local';
        $login = 'piiuser_' . preg_replace('/[^a-z0-9]/i', '', $unique);
        $userId = wp_create_user($login, 'password', $email);
        if (!is_int($userId)) {
            throw new \RuntimeException('Failed to create test user: ' . print_r($userId, true));
        }
        self::$testPosts[] = $userId; // TestCase cleans these up

        wp_update_user([
            'ID' => $userId,
            'first_name' => 'Jan',
            'last_name' => 'Janssens',
            'display_name' => 'Jan Janssens',
            'description' => 'PII test user',
        ]);

        $defaults = [
            'phone' => '+32475123456',
            'organisation' => 'Test BV',
            'department' => 'Test Dept',
            'billing_company' => 'Test BV',
            'billing_address_1' => 'Teststraat 1',
            'billing_postcode' => '1000',
            'billing_city' => 'Brussel',
            'billing_vat' => 'BE0123456789',
            'gln_number' => '5400123456789',
            'invoice_email' => 'facturen@test.local',
            'national_id' => '85.07.15-123.45',
            'date_of_birth' => '1985-07-15',
            'professional_license_number' => 'RIZIV-12345',
        ];

        foreach (array_merge($defaults, $meta) as $key => $val) {
            update_user_meta($userId, $key, $val);
        }

        return $userId;
    }

    /**
     * @test
     */
    public function anonymiseStripsCorePIIFields(): void
    {
        $userId = $this->createTestUser();

        $result = $this->service->anonymise($userId);
        $this->assertTrue($result);

        $user = get_userdata($userId);
        $this->assertNotFalse($user, 'User row must still exist');
        $this->assertStringContainsString("anonymised+{$userId}@deleted.local", $user->user_email);
        $this->assertEquals("anonymised_{$userId}", $user->user_login);
        $this->assertEquals("Verwijderde gebruiker #{$userId}", $user->display_name);
        $this->assertEquals('', $user->first_name);
        $this->assertEquals('', $user->last_name);
        $this->assertEquals('', $user->description);
    }

    /**
     * @test
     */
    public function anonymiseStripsAllMappedUserMeta(): void
    {
        $userId = $this->createTestUser();
        $this->service->anonymise($userId);

        // Every meta key in the mapping should now be gone
        $mapping = \Stride\Modules\Enrollment\EnrollmentService::getUserMetaMapping();
        foreach ($mapping as $metaKey) {
            $val = get_user_meta($userId, $metaKey, true);
            $this->assertSame('', $val, "Meta key {$metaKey} should be empty after anonymise");
        }
    }

    /**
     * @test
     */
    public function anonymiseStripsNewIdentityFields(): void
    {
        // Belt-and-braces: even if the mapping changes, the 3 new fields we
        // care about for launch (RRN, DOB, license) must be cleared.
        $userId = $this->createTestUser();
        $this->service->anonymise($userId);

        $this->assertSame('', get_user_meta($userId, 'national_id', true));
        $this->assertSame('', get_user_meta($userId, 'date_of_birth', true));
        $this->assertSame('', get_user_meta($userId, 'professional_license_number', true));
    }

    /**
     * @test
     */
    public function anonymiseSetsMarkerMeta(): void
    {
        $userId = $this->createTestUser();

        $this->assertFalse($this->service->isAnonymised($userId));
        $this->service->anonymise($userId);
        $this->assertTrue($this->service->isAnonymised($userId));

        $at = (int) get_user_meta($userId, UserLifecycleService::META_ANONYMISED_AT, true);
        $this->assertGreaterThan(0, $at);
        $this->assertLessThanOrEqual(time(), $at);
    }

    /**
     * @test
     */
    public function anonymiseIsIdempotent(): void
    {
        $userId = $this->createTestUser();
        $first = $this->service->anonymise($userId);
        $this->assertTrue($first);

        $second = $this->service->anonymise($userId);
        $this->assertTrue($second, 'Second call must be a no-op success, not an error');
    }

    /**
     * @test
     */
    public function anonymiseRefusesAdministrators(): void
    {
        $unique = uniqid('', true);
        $adminId = wp_create_user(
            'admintest_' . preg_replace('/[^a-z0-9]/i', '', $unique),
            'pw',
            'admin-test-' . $unique . '@test.local'
        );
        $this->assertIsInt($adminId, 'wp_create_user must return an ID; got: ' . print_r($adminId, true));
        self::$testPosts[] = $adminId;
        $user = get_userdata($adminId);
        $user->set_role('administrator');

        $result = $this->service->anonymise($adminId);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('cannot_anonymise_admin', $result->get_error_code());
    }

    /**
     * @test
     */
    public function anonymiseReturnsErrorForNonExistentUser(): void
    {
        $result = $this->service->anonymise(9999999);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('user_not_found', $result->get_error_code());
    }

    /**
     * @test
     */
    public function anonymiseRefusesStrideStaff(): void
    {
        $unique = uniqid('', true);
        $coordinatorId = wp_create_user(
            'coord_' . preg_replace('/[^a-z0-9]/i', '', $unique),
            'pw',
            'coord-' . $unique . '@test.local'
        );
        $this->assertIsInt($coordinatorId);
        self::$testPosts[] = $coordinatorId;
        $user = get_userdata($coordinatorId);
        $user->set_role('stride_coordinator');

        $result = $this->service->anonymise($coordinatorId);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('cannot_anonymise_staff', $result->get_error_code());

        // Existing meta must be untouched.
        $this->assertEquals(
            'coord-' . $unique . '@test.local',
            get_userdata($coordinatorId)->user_email
        );
    }

    /**
     * @test
     *
     * The core GDPR promise: anonymising a user must NOT delete their
     * historical registrations. The user_id FK keeps pointing at the row.
     */
    public function anonymisePreservesRegistrationForeignKey(): void
    {
        $userId = $this->createTestUser();
        $editionId = $this->createTestEdition();

        $repo = ntdst_get(RegistrationRepository::class);
        $regId = $repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = is_wp_error($regId) ? null : $regId;
        $this->assertIsInt($regId);

        $this->service->anonymise($userId);

        // Registration row must still exist with the same user_id
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, edition_id, status FROM {$wpdb->prefix}vad_registrations WHERE id = %d",
            $regId
        ));
        $this->assertNotNull($row, 'Registration row must survive user anonymisation');
        $this->assertEquals($userId, (int) $row->user_id);
        $this->assertEquals($editionId, (int) $row->edition_id);
        $this->assertEquals('confirmed', $row->status);
    }

    /**
     * @test
     *
     * The user gets demoted to subscriber so any cached cap check that
     * still happens against this row can't return a privileged role.
     */
    public function anonymiseDemotesToSubscriber(): void
    {
        $userId = $this->createTestUser();
        $user = get_userdata($userId);
        $user->set_role('editor');

        $this->service->anonymise($userId);

        $user = get_userdata($userId);
        $this->assertContains('subscriber', $user->roles);
        $this->assertNotContains('editor', $user->roles);
    }
}
