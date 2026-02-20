# Netdust LTI Plugin Design

**Date:** 2025-02-20
**Status:** Approved
**Branch:** `netdust-lti`
**Execution Mode:** Rigorous (per-task mini-plans, TDD, self-review)

---

## Overview

Custom WordPress plugin for LTI 1.3 integration with LearnDash and TinCanny. Enables external LMS platforms (Moodle, Canvas, Blackboard) to embed LearnDash courses with automatic grade passback.

**Scope:** Scenario A only — LearnDash as Tool (external LMS launches into LearnDash).

---

## Architecture: Hybrid Approach

Use `celtic/lti` library for protocol handling (JWT, OIDC, AGS), own the business logic and persistence.

```
LTI Endpoints (LaunchHandler, DeepLinkHandler)
    ↓ uses
celtic/lti Tool class (JWT parsing, OIDC, AGS)
    ↓ persists via
WPDataConnector (our implementation of library interface)
    ↓ stores in
WordPress tables (our schema)
    ↓
Stride Services (UserProvisioner, GradePassback, CourseEnroller)
    ↓ follow
AbstractService patterns, use ntdst_get(), dispatch events
```

**Boundary:**
- `celtic/lti` = "How do I validate this JWT? How do I post a score to AGS?"
- Our services = "Should this user get enrolled? Which courses trigger grade passback?"

---

## Plugin Structure

```
/web/app/plugins/netdust-lti/
├── netdust-lti.php                 # Plugin header, activation hooks, NTDST Core check
├── composer.json                   # celtic/lti dependency
├── vendor/                         # Composer autoload
├── README.md                       # Setup guide, endpoint reference, troubleshooting
│
├── src/
│   ├── Plugin.php                  # Bootstrap, registers services, endpoints
│   │
│   ├── LTI/                        # Protocol layer (wraps celtic/lti)
│   │   ├── LaunchHandler.php       # OIDC login + JWT launch validation
│   │   ├── DeepLinkHandler.php     # Course picker + response
│   │   ├── JwksHandler.php         # Public key endpoint
│   │   └── EndpointRouter.php      # Maps /lti/* URLs to handlers
│   │
│   ├── Services/                   # Business logic (Stride patterns)
│   │   ├── UserProvisioner.php     # Find/create WP user from LTI claims
│   │   ├── CourseEnroller.php      # Auto-enroll in LearnDash course
│   │   ├── GradePassbackService.php# Push scores to AGS
│   │   └── LaunchLogService.php    # Record launches for debugging
│   │
│   ├── Bridges/                    # Integration hooks
│   │   ├── LearnDashBridge.php     # LD completion/quiz hooks → grade passback
│   │   └── TinCannyBridge.php      # xAPI completion hooks → grade passback
│   │
│   ├── Repositories/               # Data access
│   │   ├── PlatformRepository.php  # CRUD for registered platforms
│   │   ├── ContextRepository.php   # LTI context ↔ LD course mappings
│   │   └── NonceRepository.php     # Short-lived nonces (replay prevention)
│   │
│   ├── DataConnector/
│   │   └── WPDataConnector.php     # celtic/lti DataConnector implementation
│   │
│   ├── Domain/                     # Value objects
│   │   ├── Platform.php            # Immutable platform config
│   │   ├── LtiClaims.php           # Parsed JWT claims
│   │   └── GradePayload.php        # AGS score structure
│   │
│   └── Admin/                      # Admin UI
│       ├── AdminPage.php           # Main settings page
│       ├── PlatformListTable.php   # WP_List_Table for platforms
│       └── CourseSettingsMetabox.php # Per-course grade passback options
│
├── templates/
│   ├── admin/
│   │   ├── settings-page.php       # Main admin template
│   │   ├── platform-form.php       # Add/edit platform
│   │   └── logs.php                # Launch log viewer
│   └── deep-link-picker.php        # Teacher course selection UI
│
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
│
└── database/
    └── Migrations.php              # Table creation on activation
```

---

## Database Schema

```sql
-- Registered LTI platforms (Moodle, Canvas, etc.)
CREATE TABLE {$prefix}netdust_lti_platforms (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    platform_id     VARCHAR(255) NOT NULL,          -- Issuer URL
    client_id       VARCHAR(255) NOT NULL,
    deployment_id   VARCHAR(255) DEFAULT NULL,
    auth_endpoint   VARCHAR(512) NOT NULL,
    token_endpoint  VARCHAR(512) NOT NULL,
    jwks_endpoint   VARCHAR(512) NOT NULL,
    enabled         TINYINT(1) DEFAULT 1,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,

    UNIQUE KEY platform_client (platform_id, client_id)
);

-- LTI context ↔ LearnDash course mappings
CREATE TABLE {$prefix}netdust_lti_contexts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform_id     BIGINT UNSIGNED NOT NULL,
    lti_context_id  VARCHAR(255) NOT NULL,
    ld_course_id    BIGINT UNSIGNED NOT NULL,
    resource_link_id VARCHAR(255) DEFAULT NULL,
    line_item_url   VARCHAR(512) DEFAULT NULL,
    settings        JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,

    UNIQUE KEY context_resource (platform_id, lti_context_id, resource_link_id),
    KEY ld_course (ld_course_id)
);

-- Nonces (replay attack prevention)
CREATE TABLE {$prefix}netdust_lti_nonces (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform_id     BIGINT UNSIGNED NOT NULL,
    nonce           VARCHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,

    UNIQUE KEY platform_nonce (platform_id, nonce),
    KEY expires (expires_at)
);
```

