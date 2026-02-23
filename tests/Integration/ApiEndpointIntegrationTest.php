<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Integration tests for NTDST API endpoints
 *
 * Tests that API handlers are properly registered and respond correctly.
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter ApiEndpoint
 */
class ApiEndpointIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        $this->cleanupUserMeta(self::$testUserId, [
            'phone',
            'first_name',
            'last_name',
        ]);

        parent::tearDown();
    }

    // =========================================================================
    // FILTER REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function profileHandlerFilterIsRegistered(): void
    {
        // Check that the filter has handlers attached
        $hasFilter = has_filter('ntdst/api_data/stride_update_profile');

        $this->assertNotFalse($hasFilter, 'stride_update_profile filter should be registered');
    }

    /**
     * @test
     */
    public function icalHandlerFilterIsRegistered(): void
    {
        $hasFilter = has_filter('ntdst/api_data/stride_download_ical');

        $this->assertNotFalse($hasFilter, 'stride_download_ical filter should be registered');
    }

    // =========================================================================
    // FILTER EXECUTION
    // =========================================================================

    /**
     * @test
     */
    public function profileFilterExecutesAndReturnsData(): void
    {
        $params = [
            'form_type' => 'personal',
            'first_name' => 'FilterTest',
            'last_name' => 'User',
            'phone' => '+31698765432',
        ];

        // Execute the filter directly (simulates what the API endpoint does)
        $result = apply_filters('ntdst/api_data/stride_update_profile', [], $params);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Verify data was persisted
        $this->assertUserMeta(self::$testUserId, 'phone', '+31698765432');
    }

    /**
     * @test
     */
    public function profileFilterRejectsUnauthenticatedRequests(): void
    {
        wp_set_current_user(0);

        $result = apply_filters('ntdst/api_data/stride_update_profile', [], [
            'form_type' => 'personal',
        ]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    // =========================================================================
    // RESPONSE CLASS
    // =========================================================================

    /**
     * @test
     */
    public function responseClassIsAvailable(): void
    {
        $this->assertTrue(
            class_exists('NTDST_Response'),
            'NTDST_Response class should be loaded'
        );
    }

    /**
     * @test
     */
    public function responseHelperFunctionExists(): void
    {
        $this->assertTrue(
            function_exists('ntdst_response'),
            'ntdst_response() helper should exist'
        );

        $response = ntdst_response();
        $this->assertInstanceOf('NTDST_Response', $response);
    }

    /**
     * @test
     */
    public function responseMimeTypeDetection(): void
    {
        $this->assertEquals('application/pdf', \NTDST_Response::getMimeType('test.pdf'));
        $this->assertEquals('text/calendar; charset=utf-8', \NTDST_Response::getMimeType('calendar.ics'));
        $this->assertEquals('text/csv; charset=utf-8', \NTDST_Response::getMimeType('export.csv'));
    }

    // =========================================================================
    // SERVICE AVAILABILITY
    // =========================================================================

    /**
     * @test
     */
    public function strideServicesAreRegistered(): void
    {
        // Check that key services are available via the container
        $this->assertTrue(
            function_exists('ntdst_get'),
            'ntdst_get() helper should exist'
        );

        // ProfileHandler should be instantiable
        $handler = new \Stride\Handlers\ProfileHandler();
        $this->assertInstanceOf(\Stride\Handlers\ProfileHandler::class, $handler);
    }
}
