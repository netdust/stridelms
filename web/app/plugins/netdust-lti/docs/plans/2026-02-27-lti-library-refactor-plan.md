# LTI Library Refactor Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactor netdust-lti plugin to use celtic/lti library's Platform class for OAuth2 token and AGS grade handling instead of custom implementations.

**Architecture:** Library-First Wrapper Pattern. Create WPPlatform extending ceLTIc\LTI\Platform with WordPress-specific configuration. Router delegates token/grades endpoints to WPPlatform methods. Delete custom TokenEndpoint/AGSReceiver auth code, keep grade storage logic.

**Tech Stack:** PHP 8.1+, celtic/lti library, WordPress transients, WPDataConnector

---

## Task 1: Create WPPlatform Class

**Files:**
- Create: `src/Platform/WPPlatform.php`

**Step 1: Create the WPPlatform class extending library's Platform**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Platform;
use ceLTIc\LTI\DataConnector\DataConnector;
use NetdustLTI\ToolProvider\WPDataConnector;

/**
 * WordPress-specific Platform wrapper for LTI 1.3.
 *
 * Extends celtic/lti Platform class to:
 * - Load RSA key from WordPress options
 * - Configure token endpoint URL
 * - Use WPDataConnector for persistence
 */
final class WPPlatform extends Platform
{
    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector ?? new WPDataConnector());

        // Load platform's RSA private key for signing access tokens
        $this->rsaKey = get_option('netdust_lti_private_key');
        $this->kid = get_option('netdust_lti_kid', 'netdust-lti-key-1');
        $this->signatureMethod = 'RS256';

        // Configure token endpoint URL
        $this->accessTokenUrl = home_url('/lti/platform/token');
    }

    /**
     * Handle errors from the library.
     */
    protected function onError(): void
    {
        error_log('WPPlatform error: ' . $this->reason);
    }
}
```

**Step 2: Verify file was created correctly**

Run: `cat src/Platform/WPPlatform.php | head -30`
Expected: First 30 lines of the new file

**Step 3: Commit**

```bash
git add src/Platform/WPPlatform.php
git commit -m "feat(lti): add WPPlatform class extending celtic/lti Platform"
```

---

## Task 2: Create ToolKeyResolver Helper

The library's `sendAccessToken()` validates the client_assertion JWT. It needs Tool::$defaultTool configured with the Tool's public key. Create a helper to load this.

**Files:**
- Create: `src/Platform/ToolKeyResolver.php`

**Step 1: Create ToolKeyResolver class**

```php
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
        $tools = get_posts([
            'post_type' => 'lti_tool',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_lti_client_id',
                    'value' => $clientId,
                ],
            ],
        ]);

        return $tools[0] ?? null;
    }

    /**
     * Get tool's public key from meta or JWKS endpoint.
     */
    private function getToolPublicKey(\WP_Post $tool): ?string
    {
        // Try stored public key first
        $publicKey = get_post_meta($tool->ID, '_lti_public_key', true);
        if ($publicKey) {
            return $publicKey;
        }

        // Try JWKS endpoint
        $jwksUrl = get_post_meta($tool->ID, '_lti_jwks_url', true);
        if ($jwksUrl) {
            return $this->fetchKeyFromJwks($jwksUrl);
        }

        return null;
    }

    /**
     * Fetch first RSA key from JWKS endpoint.
     */
    private function fetchKeyFromJwks(string $jwksUrl): ?string
    {
        $response = wp_remote_get($jwksUrl, [
            'timeout' => 10,
            'sslverify' => false, // For DDEV local development
        ]);

        if (is_wp_error($response)) {
            error_log('ToolKeyResolver: JWKS fetch failed: ' . $response->get_error_message());
            return null;
        }

        $jwks = json_decode(wp_remote_retrieve_body($response), true);
        if (!$jwks || !isset($jwks['keys']) || empty($jwks['keys'])) {
            return null;
        }

        foreach ($jwks['keys'] as $key) {
            if (($key['kty'] ?? '') === 'RSA') {
                try {
                    return \ceLTIc\LTI\Jwt\FirebaseClient::getPublicKey($key, 'RS256');
                } catch (\Exception $e) {
                    error_log('ToolKeyResolver: JWK to PEM failed: ' . $e->getMessage());
                }
            }
        }

        return null;
    }
}
```

**Step 2: Commit**

```bash
git add src/Platform/ToolKeyResolver.php
git commit -m "feat(lti): add ToolKeyResolver for loading tool public keys"
```

---

## Task 3: Update Router Token Endpoint to Use WPPlatform

Replace the Router's token handling to use WPPlatform::sendAccessToken().

**Files:**
- Modify: `src/Platform/Router.php`

**Step 1: Add use statements at top of Router.php**

After existing use statements, add:

```php
use NetdustLTI\Platform\WPPlatform;
use NetdustLTI\Platform\ToolKeyResolver;
```

**Step 2: Replace handleTokenRequest method**

Replace the existing `handleTokenRequest()` method with:

```php
    /**
     * Handle OAuth2 token request for AGS.
     *
     * Uses celtic/lti Platform::sendAccessToken() which:
     * 1. Validates client_assertion JWT using Tool's public key
     * 2. Signs an access token JWT with Platform's private key
     * 3. Returns the token response
     */
    private function handleTokenRequest(): void
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonError('invalid_request', 'POST method required', 405);
        }

        // Validate grant type
        $grantType = $_POST['grant_type'] ?? '';
        if ($grantType !== 'client_credentials') {
            $this->sendJsonError('unsupported_grant_type', 'Only client_credentials supported', 400);
        }

        // Get client_assertion to find the tool
        $clientAssertion = $_POST['client_assertion'] ?? '';
        if (empty($clientAssertion)) {
            $this->sendJsonError('invalid_request', 'client_assertion required', 400);
        }

        // Configure Tool::$defaultTool with the requesting tool's public key
        $resolver = new ToolKeyResolver();
        $result = $resolver->configureToolFromAssertion($clientAssertion);

        if (is_wp_error($result)) {
            $this->sendJsonError('invalid_client', $result->get_error_message(), 401);
        }

        // Create WPPlatform and let the library handle token generation
        $platform = new WPPlatform();
        $platform->ok = true;

        // Supported AGS scopes
        $supportedScopes = [
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
            'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
        ];

        // Library handles JWT validation, signing, and response
        // This method calls exit() internally
        $platform->sendAccessToken($supportedScopes);
    }

    /**
     * Send JSON error response for OAuth2.
     */
    private function sendJsonError(string $error, string $description, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo wp_json_encode([
            'error' => $error,
            'error_description' => $description,
        ]);
        exit;
    }
