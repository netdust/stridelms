<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use Firebase\JWT\JWT as FirebaseJWT;

/**
 * Mock LTI Platform for integration testing.
 *
 * Generates RSA keys, creates a platform CPT, and builds valid LTI 1.3 JWTs
 * that the Tool Provider can validate.
 */
final class MockLtiPlatform
{
    private static ?array $keyPair = null;

    private int $platformPostId = 0;
    private string $platformId;
    private string $clientId;
    private string $deploymentId;
    private string $kid = 'test-key-1';

    public function __construct(
        string $platformId = 'https://mock-lms.test',
        string $clientId = 'mock-client-id-123',
        string $deploymentId = 'mock-deployment-1',
    ) {
        $this->platformId = $platformId;
        $this->clientId = $clientId;
        $this->deploymentId = $deploymentId;
    }

    public static function getKeyPair(): array
    {
        if (self::$keyPair !== null) {
            return self::$keyPair;
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);

        self::$keyPair = [
            'private' => $privateKey,
            'public' => $details['key'],
        ];

        return self::$keyPair;
    }

    public function register(): int
    {
        $keys = self::getKeyPair();

        $postId = wp_insert_post([
            'post_title' => 'Mock LMS Platform',
            'post_type' => 'lti_platform',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create mock platform: ' . $postId->get_error_message());
        }

        $meta = [
            'lti_platform_id' => $this->platformId,
            'lti_client_id' => $this->clientId,
            'lti_deployment_id' => $this->deploymentId,
            'lti_auth_endpoint' => $this->platformId . '/auth',
            'lti_token_endpoint' => $this->platformId . '/token',
            'lti_jwks_endpoint' => '',
            'lti_rsa_key' => $keys['public'],
            'lti_kid' => $this->kid,
            'lti_enabled' => '1',
            'lti_role_instructor' => 'instructor',
            'lti_role_learner' => 'subscriber',
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        $this->platformPostId = $postId;

        return $postId;
    }

    public function buildLaunchJwt(array $overrides = []): string
    {
        $keys = self::getKeyPair();
        $now = time();

        $claims = array_merge([
            'iss' => $this->platformId,
            'aud' => $this->clientId,
            'sub' => 'user-' . uniqid(),
            'iat' => $now,
            'exp' => $now + 3600,
            'nonce' => bin2hex(random_bytes(16)),
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => $this->deploymentId,
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => home_url('/lti/launch'),
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => [
                'id' => 'resource-link-' . uniqid(),
                'title' => 'Test Course Launch',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/roles' => [
                'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner',
            ],
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
            'email' => 'testuser-' . uniqid() . '@mock-lms.test',
        ], $overrides);

        return FirebaseJWT::encode($claims, $keys['private'], 'RS256', $this->kid);
    }

    public function buildDeepLinkJwt(array $overrides = []): string
    {
        return $this->buildLaunchJwt(array_merge([
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
            'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => [
                'deep_link_return_url' => $this->platformId . '/deep-link-return',
                'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['iframe', 'window'],
            ],
        ], $overrides));
    }

    public function simulateLaunchPost(array $jwtOverrides = []): void
    {
        $jwt = $this->buildLaunchJwt($jwtOverrides);
        $_POST['id_token'] = $jwt;
        $_POST['state'] = bin2hex(random_bytes(16));
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    public function getPlatformPostId(): int
    {
        return $this->platformPostId;
    }

    public function getPlatformId(): string
    {
        return $this->platformId;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function cleanup(): void
    {
        if ($this->platformPostId) {
            wp_delete_post($this->platformPostId, true);
            $this->platformPostId = 0;
        }
    }

    public static function resetSuperglobals(): void
    {
        unset($_POST['id_token'], $_POST['state']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}
