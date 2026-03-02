<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\ToolProvider\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LTI configuration endpoints.
 *
 * Uses a test subclass to access the protected build methods,
 * since the public handlers call exit/never-return.
 */
class ConfigEndpointTest extends TestCase
{
    private TestableRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent constructor from registering hooks (add_action/add_filter are stubs)
        $this->router = new TestableRouter();
    }

    public function testJsonConfigContainsRequiredFields(): void
    {
        $config = $this->router->exposeBuildJsonConfig();

        $this->assertArrayHasKey('title', $config);
        $this->assertArrayHasKey('description', $config);
        $this->assertArrayHasKey('oidc_initiation_url', $config);
        $this->assertArrayHasKey('target_link_uri', $config);
        $this->assertArrayHasKey('jwks_uri', $config);
        $this->assertArrayHasKey('claims', $config);
        $this->assertArrayHasKey('messages', $config);
        $this->assertArrayHasKey('scopes', $config);
    }

    public function testJsonConfigUrlsAreCorrect(): void
    {
        $config = $this->router->exposeBuildJsonConfig();

        $homeUrl = home_url();
        $this->assertSame($homeUrl . '/lti/login', $config['oidc_initiation_url']);
        $this->assertSame($homeUrl . '/lti/launch', $config['target_link_uri']);
        $this->assertSame($homeUrl . '/lti/jwks', $config['jwks_uri']);
    }

    public function testJsonConfigIncludesDeepLinkingMessage(): void
    {
        $config = $this->router->exposeBuildJsonConfig();

        $messageTypes = array_column($config['messages'], 'type');
        $this->assertContains('LtiResourceLinkRequest', $messageTypes);
        $this->assertContains('LtiDeepLinkingRequest', $messageTypes);
    }

    public function testJsonConfigIncludesAgsScopes(): void
    {
        $config = $this->router->exposeBuildJsonConfig();

        $this->assertContains(
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            $config['scopes']
        );
        $this->assertContains(
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            $config['scopes']
        );
    }

    public function testDeepLinkTargetUri(): void
    {
        $config = $this->router->exposeBuildJsonConfig();

        $deepLinkMessage = null;
        foreach ($config['messages'] as $msg) {
            if ($msg['type'] === 'LtiDeepLinkingRequest') {
                $deepLinkMessage = $msg;
                break;
            }
        }

        $this->assertNotNull($deepLinkMessage);
        $this->assertSame(
            home_url() . '/lti/deep-link',
            $deepLinkMessage['target_link_uri']
        );
    }

    public function testJsonConfigTitleFallsBackToDefault(): void
    {
        // With default stubs, get_bloginfo('name') returns 'Test Site'
        $config = $this->router->exposeBuildJsonConfig();
        $this->assertSame('Test Site', $config['title']);
    }

    public function testJsonConfigDescription(): void
    {
        $config = $this->router->exposeBuildJsonConfig();
        $this->assertSame('LearnDash course delivery via LTI 1.3', $config['description']);
    }

    public function testJsonConfigClaims(): void
    {
        $config = $this->router->exposeBuildJsonConfig();
        $this->assertSame(
            ['sub', 'name', 'email', 'given_name', 'family_name'],
            $config['claims']
        );
    }

    public function testCanvasXmlContainsRequiredElements(): void
    {
        $xml = $this->router->exposeBuildCanvasXml();

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('cartridge_basiclti_link', $xml);
        $this->assertStringContainsString('<blti:title>', $xml);
        $this->assertStringContainsString('<blti:description>', $xml);
        $this->assertStringContainsString('<blti:launch_url>', $xml);
    }

    public function testCanvasXmlContainsCorrectLaunchUrl(): void
    {
        $xml = $this->router->exposeBuildCanvasXml();

        $homeUrl = home_url();
        $this->assertStringContainsString($homeUrl . '/lti/launch', $xml);
    }

    public function testCanvasXmlContainsDomain(): void
    {
        $xml = $this->router->exposeBuildCanvasXml();

        $homeUrl = home_url();
        $domain = wp_parse_url($homeUrl, PHP_URL_HOST);
        $this->assertStringContainsString($domain, $xml);
    }

    public function testCanvasXmlContainsCanvasExtensions(): void
    {
        $xml = $this->router->exposeBuildCanvasXml();

        $this->assertStringContainsString('canvas.instructure.com', $xml);
        $this->assertStringContainsString('course_navigation', $xml);
        $this->assertStringContainsString('privacy_level', $xml);
    }
}

/**
 * Testable subclass that exposes protected build methods.
 */
class TestableRouter extends Router
{
    public function exposeBuildJsonConfig(): array
    {
        return $this->buildJsonConfig();
    }

    public function exposeBuildCanvasXml(): string
    {
        return $this->buildCanvasXml();
    }
}
