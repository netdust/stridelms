# LTI Integration with LearnDash + TinCanny
## Deep Research: Building a Proper Custom Plugin

---

## 1. What Is LTI?

**LTI (Learning Tools Interoperability)** is an open standard by **1EdTech** (formerly IMS Global) that allows a **Platform** (your LMS — LearnDash) to launch a **Tool** (an external app like a quiz engine, SCORM player, video tool) in a secure, seamless way — without the user needing to log in again.

Think of it as OAuth for eLearning: the Platform sends a signed, encrypted JWT message to the Tool, which proves who the user is, what course they're in, and what role they have.

### LTI 1.3 / LTI Advantage (current version)
This is the version you want. It replaces the old LTI 1.1 (which used simple OAuth 1.0 shared secrets).

| Layer | What it does |
|---|---|
| **LTI 1.3 Core** | Secure OIDC-based launch (JWT tokens, OAuth 2.0) |
| **AGS** — Assignment & Grade Services | Tool can push grades back to the Platform's gradebook |
| **NRPS** — Names & Role Provisioning | Tool can pull the course roster from the Platform |
| **Deep Linking 2.0** | Teacher picks specific content inside the Tool and embeds it in the Platform course |

### Two Roles
- **Platform (consumer):** Your WordPress/LearnDash site when you want to *embed external tools* inside your courses
- **Tool (provider):** Your WordPress/LearnDash site when an *external LMS* (Moodle, Canvas, Blackboard) wants to embed your LearnDash courses inside their system

Both roles are relevant depending on which side your client is on.

---

## 2. The Existing Plugin Stack — Why It's Crappy

### Current ecosystem
There are 3 separate plugins you'd need to stack:

1. **`celtic-lti`** (ceLTIc LTI Library) — base PHP library wrapping the `celtic/lti` Composer package
2. **`lti-tool`** (WordPress LTI Connector) — adds LTI Platform management UI to WordPress
3. **`lti-tool-learndash`** — thin LearnDash extension on top of the above

### Problems with the existing plugin stack

**Architecture problems:**
- Depends on a chain of 3 plugins that must all be active and version-compatible
- The LearnDash connector (`lti-tool-learndash`) has **only 1 star, 2 forks** on GitHub — essentially unmaintained
- PHP Fatal errors on PHP 8.1 (`Undefined constant "LEARNDASH_LMS_PLUGIN_URL"`) reported as recently as December 2024
- Not tested against LearnDash 4.x+ (last confirmed working was pre-4.0)
- The WP Hive test shows **activation failures on PHP 8.1 + WP 6.7**

**Functional gaps:**
- No grade passback (AGS) to the external platform out of the box
- No course-level content selection (Deep Linking) — you'd get the whole LearnDash site, not a specific course
- No roster sync (NRPS)
- TinCanny xAPI/SCORM data is **completely siloed** — zero bridge between what TinCanny records and what gets passed back to the calling LMS via LTI

**The core problem:** The existing plugin just handles the SSO/launch part. It creates a WordPress user on-the-fly from the LTI launch claim, logs them in, and dumps them on a page. That's LTI 1.1-era thinking. There's no Advantage services, no grade passback, no awareness of TinCanny.

---

## 3. What a Proper Custom Plugin Should Do

### Two scenarios to support

**Scenario A — LearnDash AS Tool (most common for you)**  
External LMS (Moodle at a university, Canvas, Blackboard) embeds your LearnDash course. Students from the external LMS launch into your LearnDash course via LTI. Completions and grades flow back automatically.

**Scenario B — LearnDash AS Platform**  
Your LearnDash site needs to embed external LTI tools (e.g., a university's lab simulator, a publisher's content library) inside a LearnDash lesson.

You probably want Scenario A primarily, with Scenario B as a nice-to-have.

---

## 4. Custom Plugin Architecture

### Foundation: `celtic/lti` Composer Library

Don't reinvent the LTI 1.3 security layer. The **`celtic/lti`** PHP library (via Composer) handles all the cryptographic complexity of LTI 1.3:
- OIDC login initiation
- JWT launch message parsing and verification  
- Public/private key management (JWKS endpoints)
- Access token requests for AGS/NRPS calls
- Dynamic Registration flow

```bash
composer require celtic/lti
```

This is the same battle-tested library that underpins the existing plugin stack. The difference is you build *directly* on it with clean, modern PHP — not through a 3-plugin dependency chain.

### Plugin Structure