```

**Step 3: Test token endpoint responds**

Run: `curl -X POST https://vad-vormingen.ddev.site/lti/platform/token -d "grant_type=client_credentials" 2>/dev/null | head -c 200`
Expected: JSON error response (invalid request, but proves endpoint works)

**Step 4: Commit**

```bash
git add src/Platform/Router.php
git commit -m "refactor(lti): use WPPlatform::sendAccessToken() for token endpoint"
```

---

## Task 4: Refactor AGSReceiver to Use Library Authorization

Keep grade storage logic, replace token validation with library's verifyAuthorization().

**Files:**
- Modify: `src/Platform/AGSReceiver.php`

**Step 1: Update use statements**

Add after existing use statements:

```php
use NetdustLTI\Platform\WPPlatform;
```

**Step 2: Replace validateToken method**

Replace the `validateToken()` method with one that uses the library:

```php
    /**
     * Validate JWT access token using library.
     *
     * @param string $token JWT Bearer token from Authorization header
     * @return array|WP_Error Token claims on success, WP_Error on failure
     */
    private function validateToken(string $token): array|WP_Error
    {
        // Create platform instance for verification
        $platform = new WPPlatform();

        // Required scopes for grade submission
        $requiredScopes = [
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
        ];

        // Library verifies JWT signature and checks scopes
        // Returns true if valid, sets $platform->reason on failure
        if (!$platform->verifyAuthorization($requiredScopes)) {
            $reason = $platform->reason ?? 'Token verification failed';
            error_log('AGSReceiver: Token validation failed - ' . $reason);
            return new WP_Error('invalid_token', $reason);
        }

        // Token is valid - extract claims for grade storage context
        // The library has validated the token, we just need tool context
        return [
            'valid' => true,
            'scopes' => $requiredScopes,
        ];
    }
```

**Step 3: Update processGradeSubmission to handle simplified token data**

In `processGradeSubmission()`, after token validation, update the tool_id extraction:

Find this section:
```php
        // Tool ID from validated token, resource link from URL path
        $toolId = absint($tokenData['tool_id'] ?? 0);
```

Replace with:
```php
        // With library auth, we don't get tool_id from token
        // Use 0 as generic tool ID (grades are keyed by resource_link_id anyway)
        $toolId = 0;
```

**Step 4: Remove the unused TokenEndpoint reference**

Remove the import if present:
```php
// Remove this line if it exists:
// use NetdustLTI\Platform\TokenEndpoint;
```

**Step 5: Commit**

