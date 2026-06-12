<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Tool;
use WP_Error;

/**
 * Resolves LTI tool public keys for JWT verification.
 *
 * Given a client_id from a JWT assertion, finds the corresponding
 * lti_tool post and configures Tool::$defaultTool with its public key.
 */
final class ToolKeyResolver
{
    /**
     * Configure Tool::$defaultTool from client_id in JWT assertion.
     *
     * @param string $clientAssertion The JWT from client_assertion POST param
     * @return true|WP_Error True on success, WP_Error if tool not found
     */
    public function configureToolFromAssertion(string $clientAssertion): true|WP_Error
    {
        // Extract client_id from JWT payload (iss claim)
        $clientId = $this->extractClientIdFromJwt($clientAssertion);
        if (is_wp_error($clientId)) {
            return $clientId;
        }

        // Find tool by client_id
        $tool = $this->findToolByClientId($clientId);
        if (!$tool) {
            return new WP_Error('invalid_client', 'Unknown client_id: ' . $clientId);
        }

        // Get tool's public key
        $publicKey = $this->getToolPublicKey($tool);
        if (!$publicKey) {
            return new WP_Error('invalid_client', 'Could not get tool public key');
        }

        // Configure Tool::$defaultTool for the library
        Tool::$defaultTool = new Tool(null);
        Tool::$defaultTool->rsaKey = $publicKey;

        return true;
    }

    /**
     * Extract iss claim from JWT without full validation.
     */
    private function extractClientIdFromJwt(string $jwt): string|WP_Error
    {
        if (empty($jwt)) {
            return new WP_Error('missing_assertion', 'Client assertion required');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return new WP_Error('invalid_jwt', 'Invalid JWT format');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload || !isset($payload['iss'])) {
            return new WP_Error('invalid_jwt', 'Could not decode JWT or missing iss claim');
        }

        return $payload['iss'];
    }

    /**
     * Find LTI tool CPT by client_id meta.
     */
    private function findToolByClientId(string $clientId): ?\WP_Post
    {
        $model = ntdst_data()->get('lti_tool');
        $tool = $model->where('client_id', $clientId)->first();

        return $tool ? get_post($tool->ID) : null;
    }

    /**
     * Get tool's public key from Data Manager field or JWKS endpoint.
     */
    private function getToolPublicKey(\WP_Post $tool): ?string
    {
        // Load tool via Data Manager to access fields
        $model = ntdst_data()->get('lti_tool');
        $toolData = $model->find($tool->ID);

        if (!$toolData || is_wp_error($toolData)) {
            return null;
        }

        // Try stored public key first (via Data Manager field)
        $publicKey = $toolData->fields['public_key'] ?? '';
        if (!empty($publicKey)) {
            return $publicKey;
        }

        // Try JWKS endpoint (via Data Manager field)
        $jwksUrl = $toolData->fields['jwks_url'] ?? '';
        if (!empty($jwksUrl)) {
            return $this->fetchKeyFromJwks($jwksUrl);
        }

        return null;
    }

    /**
     * Fetch first RSA key from JWKS endpoint with caching.
     */
    private function fetchKeyFromJwks(string $jwksUrl): ?string
    {
        // Check cache first (1 hour TTL)
        $cacheKey = 'lti_jwks_' . md5($jwksUrl);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached ?: null; // Empty string means "no key found"
        }

        $response = wp_remote_get($jwksUrl, [
            'timeout' => 10,
            'sslverify' => defined('DDEV_SITENAME'), // Disable for DDEV only
        ]);

        if (is_wp_error($response)) {
            ntdst_log('lti')->warning('JWKS fetch failed', [
                'url' => $jwksUrl,
                'error' => $response->get_error_message(),
            ]);
            return null;
        }

        $jwks = json_decode(wp_remote_retrieve_body($response), true);
        if (!$jwks || !isset($jwks['keys']) || empty($jwks['keys'])) {
            ntdst_log('lti')->warning('Invalid JWKS response', ['url' => $jwksUrl]);
            set_transient($cacheKey, '', HOUR_IN_SECONDS); // Cache negative result
            return null;
        }

        foreach ($jwks['keys'] as $key) {
            if (($key['kty'] ?? '') === 'RSA') {
                try {
                    $pem = \ceLTIc\LTI\Jwt\FirebaseClient::getPublicKey($key, 'RS256');
                    set_transient($cacheKey, $pem, HOUR_IN_SECONDS);
                    return $pem;
                } catch (\Exception $e) {
                    ntdst_log('lti')->warning('JWK to PEM conversion failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        set_transient($cacheKey, '', HOUR_IN_SECONDS); // Cache negative result
        return null;
    }
}