```
/wp-content/plugins/netdust-lti/
├── netdust-lti.php               # Plugin header, bootstrap
├── composer.json                 # celtic/lti dependency
├── vendor/                       # Composer autoload
├── src/
│   ├── Admin/
│   │   ├── PlatformAdmin.php     # Register LTI platforms (where launches come from)
│   │   └── ToolAdmin.php         # Configure tool registration details
│   ├── LTI/
│   │   ├── LaunchHandler.php     # Handle OIDC login + launch JWT
│   │   ├── DeepLinkHandler.php   # Deep Linking content selection
│   │   ├── GradePassback.php     # AGS: push grades back to platform
│   │   └── RosterSync.php        # NRPS: pull/sync course members
│   ├── LearnDash/
│   │   ├── UserProvisioner.php   # Create/find WP users from LTI claims
│   │   ├── CourseEnroller.php    # Auto-enroll in LearnDash courses
│   │   └── ProgressBridge.php   # Hook LearnDash completions → grade passback
│   ├── TinCanny/
│   │   └── XapiBridge.php        # Hook TinCanny xAPI statements → grade passback
│   └── DataConnector/
│       └── WPDataConnector.php   # Persist LTI state in custom WP tables
├── templates/
│   ├── launch-redirect.php       # OIDC redirect response
│   └── deep-link-picker.php      # Course picker UI for teachers
└── assets/
    └── admin.js
```

### Database Tables

The plugin needs a few custom tables (the ceLTIc library will use your DataConnector to read/write these):

```sql
-- LTI Platforms (Moodle, Canvas etc. that can launch into your site)
CREATE TABLE wp_netdust_lti_platforms (
  platform_pk     BIGINT AUTO_INCREMENT PRIMARY KEY,
  platform_id     VARCHAR(255),          -- issuer URL
  client_id       VARCHAR(255),
  deployment_id   VARCHAR(255),
  public_key      TEXT,                  -- platform's JWKS/public key
  auth_endpoint   VARCHAR(512),          -- OIDC auth endpoint
  token_endpoint  VARCHAR(512),          -- OAuth2 token endpoint
  jwks_endpoint   VARCHAR(512),
  name            VARCHAR(100),
  enabled         TINYINT DEFAULT 1,
  created         DATETIME,
  updated         DATETIME
);

-- LTI Contexts (Course in external LMS ↔ LearnDash course mapping)
CREATE TABLE wp_netdust_lti_contexts (
  context_pk      BIGINT AUTO_INCREMENT PRIMARY KEY,
  platform_pk     BIGINT,
  lti_context_id  VARCHAR(255),           -- course ID in external LMS
  ld_course_id    BIGINT,                 -- LearnDash post ID
  line_item_url   VARCHAR(512),           -- AGS line item for grade passback
  settings        LONGTEXT,              -- JSON
  created         DATETIME,
  updated         DATETIME
);

-- Nonces (short-lived, LTI 1.3 security)
CREATE TABLE wp_netdust_lti_nonces (
  consumer_pk     BIGINT,
  value           VARCHAR(50),
  expires         DATETIME,
  PRIMARY KEY (consumer_pk, value)
);
```

---

## 5. The LTI 1.3 Launch Flow (What to implement)

```
External LMS (Platform)                    Your WP/LearnDash (Tool)
─────────────────────────────────────────────────────────────────
1. Teacher adds your tool to their course
   → They use your Tool URL + Client ID

2. Student clicks the course link in external LMS
   → POST to your OIDC Login Initiation URL
     e.g. https://yoursite.com/lti/login

3. Your LaunchHandler redirects to Platform's auth endpoint
   → GET https://moodle.edu/auth/token?...

4. Platform authenticates, sends signed JWT to your Launch URL
   → POST https://yoursite.com/lti/launch
     JWT contains: user email, roles, course context, 
                   resource link, Deep Link claim...

5. Your LaunchHandler validates JWT signature against JWKS
   → Find or create WP user (UserProvisioner)
   → Find or create LearnDash course enrollment (CourseEnroller)
   → Log user in, redirect to correct course/lesson

6. Student completes course/SCORM module
   → LearnDash fires completion hook
   → TinCanny fires xAPI completion statement
   → GradePassback.php calls AGS Score endpoint
     POST https://moodle.edu/lti/scores
     { userId, scoreGiven, scoreMaximum, activityProgress, gradingProgress }

7. Grade appears in external LMS gradebook automatically ✓
```

---

## 6. TinCanny Integration (The Missing Piece)

TinCanny stores xAPI statements in `wp_tin_canny_tincan` table. It also fires WordPress actions you can hook into.

### Hook into TinCanny completions