```bash
git add src/Platform/AGSReceiver.php
git commit -m "refactor(lti): use WPPlatform::verifyAuthorization() for grade auth"
```

---

## Task 5: Delete Deprecated Files

Remove the custom implementations that are now replaced by library usage.

**Files:**
- Delete: `src/Platform/TokenEndpoint.php`
- Delete: `src/Platform/PlatformTokenService.php` (if exists)
- Delete: `src/Platform/JWTBuilder.php`

**Step 1: Delete TokenEndpoint.php**

```bash
rm src/Platform/TokenEndpoint.php
```

**Step 2: Delete PlatformTokenService.php if exists**

```bash
rm -f src/Platform/PlatformTokenService.php
```

**Step 3: Update Router to remove old service references**

In `Router.php`, remove this method and its usage:

Find:
```php
    private function handleTokenRequest(): void
    {
        $endpoint = ntdst_get(TokenEndpoint::class);
        ...
    }
```

This should already be replaced in Task 3. Verify no references remain to TokenEndpoint.

**Step 4: Verify no broken references**

```bash
grep -r "TokenEndpoint" src/
grep -r "PlatformTokenService" src/
```

Expected: No results (or only the new sendJsonError helper if named similarly)

**Step 5: Commit**

```bash
git add -A
git commit -m "chore(lti): delete deprecated TokenEndpoint and PlatformTokenService"
```

---

## Task 6: Keep JWTBuilder for Launch Flow (Do Not Delete)

**Important:** After reviewing the code, JWTBuilder.php handles the OIDC auth callback and creates the id_token for launching tools. This is a *different* flow from AGS tokens.

- **Token endpoint**: Tool requests access_token to POST grades (OAuth2 client_credentials)
- **Auth callback**: Platform creates id_token for launching into tool (OIDC)

JWTBuilder.php should be kept as it handles the launch flow correctly. The only issue was the AGS token flow in TokenEndpoint.php.

**Files:**
- Keep: `src/Platform/JWTBuilder.php` (handles launch flow, not AGS)

**Step 1: Verify JWTBuilder is still referenced**

```bash
grep -r "JWTBuilder" src/Platform/Router.php
```

Expected: Reference in handleAuthCallback()

**Step 2: Document decision**

No code changes needed. JWTBuilder stays. Add a comment if desired.

---

## Task 7: Manual Integration Test

Test the complete grade passback flow.

**Step 1: Run stride's test script**

```bash
cd /home/ntdst/Sites/stride
ddev exec wp eval-file scripts/test-grade-user1221.php
```

Expected: Grade posted successfully (or meaningful error message)

**Step 2: Check vad-vormingen logs for token request**

```bash
tail -50 /home/ntdst/Sites/vad-vormingen/web/app/debug.log | grep -i "lti\|token\|platform"
```

Expected: No JWT signature errors, token issued successfully

**Step 3: Verify grade was stored**

```bash
cd /home/ntdst/Sites/stride
ddev exec wp user meta get 1221 _lti_grades
```

Expected: Grade data with score and timestamp

---

## Task 8: Update Service Registration (if needed)

Check if TokenEndpoint or PlatformTokenService were registered in plugin config.

**Files:**
- Check: Plugin config/service registration file

**Step 1: Search for service registrations**

```bash
grep -r "TokenEndpoint\|PlatformTokenService" .
```

**Step 2: Remove any registrations found**

If found in a config array, remove the entries.

**Step 3: Commit if changes made**

```bash
git add -A
git commit -m "chore(lti): remove deleted services from registration"
```

---

## Summary of Changes

| File | Action | Purpose |
|------|--------|---------|
| `src/Platform/WPPlatform.php` | Create | Extends library Platform with WP config |
| `src/Platform/ToolKeyResolver.php` | Create | Loads tool public key for JWT validation |
| `src/Platform/Router.php` | Modify | Uses WPPlatform for token endpoint |
| `src/Platform/AGSReceiver.php` | Modify | Uses WPPlatform::verifyAuthorization() |
| `src/Platform/TokenEndpoint.php` | Delete | Replaced by WPPlatform |
| `src/Platform/PlatformTokenService.php` | Delete | Replaced by WPPlatform |
| `src/Platform/JWTBuilder.php` | Keep | Handles OIDC launch flow (separate concern) |

## Success Criteria

1. ✅ Token endpoint returns signed JWT access tokens (not random strings)
2. ✅ Grade passback validates JWT signature (not transient lookup)
3. ✅ Stride can POST grades to vad-vormingen without 401 errors
4. ✅ LTI launches still work (JWTBuilder unchanged)
5. ✅ Deep linking still works (separate flow)
