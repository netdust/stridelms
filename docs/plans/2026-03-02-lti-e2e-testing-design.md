# LTI Tool Provider E2E Testing — Design

**Date:** 2026-03-02
**Scope:** Tool Provider endpoints only. Platform side deferred.

---

## 1. Architecture

Two test suites targeting different layers:

- **Playwright** (`tests/frontend/lti/`) — Browser-accessible endpoints: config JSON/XML, JWKS, admin settings page, dynamic registration error handling
- **PHPUnit Integration** (`tests/Integration/NetdustLTI/`) — Full WordPress with mock platform helper: JWT launch flow, user provisioning, course enrollment, grade passback, WPDataConnector CRUD

---

## 2. Mock LTI Platform Helper

**File:** `tests/Integration/NetdustLTI/MockLtiPlatform.php`

A test helper class (not a service) that simulates an LMS platform:

- Generates 2048-bit RSA key pair (cached per test class)
- Creates `lti_platform` CPT via Data Manager with test keys/endpoints
- Builds valid LTI 1.3 JWT id_tokens using celtic/lti's `Jwt\FirebaseClient`
- Sets `$_POST` superglobals for `Tool::handleRequest()` consumption
- Factory methods: `createLaunchRequest(array $overrides)`, `createDeepLinkRequest()`

The mock platform's RSA public key is stored in the CPT's `rsa_key` field so the Tool validates JWT signatures without external JWKS fetches.

---

## 3. Playwright Tests (~20 test cases)

### `tests/frontend/lti/config-json.spec.ts`
- GET `/lti/configure-json` returns 200 JSON
- Contains required IMS fields: `title`, `oidc_initiation_url`, `target_link_uri`, `jwks_uri`, `claims`, `messages`, `scopes`
- All URLs point to `/lti/*` paths
- Messages include `LtiResourceLinkRequest` and `LtiDeepLinkingRequest`
- AGS scopes present (lineitem, score)

### `tests/frontend/lti/config-xml.spec.ts`
- GET `/lti/configure-xml` returns 200 XML
- Valid XML with `cartridge_basiclti_link` root element
- Contains `blti:title`, `blti:launch_url`, Canvas extensions

### `tests/frontend/lti/jwks.spec.ts`
- GET `/lti/jwks` returns 200 JSON
- Contains `keys` array with at least one key
- Key has required JWK fields: `kty`, `n`, `e`, `kid`, `use`

### `tests/frontend/lti/admin-settings.spec.ts`
- Requires admin login (via `auth.setup.ts` storageState)
- Settings > Netdust LTI page shows all 7 endpoint URLs
- Copy buttons exist for each endpoint
- Links to Manage Platforms, Manage Tools, Launch Test present

### `tests/frontend/lti/registration.spec.ts`
- GET `/lti/register` without login → 403
- GET `/lti/register` as admin without params → 400
- GET `/lti/register?openid_configuration=not-a-url` as admin → 400

### `tests/frontend/lti/auth.setup.ts`
- Logs in as admin, saves cookies to `tests/frontend/.auth/admin.json`
- Reused by admin-settings and registration tests

---

## 4. PHPUnit Integration Tests (~25-30 test cases)

### `tests/Integration/NetdustLTI/LtiLaunchFlowTest.php`
- Full launch: JWT → Tool::handleRequest() → user created with correct username (`given.family`), correct role (from platform meta), scoped sub stored
- Launch with `ld_course_id` custom param → user enrolled in LearnDash course
- Launch with existing user (same email) → no duplicate, existing user logged in
- Launch with existing user (same scoped sub) → same user matched
- Launch with invalid JWT signature → `$tool->ok === false`
- Launch with disabled platform → rejected

### `tests/Integration/NetdustLTI/WPDataConnectorTest.php`
- Platform CRUD: create → load by record ID → load by issuer+client → update → delete
- Nonce lifecycle: save → load (exists) → TTL expiry
- Access token: save → load → verify scopes
- Context CRUD: save → load → update settings → delete
- `netdust_lti_platform_registered` action fires on new platform

### `tests/Integration/NetdustLTI/ConfigEndpointIntegrationTest.php`
- JSON config built with real `home_url()` values
- XML config contains correct domain from `wp_parse_url()`
- Blog name appears in both configs

### `tests/Integration/NetdustLTI/GradePassbackIntegrationTest.php`
- `netdust_lti_grade_payload` filter modifies payload
- `netdust_lti_should_post_grade` filter suppresses posting
- AGS context in user meta accessible by GradePassbackService

---

## 5. Test Data & Setup

**RSA Keys:**
- MockLtiPlatform generates key pair once per test class (`setUpBeforeClass`)
- Tool keys set via `update_option()` in integration bootstrap

**Cleanup:**
- Extends `IntegrationTestCase` for automatic CPT/user cleanup
- Transients auto-expire (no manual cleanup)

**Playwright Auth:**
- `auth.setup.ts` logs in as admin, saves `storageState` to `.auth/admin.json`
- Admin tests depend on setup project

**Rewrite Rules:**
- Integration tests call `flush_rewrite_rules()` in bootstrap
- Playwright relies on existing DDEV routes

**LearnDash:**
- Enrollment tests check for LearnDash availability, skip if not active

---

## Files

### New Files

| File | Purpose |
|------|---------|
| `tests/Integration/NetdustLTI/MockLtiPlatform.php` | Test helper: RSA keys, platform CPT, JWT generation |
| `tests/Integration/NetdustLTI/LtiLaunchFlowTest.php` | Full launch flow integration tests |
| `tests/Integration/NetdustLTI/WPDataConnectorTest.php` | DataConnector CRUD integration tests |
| `tests/Integration/NetdustLTI/ConfigEndpointIntegrationTest.php` | Config endpoint integration tests |
| `tests/Integration/NetdustLTI/GradePassbackIntegrationTest.php` | Grade passback integration tests |
| `tests/frontend/lti/auth.setup.ts` | Playwright admin login setup |
| `tests/frontend/lti/config-json.spec.ts` | Config JSON endpoint tests |
| `tests/frontend/lti/config-xml.spec.ts` | Config XML endpoint tests |
| `tests/frontend/lti/jwks.spec.ts` | JWKS endpoint tests |
| `tests/frontend/lti/admin-settings.spec.ts` | Admin settings page tests |
| `tests/frontend/lti/registration.spec.ts` | Dynamic registration error tests |

### Modified Files

| File | Changes |
|------|---------|
| `playwright.config.ts` | Add setup project for admin auth |
| `tests/Integration/bootstrap.php` | Add LTI key setup + flush_rewrite_rules |
