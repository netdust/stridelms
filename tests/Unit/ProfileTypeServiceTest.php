<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\User\ProfileTypeService;
use Stride\Tests\TestCase;

/**
 * Unit tests for ProfileTypeService
 *
 * Tests profile type management: CRUD on wp_options types,
 * user type assignment via usermeta, and registration hook.
 */
class ProfileTypeServiceTest extends TestCase
{
    private ProfileTypeService $service;

    /** Sample profile types used across tests */
    private array $sampleTypes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleTypes = [
            [
                'slug' => 'apotheker',
                'label' => 'Apotheker',
                'description' => 'Pharmacist profile',
                'color' => '#3b82f6',
                'icon' => 'pill',
                'order' => 1,
            ],
            [
                'slug' => 'arts',
                'label' => 'Arts',
                'description' => 'Doctor profile',
                'color' => '#10b981',
                'icon' => 'stethoscope',
                'order' => 2,
            ],
            [
                'slug' => 'verpleegkundige',
                'label' => 'Verpleegkundige',
                'description' => 'Nurse profile',
                'color' => '#f59e0b',
                'icon' => 'heart',
                'order' => 3,
            ],
        ];

        $this->service = new ProfileTypeService();
    }

    // =========================================================================
    // getTypes()
    // =========================================================================

    /** @test */
    public function testGetTypesReturnsEmptyArrayWhenNoTypesConfigured(): void
    {
        $types = $this->service->getTypes();

        $this->assertIsArray($types);
        $this->assertEmpty($types);
    }

    /** @test */
    public function testGetTypesReturnsStoredTypes(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        // New instance to bypass cache
        $service = new ProfileTypeService();
        $types = $service->getTypes();

        $this->assertCount(3, $types);
        $this->assertEquals('apotheker', $types[0]['slug']);
        $this->assertEquals('arts', $types[1]['slug']);
        $this->assertEquals('verpleegkundige', $types[2]['slug']);
    }

    /** @test */
    public function testGetTypesCachesResultOnSubsequentCalls(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();

        // First call loads from option
        $first = $service->getTypes();
        $this->assertCount(3, $first);

        // Modify option directly — cached result should remain
        global $_test_options;
        $_test_options['stride_profile_types'] = [];

        $second = $service->getTypes();
        $this->assertCount(3, $second, 'Should return cached types, not re-read option');
    }

    /** @test */
    public function testGetTypesHandlesNonArrayOptionGracefully(): void
    {
        global $_test_options;
        $_test_options['stride_profile_types'] = 'invalid';

        $service = new ProfileTypeService();
        $types = $service->getTypes();

        $this->assertIsArray($types);
        $this->assertEmpty($types);
    }

    // =========================================================================
    // getType()
    // =========================================================================

    /** @test */
    public function testGetTypeReturnsBySlug(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $type = $service->getType('arts');

        $this->assertNotNull($type);
        $this->assertEquals('arts', $type['slug']);
        $this->assertEquals('Arts', $type['label']);
        $this->assertEquals('#10b981', $type['color']);
    }

    /** @test */
    public function testGetTypeReturnsNullForUnknownSlug(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $type = $service->getType('nonexistent');

        $this->assertNull($type);
    }

    /** @test */
    public function testGetTypeReturnsNullWhenNoTypesConfigured(): void
    {
        $type = $this->service->getType('apotheker');

        $this->assertNull($type);
    }

    // =========================================================================
    // setUserType()
    // =========================================================================

    /** @test */
    public function testSetUserTypeStoresAsArrayAndValidates(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $result = $service->setUserType(42, 'apotheker');

        $this->assertTrue($result);

        // Verify stored as array in usermeta
        $stored = get_user_meta(42, '_stride_profile_type', true);
        $this->assertIsArray($stored);
        $this->assertEquals(['apotheker'], $stored);
    }

    /** @test */
    public function testSetUserTypeRejectsUnknownSlug(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $result = $service->setUserType(42, 'unknown_type');

        $this->assertFalse($result);

        // Verify nothing was stored
        $stored = get_user_meta(42, '_stride_profile_type', true);
        $this->assertEmpty($stored);
    }

    /** @test */
    public function testSetUserTypeReplacesExistingType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->setUserType(42, 'apotheker');
        $service->setUserType(42, 'arts');

        $stored = get_user_meta(42, '_stride_profile_type', true);
        $this->assertEquals(['arts'], $stored);
    }

    // =========================================================================
    // getUserType()
    // =========================================================================

    /** @test */
    public function testGetUserTypeReturnsResolvedType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->setUserType(42, 'arts');

        $type = $service->getUserType(42);

        $this->assertNotNull($type);
        $this->assertEquals('arts', $type['slug']);
        $this->assertEquals('Arts', $type['label']);
    }

    /** @test */
    public function testGetUserTypeReturnsNullForOrphanedSlug(): void
    {
        // Set a type slug on the user directly
        update_user_meta(42, '_stride_profile_type', ['deleted_type']);

        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $type = $service->getUserType(42);

        $this->assertNull($type, 'Should return null when slug no longer exists in defined types');
    }

    /** @test */
    public function testGetUserTypeReturnsNullWhenNoTypeSet(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $type = $service->getUserType(42);

        $this->assertNull($type);
    }

    /** @test */
    public function testGetUserTypeReturnsNullWhenMetaIsEmptyString(): void
    {
        // Default return from get_user_meta with $single=true is ''
        $service = new ProfileTypeService();
        $type = $service->getUserType(99);

        $this->assertNull($type);
    }

    // =========================================================================
    // getUserTypes() — multi-select support
    // =========================================================================

    /** @test */
    public function testGetUserTypesReturnsAllResolvedTypes(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        // Manually store multiple slugs
        update_user_meta(42, '_stride_profile_type', ['apotheker', 'arts']);

        $service = new ProfileTypeService();
        $types = $service->getUserTypes(42);

        $this->assertCount(2, $types);
        $this->assertEquals('apotheker', $types[0]['slug']);
        $this->assertEquals('arts', $types[1]['slug']);
    }

    /** @test */
    public function testGetUserTypesFiltersOutOrphanedSlugs(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        update_user_meta(42, '_stride_profile_type', ['apotheker', 'deleted_type', 'arts']);

        $service = new ProfileTypeService();
        $types = $service->getUserTypes(42);

        $this->assertCount(2, $types);
        $this->assertEquals('apotheker', $types[0]['slug']);
        $this->assertEquals('arts', $types[1]['slug']);
    }

    /** @test */
    public function testGetUserTypesReturnsEmptyWhenNoTypeSet(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $types = $service->getUserTypes(42);

        $this->assertEmpty($types);
    }

    // =========================================================================
    // userHasType()
    // =========================================================================

    /** @test */
    public function testUserHasTypeReturnsTrueWhenUserHasType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->setUserType(42, 'apotheker');

        $this->assertTrue($service->userHasType(42, 'apotheker'));
    }

    /** @test */
    public function testUserHasTypeReturnsFalseWhenUserDoesNotHaveType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->setUserType(42, 'apotheker');

        $this->assertFalse($service->userHasType(42, 'arts'));
    }

    /** @test */
    public function testUserHasTypeReturnsFalseWhenNoTypeSet(): void
    {
        $this->assertFalse($this->service->userHasType(42, 'apotheker'));
    }

    /** @test */
    public function testUserHasTypeUsesStrictComparison(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->setUserType(42, 'arts');

        // Partial match should not work
        $this->assertFalse($service->userHasType(42, 'art'));
        $this->assertFalse($service->userHasType(42, 'Arts'));
    }

    // =========================================================================
    // onRegistrationComplete()
    // =========================================================================

    /** @test */
    public function testOnRegistrationCompleteSetsProfileType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->onRegistrationComplete(42, ['profile_type' => 'apotheker']);

        $type = $service->getUserType(42);
        $this->assertNotNull($type);
        $this->assertEquals('apotheker', $type['slug']);
    }

    /** @test */
    public function testOnRegistrationCompleteIgnoresEmptyType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->onRegistrationComplete(42, ['profile_type' => '']);

        $this->assertNull($service->getUserType(42));
    }

    /** @test */
    public function testOnRegistrationCompleteIgnoresMissingTypeKey(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->onRegistrationComplete(42, ['some_other_key' => 'value']);

        $this->assertNull($service->getUserType(42));
    }

    /** @test */
    public function testOnRegistrationCompleteRejectsUnknownType(): void
    {
        $this->setProfileTypes($this->sampleTypes);

        $service = new ProfileTypeService();
        $service->onRegistrationComplete(42, ['profile_type' => 'unknown_type']);

        $this->assertNull($service->getUserType(42));
    }

    /** @test */
    public function testRegistrationHookIsRegistered(): void
    {
        global $_test_actions;

        // Creating the service registers the hook
        new ProfileTypeService();

        $this->assertArrayHasKey('ntdst_auth_registration_complete', $_test_actions);
    }

    // =========================================================================
    // metadata()
    // =========================================================================

    /** @test */
    public function testMetadataReturnsRequiredFields(): void
    {
        $meta = ProfileTypeService::metadata();

        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('description', $meta);
        $this->assertArrayHasKey('priority', $meta);
        $this->assertEquals('Profile Type Service', $meta['name']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Store profile types in the test options store.
     */
    private function setProfileTypes(array $types): void
    {
        global $_test_options;
        $_test_options['stride_profile_types'] = $types;
    }
}
