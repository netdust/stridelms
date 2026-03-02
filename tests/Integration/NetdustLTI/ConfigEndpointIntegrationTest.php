<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\ToolProvider\Router;

/**
 * Integration tests for LTI config endpoints.
 *
 * Verifies JSON and XML configs contain correct WordPress-generated URLs
 * when buildJsonConfig() and buildCanvasXml() are invoked with real WordPress.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter ConfigEndpointIntegration
 */
class ConfigEndpointIntegrationTest extends IntegrationTestCase
{
    private Router $router;
    private \ReflectionMethod $buildJsonConfig;
    private \ReflectionMethod $buildCanvasXml;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = ntdst_get(Router::class);

        // Both methods are protected — expose via reflection
        $this->buildJsonConfig = new \ReflectionMethod($this->router, 'buildJsonConfig');
        $this->buildJsonConfig->setAccessible(true);

        $this->buildCanvasXml = new \ReflectionMethod($this->router, 'buildCanvasXml');
        $this->buildCanvasXml->setAccessible(true);
    }

    // =========================================================================
    // JSON Config
    // =========================================================================

    /** @test */
    public function jsonConfigContainsCorrectHomeUrlValues(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);
        $homeUrl = home_url();

        // All endpoint URLs must start with the site home URL
        $this->assertStringStartsWith($homeUrl, $config['oidc_initiation_url']);
        $this->assertStringStartsWith($homeUrl, $config['target_link_uri']);
        $this->assertStringStartsWith($homeUrl, $config['jwks_uri']);

        // Verify specific LTI route paths
        $this->assertStringContainsString('/lti/login', $config['oidc_initiation_url']);
        $this->assertStringContainsString('/lti/launch', $config['target_link_uri']);
        $this->assertStringContainsString('/lti/jwks', $config['jwks_uri']);
    }

    /** @test */
    public function jsonConfigContainsBlogName(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);
        $blogName = get_bloginfo('name');

        if (!empty($blogName)) {
            $this->assertEquals($blogName, $config['title']);
        } else {
            $this->assertEquals('Stride LMS', $config['title']);
        }
    }

    /** @test */
    public function jsonConfigContainsDescription(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);

        $this->assertArrayHasKey('description', $config);
        $this->assertNotEmpty($config['description']);
    }

    /** @test */
    public function jsonConfigContainsRequiredMessages(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);

        $this->assertArrayHasKey('messages', $config);
        $this->assertIsArray($config['messages']);

        $messageTypes = array_column($config['messages'], 'type');

        $this->assertContains('LtiResourceLinkRequest', $messageTypes);
        $this->assertContains('LtiDeepLinkingRequest', $messageTypes);
    }

    /** @test */
    public function jsonConfigMessagesHaveTargetLinkUri(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);
        $homeUrl = home_url();

        foreach ($config['messages'] as $message) {
            $this->assertArrayHasKey('target_link_uri', $message, "Message type '{$message['type']}' missing target_link_uri");
            $this->assertStringStartsWith($homeUrl, $message['target_link_uri']);
        }
    }

    /** @test */
    public function jsonConfigDeepLinkMessagePointsToDeepLinkRoute(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);

        $deepLinkMessages = array_filter(
            $config['messages'],
            fn(array $msg) => $msg['type'] === 'LtiDeepLinkingRequest'
        );

        $this->assertNotEmpty($deepLinkMessages, 'Should have a LtiDeepLinkingRequest message');

        $deepLinkMsg = reset($deepLinkMessages);
        $this->assertStringContainsString('/lti/deep-link', $deepLinkMsg['target_link_uri']);
    }

    /** @test */
    public function jsonConfigContainsAgsScopes(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);

        $this->assertArrayHasKey('scopes', $config);
        $this->assertIsArray($config['scopes']);

        // All four AGS scopes must be present
        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem', $config['scopes']);
        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly', $config['scopes']);
        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/score', $config['scopes']);
        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly', $config['scopes']);
    }

    /** @test */
    public function jsonConfigContainsClaims(): void
    {
        $config = $this->buildJsonConfig->invoke($this->router);

        $this->assertArrayHasKey('claims', $config);
        $this->assertIsArray($config['claims']);
        $this->assertContains('sub', $config['claims']);
        $this->assertContains('name', $config['claims']);
        $this->assertContains('email', $config['claims']);
    }

    // =========================================================================
    // XML Config (Canvas-compatible)
    // =========================================================================

    /** @test */
    public function xmlConfigContainsCorrectDomain(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);

        $this->assertStringContainsString($domain, $xml);
    }

    /** @test */
    public function xmlConfigContainsLtiElements(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);

        $this->assertStringContainsString('<cartridge_basiclti_link', $xml);
        $this->assertStringContainsString('blti:title', $xml);
        $this->assertStringContainsString('blti:description', $xml);
        $this->assertStringContainsString('blti:launch_url', $xml);
    }

    /** @test */
    public function xmlConfigContainsLaunchUrl(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);
        $homeUrl = home_url();

        // The launch URL should contain the home URL and /lti/launch path
        $this->assertStringContainsString('/lti/launch', $xml);
    }

    /** @test */
    public function xmlConfigContainsCanvasExtensions(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);

        $this->assertStringContainsString('canvas.instructure.com', $xml);
        $this->assertStringContainsString('privacy_level', $xml);
        $this->assertStringContainsString('course_navigation', $xml);
    }

    /** @test */
    public function xmlConfigIsValidXml(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);

        // Suppress warnings and try to parse
        $previousErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $this->assertNotFalse($doc, 'XML config should be valid XML. Errors: ' . implode(
            ', ',
            array_map(fn(\LibXMLError $e) => trim($e->message), $errors)
        ));
    }

    /** @test */
    public function xmlConfigHasCorrectRootElement(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);

        $previousErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $this->assertNotFalse($doc);
        $this->assertEquals('cartridge_basiclti_link', $doc->getName());
    }

    /** @test */
    public function xmlConfigContainsBlogNameInTitle(): void
    {
        $xml = $this->buildCanvasXml->invoke($this->router);
        $blogName = get_bloginfo('name');

        if (!empty($blogName)) {
            $this->assertStringContainsString(esc_html($blogName), $xml);
        } else {
            $this->assertStringContainsString('Stride LMS', $xml);
        }
    }
}