```php
// When a SCORM/xAPI module is completed in TinCanny
add_action('uo_tin_canny_module_completed', function($user_id, $module_id, $data) {
    // Get the LTI context for this user's current session
    $lti_context = get_user_meta($user_id, '_netdust_lti_context', true);
    if (!$lti_context) return;
    
    // Get the score from TinCanny data
    $score = $data['score']['raw'] ?? null;
    $max   = $data['score']['max'] ?? 100;
    
    // Push grade back via AGS
    $grader = new GradePassback($lti_context);
    $grader->postScore($user_id, $score, $max);
    
}, 10, 3);
```

### Hook into LearnDash completions

```php
// When a LearnDash course is completed
add_action('learndash_course_completed', function($data) {
    $user_id   = $data['user']->ID;
    $course_id = $data['course']->ID;
    
    $lti_context = get_user_meta($user_id, '_netdust_lti_context_' . $course_id, true);
    if (!$lti_context) return;
    
    $grader = new GradePassback($lti_context);
    $grader->postScore($user_id, 1, 1, 'Completed', 'FullyGraded');
});

// When a LearnDash quiz is completed
add_action('learndash_quiz_completed', function($data, $user) {
    $score     = $data['pass'] ? $data['score'] : $data['score'];
    $course_id = $data['course']->ID;
    $user_id   = $user->ID;
    
    $lti_context = get_user_meta($user_id, '_netdust_lti_context_' . $course_id, true);
    if (!$lti_context) return;
    
    $grader = new GradePassback($lti_context);
    $grader->postScore($user_id, $score, $data['count'], 'Completed', 'FullyGraded');
}, 10, 2);
```

---

## 7. Deep Linking (Teacher Course Selection)

Deep Linking 2.0 allows the teacher in the external LMS to pick which specific LearnDash course (or lesson) to embed, rather than just landing on your homepage.

### Flow
1. Teacher clicks "Add content from external tool" in Moodle/Canvas
2. LMS sends a Deep Link launch JWT to your site
3. You show the teacher a picker: list of LearnDash courses
4. Teacher selects "Advanced Photography Module 3"
5. You send back a Deep Link Response with a content item URL pointing to that exact course
6. LMS stores that URL — all future student launches go directly to that course

### Implementation
```php
class DeepLinkHandler {
    public function handle(Tool $tool): void {
        // Render course picker UI
        $courses = get_posts(['post_type' => 'sfwd-courses', 'numberposts' => -1]);
        
        // When teacher submits selection:
        $course_id = $_POST['course_id'];
        $course    = get_post($course_id);
        
        $content_item = [
            'type'  => 'ltiResourceLink',
            'title' => $course->post_title,
            'url'   => get_permalink($course_id),
            'custom' => ['ld_course_id' => $course_id]
        ];
        
        // Send Deep Link response JWT back to LMS
        $tool->sendDeepLinkResponse([$content_item]);
    }
}
```

---

## 8. User Provisioning Strategy

When a student from Moodle lands on your site via LTI, you need to get them a WordPress account:

```php
class UserProvisioner {
    public function provision(array $lti_claims): WP_User {
        $email = $lti_claims['email'];
        $sub   = $lti_claims['sub'];           // stable LTI user ID
        
        // First: look up by LTI subject ID (most reliable)
        $user_id = $this->findByLtiSub($sub);
        
        // Second: look up by email
        if (!$user_id) {
            $existing = get_user_by('email', $email);
            $user_id  = $existing ? $existing->ID : null;
        }
        
        // Third: create new user
        if (!$user_id) {
            $user_id = wp_create_user(
                $lti_claims['given_name'] . '_lti_' . substr($sub, 0, 8),
                wp_generate_password(),
                $email
            );
            update_user_meta($user_id, '_lti_sub', $sub);
            update_user_meta($user_id, '_lti_provisioned', 1);
        }
        
        return get_user_by('id', $user_id);
    }
}
```

**Important decisions to expose as admin settings:**
- Map by email vs. always create new accounts
- Auto-enroll in LearnDash courses (yes/no)
- Default WP role for LTI-provisioned users
- Allow LTI users to log in normally (yes/no)

---

## 9. Admin UI Requirements

The plugin needs a clean admin interface for each client site. Minimum screens:

### Platform Management
- List of registered LTI platforms (Moodle, Canvas, etc.)
- Add/edit platform: paste Platform ID, Client ID, Auth/Token/JWKS URLs
- Dynamic Registration support: auto-fill these from a single URL
- Enable/disable per platform

### Tool Registration Info
- Expose your endpoints clearly:
  - **OIDC Login URL:** `https://yoursite.com/?lti-tool=login`
  - **Launch URL:** `https://yoursite.com/?lti-tool=launch`
  - **JWKS URL:** `https://yoursite.com/?lti-tool=jwks`
  - **Deep Link URL:** `https://yoursite.com/?lti-tool=deep-link`
