# ntdst-auth Plugin Design

**Date:** 2026-02-22
**Status:** Approved

## Overview

Custom authentication plugin for WordPress with magic link login, configurable registration, and GDPR compliance. Depends on ntdst-core for routing, DI container, and email.

## Requirements

- Magic link authentication (primary)
- Optional password login (configurable)
- Registration with email activation
- Configurable URLs via WP Admin settings
- Configurable registration fields
- GDPR compliant with consent tracking
- Redirect wp-login.php to custom login
- UIkit 3 + Alpine.js frontend
- Reusable across projects

## Plugin Structure

```
web/app/plugins/
└── ntdst-auth/
    ├── ntdst-auth.php              # Plugin bootstrap & header
    ├── plugin-config.php           # Service registration
    ├── src/
    │   ├── AuthService.php         # Magic link & password authentication
    │   ├── RegistrationService.php # User creation, activation, field validation
    │   ├── TokenService.php        # Secure token generation & verification
    │   ├── ConsentService.php      # GDPR consent logging
    │   ├── SettingsService.php     # Admin settings page & options
    │   └── Handlers/
    │       └── AuthHandler.php     # AJAX endpoints for all auth actions
    ├── templates/
    │   ├── pages/
    │   │   ├── login.php           # Login page (magic link + optional password)
    │   │   ├── register.php        # Registration form
    │   │   ├── activate.php        # Account activation landing
    │   │   └── reset-password.php  # Password reset (if password enabled)
    │   └── emails/
    │       ├── magic-link.php      # Magic link email
    │       ├── activation.php      # Account activation email
    │       ├── already-registered.php
    │       └── welcome.php         # Post-activation welcome
    ├── assets/
    │   ├── css/
    │   │   └── auth.css            # UIkit-based styling
    │   └── js/
    │       └── auth.js             # Alpine.js components
    └── admin/
        └── settings.php            # Settings page template
```

**Namespace:** `NTDST\Auth\`

## Data Storage

No custom database tables. Uses WordPress native storage.

### Tokens (Transients)

```php
set_transient('ntdst_auth_magic_' . $token_hash, [
    'email' => 'user@example.com',
    'user_id' => 123,
    'created' => time(),
    'uses' => 0,
    'max_uses' => 3,
], 15 * MINUTE_IN_SECONDS);
```

Token policy:
- Valid for 15 minutes AND up to 3 uses
- Handles email scanner pre-fetching
- Deleted after max uses or expiry

### Consent (User Meta)

```php
update_user_meta($user_id, 'ntdst_auth_consent', [
    'terms' => true,
    'privacy' => true,
    'version' => '1.0',
    'timestamp' => time(),
    'ip' => $ip,
]);

update_user_meta($user_id, 'ntdst_auth_activated', true);
update_user_meta($user_id, 'ntdst_auth_activated_at', time());
```

### Settings (Options)

```php
get_option('ntdst_auth_settings', [
    'login_url' => '/login',
    'register_url' => '/register',
    'activate_url' => '/activate',
    'redirect_after_login' => '/',
    'redirect_after_logout' => '/login',
    'enable_password' => false,
    'enable_magic_link' => true,
    'registration_fields' => ['email', 'first_name', 'last_name'],
    'magic_link_expiry' => 15,
    'activation_link_expiry' => 48,
    'terms_url' => '/terms',
    'privacy_url' => '/privacy',
]);
```

## URL Routing

Via ntdst-core Router:

```php
ntdst_router()->get($settings['login_url'], ...);
ntdst_router()->get($settings['register_url'], ...);
ntdst_router()->get('/auth/verify/{token}', ...);
ntdst_router()->get('/auth/logout', ...);
```

Templates overridable in theme: `themes/{theme}/ntdst-auth/pages/login.php`

## Authentication Flows

### Magic Link Flow

1. User enters email on /login
2. AJAX validates email, ALWAYS returns "Check your inbox"
3. Background: only send email if user exists & activated
4. User clicks /auth/verify/{token}
5. Verify token, set auth cookie, redirect

### Password Login Flow

1. User enters email + password
2. Generic error: "Invalid email or password"
3. Check activation status
4. Set auth cookie, redirect

### Registration Flow

1. User fills form with configurable fields
2. ALWAYS returns "Check your inbox"
3. Background: create user or send "already registered" email
4. User clicks activation link
5. Activate account, set auth cookie, send welcome email

### Security: No Email Enumeration

Same response regardless of email existence to prevent enumeration attacks.

## Settings Page

Location: Settings → Authentication

### Tabs

1. **URLs** - Login, register, activate, redirects
2. **Authentication Methods** - Enable magic link, password, expiry times
3. **Registration** - Enable registration, required fields, policy URLs
4. **Security** - Rate limits, wp-login redirect

## Frontend UI

UIkit 3 + Alpine.js components:

- Clean, centered card layout
- Form → sending → success state transitions
- Error handling with inline messages
- Configurable branding (logo slot)

## Email Templates

Via `ntdst_mail()->template()`:

1. **magic-link.php** - Sign in link (15 min expiry)
2. **activation.php** - Account activation (48 hour expiry)
3. **already-registered.php** - Sent when email already exists
4. **welcome.php** - Post-activation welcome

## Security

### Token Security
- 32 bytes random, URL-safe
- Stored hashed (SHA-256)
- Constant-time comparison
- 3 uses max, 15 min expiry

### Rate Limiting
- Magic link: 3/email, 10/IP per 15 min
- Login: 5/IP per 15 min
- Registration: 3/IP per hour

### Input Validation
- Strict email validation
- All input sanitized
- CSRF via nonces

### Redirect Safety
- wp_validate_redirect for same-site only

## GDPR Compliance

### Consent
- Required checkbox at registration
- Stored in user meta with version, timestamp, IP
- Hooks for audit logging: `ntdst_auth_consent_recorded`

### WordPress Privacy Tools
- Data exporter registered
- Data eraser registered

### Policy Versioning
- Admin can bump version
- Optional re-consent prompt on version mismatch

## Extensibility Hooks

```php
// Consent
do_action('ntdst_auth_consent_recorded', $user_id, $consent_data);
do_action('ntdst_auth_consent_outdated', $user_id);

