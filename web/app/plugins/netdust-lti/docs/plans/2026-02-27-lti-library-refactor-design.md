# LTI Plugin Refactor Design

## Goal

Refactor netdust-lti plugin to properly use the celtic/lti library instead of custom LTI protocol implementations.

## Problem

The current implementation reimplements LTI protocol code that the celtic/lti library already provides:
- `TokenEndpoint.php` - generates random access tokens instead of signed JWTs
- `AGSReceiver.php` - validates tokens via transient lookup instead of cryptographic verification
- `JWTBuilder.php` - manually builds JWT instead of using library's `addSignature()`

This causes issues like the grade passback 401 errors because the token flow doesn't match LTI spec.

## Approach

**Library-First Wrapper Pattern**: Extend/use library classes directly with thin WordPress wrappers.

## Architecture

```
netdust-lti/
├── src/
│   ├── Platform/           # When this site LAUNCHES external tools
│   │   ├── WPPlatform.php  # NEW: Extends ceLTIc\LTI\Platform
│   │   ├── Router.php      # Modified to use WPPlatform
│   │   ├── ToolRepository.php  # Keep as-is
│   │   ├── OIDCInitiator.php   # Refactor to use library methods
│   │   └── DeepLinkReceiver.php # Keep as-is
│   │
│   ├── ToolProvider/       # When this site RECEIVES launches
│   │   ├── Tool.php        # Keep (already extends library)
│   │   ├── WPDataConnector.php  # Keep
│   │   └── Services/       # Keep
│   │
│   └── Shared/
│       └── Domain/         # Keep
```

## WPPlatform Class

New class extending `ceLTIc\LTI\Platform`:

```php
class WPPlatform extends \ceLTIc\LTI\Platform
{
    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector ?? new WPDataConnector());

        $this->rsaKey = get_option('netdust_lti_private_key');
        $this->kid = get_option('netdust_lti_kid');
        $this->signatureMethod = 'RS256';
        $this->accessTokenUrl = home_url('/lti/platform/token');
    }

    protected function onInitiateLogin(&$url, &$loginHint, &$ltiMessageHint, $params): void
    {
        // Store state for callback
    }

    protected function onAuthenticate(): void
    {
        // Load launch context, configure Tool::$defaultTool
    }

    protected function onError(): void
    {
        wp_die($this->reason, 'LTI Error', ['response' => 400]);
    }
}
```

## Token Endpoint Flow

```
Tool POST /lti/platform/token
  ├─ client_assertion (JWT signed by Tool's private key)
  └─ scope (AGS scopes requested)

Router:
  ├─ Extract client_id from JWT's iss claim
  ├─ Find Tool, configure Tool::$defaultTool with Tool's public key
  ├─ Create WPPlatform instance
  └─ Call $platform->sendAccessToken($supportedScopes)
      └─ Library validates client_assertion
      └─ Library signs access token JWT with Platform's rsaKey
      └─ Returns signed JWT access token
```

## Grade Passback Flow

```
Tool POST /lti/platform/grades
  ├─ Authorization: Bearer <JWT access token>
  └─ JSON body with scoreGiven, userId, etc.

Router:
  ├─ Create WPPlatform instance
  ├─ Call $platform->verifyAuthorization($agsScopes)
      └─ Library verifies JWT signature
      └─ Library checks scopes
  ├─ If valid: parse JSON, store grade, fire hook
  └─ Return success
```

## Files Changed

| File | Action |
|------|--------|
| `Platform/WPPlatform.php` | Create |
| `Platform/Router.php` | Modify |
| `Platform/OIDCInitiator.php` | Refactor |
| `Platform/AGSReceiver.php` | Refactor (keep grade storage, use library auth) |
| `Platform/JWTBuilder.php` | Delete |
| `Platform/TokenEndpoint.php` | Delete |
| `Platform/PlatformTokenService.php` | Delete |

## Success Criteria

1. Grade passback: stride can POST grades to vad-vormingen
2. LTI launches: vad-vormingen can launch courses on stride
3. Deep linking: course selection flow works

## Testing

1. Run existing test scripts after refactor
2. Manual: Launch course from vad-vormingen to stride
3. Manual: Complete course, verify grade passback
4. Check logs for JWT/signature errors