- Your public key (for platforms that don't support JWKS)
- Client ID (you generate per platform)

### Course Mapping
- Map external LMS course IDs → LearnDash courses (optional, for when Deep Linking isn't supported)
- Grade passback: which LearnDash events trigger a score push (course complete / quiz score / TinCanny xAPI complete)

### Logs / Debug
- LTI launch log (timestamped, with user, platform, claims summary)
- Grade passback log (score, timestamp, success/fail)

---

## 10. WordPress URL Handling

Use WordPress rewrite rules to expose the LTI endpoints cleanly:

```php
// In plugin init
add_rewrite_rule('^lti/login/?$', 'index.php?lti_action=login', 'top');
add_rewrite_rule('^lti/launch/?$', 'index.php?lti_action=launch', 'top');
add_rewrite_rule('^lti/jwks/?$', 'index.php?lti_action=jwks', 'top');
add_rewrite_rule('^lti/deep-link/?$', 'index.php?lti_action=deep-link', 'top');
add_rewrite_rule('^lti/dynamic-register/?$', 'index.php?lti_action=dynamic-register', 'top');

add_filter('query_vars', function($vars) {
    $vars[] = 'lti_action';
    return $vars;
});

add_action('parse_request', function($wp) {
    $action = $wp->query_vars['lti_action'] ?? null;
    if (!$action) return;
    
    $router = new LTI\Router();
    $router->handle($action); // dispatches to LaunchHandler, JWKS etc.
    exit;
});
```

### OIDC Cookie Problem
LTI 1.3 uses a state parameter stored server-side between the login initiation and the launch. On sites with SameSite=Lax cookies (most modern setups), the cross-origin POST back from the platform drops the session cookie. Use one of these strategies:

- **Platform Storage** (preferred): Store nonce/state in sessionStorage on the tool side via postMessages (1EdTech has a spec for this: `lti-cs-oidc`)
- **State in URL**: Embed the state value in the launch URL itself (simpler, slightly less clean)
- **Server-side session**: Store state keyed by a value passed in the `lti_message_hint` parameter

---

## 11. Security Checklist

- [ ] Validate JWT signature against platform JWKS (never trust unverified claims)
- [ ] Validate `iss`, `aud`, `nonce` claims
- [ ] Check nonce hasn't been used before (replay attack prevention) — store in DB with 10-min TTL
- [ ] Validate `deployment_id` matches registered platform
- [ ] Only accept HTTPS endpoints (reject HTTP platforms in production)
- [ ] Scope AGS access tokens (don't request LineItem scope if you only need Score)
- [ ] Store platform keys encrypted at rest
- [ ] Log all launches with IP for audit

---

## 12. Build Priority Order

**Phase 1 — Core Launch (MVP)**
- OIDC login initiation endpoint
- JWT launch validation
- User provisioning (create/find WP user)
- Auto-enroll in LearnDash course
- Session and redirect
- Admin: platform registration UI
- Admin: JWKS/endpoint display

**Phase 2 — Grade Passback**
- AGS: LearnDash course completion → score
- AGS: LearnDash quiz completion → score
- TinCanny: xAPI completion → score
- Admin: grade passback settings per course

**Phase 3 — Deep Linking**
- Deep Link launch handling
- Course picker UI for teachers
- Deep Link response (content item)

**Phase 4 — Advanced**
- NRPS: roster sync
- Dynamic Registration (auto-config from one URL)
- Scenario B: LearnDash AS platform (embed external LTI tools in LD lessons)
- Reporting: launch logs, passback logs

---

## 13. Key Resources

| Resource | URL |
|---|---|
| LTI 1.3 Spec | https://www.imsglobal.org/spec/lti/v1p3/ |
| LTI Advantage Implementation Guide | https://www.imsglobal.org/spec/lti/v1p3/impl/ |
| AGS Spec | https://www.imsglobal.org/spec/lti-ags/v2p0/ |
| Deep Linking Spec | https://www.imsglobal.org/spec/lti-dl/v2p0 |
| NRPS Spec | https://www.imsglobal.org/spec/lti-nrps/v2p0 |
| ceLTIc PHP Library | https://github.com/celtic-project/LTI-PHP |
| ceLTIc Packagist | https://packagist.org/packages/celtic/lti |
| 1EdTech Reference Impl (test your tool) | https://lti-ri.imsglobal.org/ |
| LTI Advantage Certification Validator | https://ltiadvantagevalidator.imsglobal.org/ |
| TinCanny Plugin | https://www.uncannyowl.com/downloads/tin-canny-reporting/ |
| LearnDash Hooks reference | https://www.learndash.com/support/docs/developers/ |