// Authentication
do_action('ntdst_auth_login_success', $user_id);
do_action('ntdst_auth_user_activated', $user_id);
do_action('ntdst_auth_registration_complete', $user_id);
```

## Testing

### Acceptance Test Scenarios (Browser-Level)

**VISITOR LOGIN FLOW:**

```
SCENARIO: Magic link request shows success regardless of email existence
  GIVEN: I am on /login
  WHEN: I enter any email and submit
  THEN: I see "Check your inbox" message
    AND: No indication whether email exists

SCENARIO: Valid magic link logs user in
  GIVEN: I have a valid magic link token
  WHEN: I click the link
  THEN: I am logged in and redirected to configured URL
    AND: Auth cookie is set
    AND: Token use count incremented in database

SCENARIO: Expired magic link shows friendly error
  GIVEN: I have an expired magic link (>15 min old)
  WHEN: I click the link
  THEN: I see "This link has expired" message
    AND: I see option to request new link
    AND: I am NOT logged in

SCENARIO: Exhausted magic link (3 uses) shows error
  GIVEN: I have a magic link that was used 3 times
  WHEN: I click the link
  THEN: I see "This link is no longer valid" message
    AND: I am NOT logged in
```

**REGISTRATION FLOW:**

```
SCENARIO: Registration creates pending user
  GIVEN: I am on /register
  WHEN: I fill required fields (email, name) and accept terms
  THEN: I see "Check your inbox" message
    AND: User exists in database with ntdst_auth_activated=false
    AND: Consent meta is stored with timestamp and IP

SCENARIO: Activation link activates account
  GIVEN: I registered and have activation token
  WHEN: I click the activation link
  THEN: I am logged in and see welcome message
    AND: ntdst_auth_activated=true in database
    AND: Welcome email is sent

SCENARIO: Already registered email sends notification
  GIVEN: user@example.com already exists
  WHEN: I try to register with user@example.com
  THEN: I see same "Check your inbox" message (no enumeration)
    AND: "Already registered" email is sent to user
```

**ERROR FLOWS:**

```
SCENARIO: Rate limit blocks excessive requests
  GIVEN: I sent 3 magic link requests in 15 minutes
  WHEN: I try to send another
  THEN: I see "Please wait before requesting another link"
    AND: No email is sent

SCENARIO: Invalid token shows error
  GIVEN: I have a malformed or tampered token
  WHEN: I visit /auth/verify/{bad_token}
  THEN: I see "Invalid link" message
    AND: I am NOT logged in
```

**ADMIN FLOW:**

```
SCENARIO: Admin can configure auth settings
  GIVEN: I am logged in as admin
  WHEN: I go to Settings → Authentication
  THEN: I see tabs for URLs, Methods, Registration, Security
    AND: I can save changes
    AND: Changes persist after refresh
```

### Unit Test Coverage

| Service | Key Test Cases |
|---------|---------------|
| `TokenService` | Token generation, hashing, verification, expiry, use counting |
| `AuthService` | Magic link creation, password validation, session handling |
| `RegistrationService` | User creation, field validation, activation |
| `ConsentService` | Consent recording, version checking, GDPR export/erase |
| `SettingsService` | Option retrieval, defaults, validation |

## Dependencies

- ntdst-core (Router, Container, Mailer)
- WordPress 6.0+
- PHP 8.1+