**User meta:**
- `_netdust_lti_sub` — stable LTI subject ID
- `_netdust_lti_provisioned` — flag for LTI-created users
- `_netdust_lti_context_{course_id}` — current session context for grade passback

---

## LTI Endpoints

| URL | Handler | Purpose |
|-----|---------|---------|
| `/lti/login` | LaunchHandler | OIDC login initiation |
| `/lti/launch` | LaunchHandler | JWT validation + redirect |
| `/lti/jwks` | JwksHandler | Public key for platforms |
| `/lti/deep-link` | DeepLinkHandler | Course picker for teachers |

**Launch Flow:**

1. Platform POSTs to `/lti/login` with `iss`, `login_hint`, `target_link_uri`
2. We redirect to platform's auth endpoint
3. Platform authenticates, POSTs JWT to `/lti/launch`
4. We validate JWT (via celtic/lti), provision user, enroll in course
5. Login user, redirect to course

**Deep Link Flow:**

1. Platform POSTs deep link JWT to `/lti/deep-link`
2. We render course picker (list of sfwd-courses)
3. Teacher selects course
4. We send content item response back to platform

---

## User Provisioning

Match by email first, create if not found.

```php
class UserProvisioner
{
    public function provision(LtiClaims $claims): WP_User|WP_Error
    {
        // 1. Look up by LTI sub (most reliable for repeat launches)
        $userId = $this->findByLtiSub($claims->sub);

        // 2. Look up by email
        if (!$userId) {
            $existing = get_user_by('email', $claims->email);
            $userId = $existing?->ID;
        }

        // 3. Create new user
        if (!$userId) {
            $userId = $this->createUser($claims);
        }

        // Store LTI sub for future lookups
        update_user_meta($userId, '_netdust_lti_sub', $claims->sub);

        return get_user_by('id', $userId);
    }
}
```

---

## Grade Passback

Three configurable triggers per course:

| Trigger | Event | Hook |
|---------|-------|------|
| `course_complete` | LearnDash course completed | `learndash_course_completed` |
| `quiz_score` | LearnDash quiz completed | `learndash_quiz_completed` |
| `tincanny_complete` | TinCanny module completed | `tincanny_module_result_processed` |

**LearnDashBridge** and **TinCannyBridge** listen to these hooks, check for LTI context, and dispatch to **GradePassbackService**.

---

## Logging

Uses existing `ntdst_log()` with LTI-specific channels:

```php
ntdst_log('lti')->info('JWT validated', ['sub' => $sub, 'context' => $contextId]);
ntdst_log('lti')->error('Validation failed', ['error' => $message]);

ntdst_log('lti-grade')->info('Score posted', ['user_id' => $userId, 'score' => $score]);
ntdst_log('lti-grade')->error('AGS failed', ['response' => $response]);
```

Log files: `wp-content/logs/lti-YYYY-MM-DD.log`, `wp-content/logs/lti-grade-YYYY-MM-DD.log`

In production (WP_DEBUG=false), only warnings/errors are logged.

---

## Admin UI

**Menu:** Settings → Netdust LTI

**Screens:**

1. **Platforms** — List/add/edit registered LTI platforms + display tool endpoints
2. **Course Settings** — Metabox on sfwd-courses for grade passback toggles
3. **Logs** — Tabbed view of recent launches and grade passbacks

---

## Dependencies

- **NTDST Core** — Required (hard dependency). Uses DI container, service patterns.
- **celtic/lti** — Composer package for LTI 1.3 protocol handling.
- **LearnDash** — Required for course content and enrollment.
- **TinCanny** — Optional but expected. Grade passback works without it.

---

## Key Decisions

1. **Scenario A only** — LearnDash as Tool. Platform mode (Scenario B) deferred.
2. **Generic LTI 1.3** — Spec-compliant, test against 1EdTech reference implementation.
3. **Match by email** — User provisioning links existing accounts by email.
4. **Deep Linking required** — Teachers select specific courses during setup.
5. **Regular plugin** — `/web/app/plugins/netdust-lti/`, distributable.
6. **NTDST Core required** — Full integration with DI container and patterns.
7. **Per-course config** — Grade passback triggers configurable per course.
8. **Existing logger** — Uses `ntdst_log()`, no custom logging system.

---

## Security Checklist

- [ ] Validate JWT signature against platform JWKS
- [ ] Validate `iss`, `aud`, `nonce`, `exp` claims
- [ ] Check nonce hasn't been used (replay prevention)
- [ ] Validate `deployment_id` matches registered platform
- [ ] Only accept HTTPS endpoints
- [ ] Scope AGS access tokens appropriately
- [ ] Log all launches with IP for audit

---

## Build Phases

**Phase 1 — Core Launch (MVP)**
- OIDC login initiation
- JWT launch validation
- User provisioning
- Course enrollment
- Admin: platform registration
- Admin: endpoint display

**Phase 2 — Grade Passback**
- LearnDash course completion → AGS
- LearnDash quiz completion → AGS
- TinCanny completion → AGS
- Admin: per-course settings

**Phase 3 — Deep Linking**
- Deep link launch handling
- Course picker UI
- Content item response

**Phase 4 — Polish**
- Admin logs viewer
- Platform health checks
- Documentation
