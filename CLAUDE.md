# Stride LMS - Claude Code Guide

## Project Overview

**Stride** - Modern LMS platform for VAD training management. Clean rewrite of VAD Vormingen v3 with focus on simplicity, better UX, and maintainable code.

**Key Technologies:**
- Framework: NTDST Core (DI container, Bootstrap, Router)
- Stack: Bedrock WordPress
- LMS: LearnDash
- CRM/Forms: FluentCRM, FluentForms, Fluent SMTP
- Frontend: Tailwind CSS + Alpine.js + Vite (Stridence theme)

Operational config (hosting, deploy, SSH): `site.yml`. Memory: `memory/`. Tasks: `tasks/`.

---

## Architecture Overview

Stride follows WordPress mu-plugin architecture with clear separation:

- **stride-core** (mu-plugin): All business logic, services, data models
- **stride** (theme): Presentation only - templates, assets, frontend services

### Namespace Structure

| Location | Namespace | Purpose |
|----------|-----------|---------|
| `mu-plugins/stride-core/Modules/` | `Stride\Modules\{Module}\` | Domain modules (Edition, Enrollment, etc.) |
| `mu-plugins/stride-core/Handlers/` | `Stride\Handlers\` | AJAX handlers |
| `mu-plugins/stride-core/Admin/` | `Stride\Admin\` | Admin dashboard services |
| `mu-plugins/stride-core/Integrations/` | `Stride\Integrations\` | Third-party adapters (LearnDash) |
| `mu-plugins/stride-core/Contracts/` | `Stride\Contracts\` | Interfaces (LMSAdapterInterface, etc.) |
| `mu-plugins/stride-core/Domain/` | `Stride\Domain\` | Value objects (Money, EditionStatus, RegistrationStatus, etc.) |
| `mu-plugins/stride-core/Infrastructure/` | `Stride\Infrastructure\` | Abstract base classes (AbstractRepository, AbstractService, BatchQueryHelper) |
| `themes/stridence/services/frontend/` | `stridence\services\frontend` | Theme presentation services |

---

## Development Workflow Skills

Skills are invoked automatically or via `/skill-name`. They guide how Claude approaches tasks.

### Superpowers Workflow (Brainstorm → Plan → Implement)

The core development loop follows three phases:

| Phase | Skill | When |
|-------|-------|------|
| **Brainstorm** | `superpowers:brainstorming` | Before any creative work — features, components, modifications. Explores intent, requirements, and design before code. |
| **Plan** | `superpowers:writing-plans` | After brainstorming, when you have spec/requirements for a multi-step task. Produces a structured implementation plan. |
| **Implement** | `superpowers:executing-plans` | Execute a written plan in a session with review checkpoints. |
| **Implement (parallel)** | `superpowers:subagent-driven-development` | Execute plans with independent tasks using parallel subagents. |
| **Parallel dispatch** | `superpowers:dispatching-parallel-agents` | When facing 2+ independent tasks that need no shared state. |

### Testing

| Skill | When |
|-------|------|
| `superpowers:test-driven-development` | Before writing implementation code — write tests first. |
| `testing-workflow` | After every task (unit tests) and after every phase (integration + acceptance). Covers PHP (PHPUnit) and TypeScript (Vitest/Playwright). |

### NTDST Domain Skills

| Skill | When |
|-------|------|
| `ntdst-architecture` | Service lifecycle, DI container, routing, templating, PHP 8.1+ standards. Consult during planning and code review. |
| `ntdst-data` | Data models, CPTs, field definitions, metaboxes, REST API, caching. Consult during planning for any data-related work. |
| `ntdst-infra` | DDEV environments, Vite builds, git branching, Makefile workflows, deployment. Consult during planning for DevOps work. |

### Quality & Review

| Skill | When |
|-------|------|
| `review` | Review code for NTDST framework compliance and architecture rules. |
| `code-audit` | Audit existing features against framework patterns. |
| `simplify` | Review changed code for reuse, quality, and efficiency. |
| `superpowers:requesting-code-review` | Before merging — verify work meets requirements. |
| `superpowers:receiving-code-review` | When receiving feedback — verify before implementing suggestions. |

### Completion & Git

| Skill | When |
|-------|------|
| `superpowers:verification-before-completion` | Before claiming work is done — run verification, confirm output. |
| `superpowers:finishing-a-development-branch` | After all tests pass — guides merge, PR, or cleanup decisions. |
| `superpowers:using-git-worktrees` | When feature work needs isolation from current workspace. |

### Debugging

| Skill | When |
|-------|------|
| `superpowers:systematic-debugging` | Before proposing fixes for any bug, test failure, or unexpected behavior. |

### Critical Thinking

| Skill | When |
|-------|------|
| `thinking-deeply` | When facing confirmation-seeking questions, leading statements, binary choices, or embedded assumptions. Stop and think rigorously before agreeing or disagreeing. |

---

## Problem Memory

Claude maintains a persistent knowledge base in `~/.claude/projects/-home-ntdst-Sites-stride/memory/`.

### Before debugging: check memory first

When hitting an error, test failure, or unexpected behavior — **search memory before investigating**:
```
Grep pattern="<error keyword>" path="/home/ntdst/.claude/projects/-home-ntdst-Sites-stride/memory/" glob="*.md"
```
If a match is found, apply the known fix directly. Don't re-debug.

### After solving: write it back

When you solve a non-trivial problem (not a typo, not a missing import), add an entry to the appropriate file:

| File | What goes in it |
|------|----------------|
| `memory/problems.md` | Errors with root cause and fix (codebase, deployment, testing, DI, frontend) |
| `memory/gotchas.md` | Non-obvious behavior, traps, things that waste time |
| `memory/patterns.md` | Confirmed conventions verified across multiple interactions |

## Memory

Memory and tasks are managed automatically by global hooks.
- `memory/STATE.md` — current project state, open work, decisions, risks
- `memory/lessons.md` — accumulated learnings specific to this project
- `tasks/todo.md` — open tasks carried forward between sessions

Entry format for problems:
```
### [Short title]
**Context:** Where/when this happens
**Symptom:** Error message or behavior
**Cause:** Root cause
**Fix:** Exact solution
**Date:** YYYY-MM-DD
```

### Rules
- Search before debugging — don't waste tokens re-solving known issues
- Only record verified solutions, not guesses
- Keep entries concise — future you needs the fix, not the journey
- Update entries if a better fix is found
- Delete entries that are no longer relevant

---

## Useful Agents

Specialized agents for complex tasks. Launch via the Agent tool.

### Code Quality
| Agent | When to Use |
|-------|-------------|
| `ntdst-wp-backend-reviewer` | After implementing PHP services, API endpoints, or modifying existing code. Strict NTDST framework compliance review. |
| `code-simplicity-reviewer` | Final review pass to ensure code is minimal and follows YAGNI principles. |
| `netdust-frontend-reviewer` | Review JavaScript for race conditions, Barba transitions, UIkit lifecycle, Lenis scroll. |

### Architecture & Planning
| Agent | When to Use |
|-------|-------------|
| `Plan` | Design implementation strategy for features. Returns step-by-step plans. |
| `architecture-strategist` | Analyze code changes from architectural perspective, evaluate design decisions. |
| `spec-flow-analyzer` | Analyze specifications for user flows and gap identification. |

### Security & Performance
| Agent | When to Use |
|-------|-------------|
| `security-sentinel` | Security audits, vulnerability assessments, input validation review. |
| `performance-oracle` | Analyze code for performance issues, optimize algorithms, identify bottlenecks. |

### Research & Exploration
| Agent | When to Use |
|-------|-------------|
| `Explore` | Quickly find files, search code, answer questions about the codebase. |
| `best-practices-researcher` | Research external best practices, documentation, and examples. |
| `bug-reproduction-validator` | Verify bug reports by attempting systematic reproduction. |

---

## Project Structure

```
stride/
├── site.yml                          # Operational config (hosting, deploy, DDEV, SSH)
├── docs/
│   ├── V4-PROJECT-PLAN master.md    # Feature inventory & 9-phase implementation
│   ├── ARCHITECTURE-V4-PROPOSAL.md  # Architecture decisions & design
│   ├── ARCHITECTURE-V3-ANALYSIS.md  # V3 analysis for reference
│   └── plans/                       # Dated design docs & implementation plans
├── plans/                            # Phase implementation plans (phase-1.5 through phase-5)
├── scripts/
│   ├── seed.php                     # Development data seeder
│   └── unseed.php                   # Seed data cleanup
├── web/
│   ├── app/
│   │   ├── mu-plugins/
│   │   │   ├── ntdst-coreloader.php    # Framework loader
│   │   │   ├── ntdst-core/             # DI, Bootstrap, Router, Theme
│   │   │   ├── stride-coreloader.php   # Stride business logic loader
│   │   │   └── stride-core/            # Stride business logic
│   │   │       ├── Modules/            # Domain modules
│   │   │       │   ├── Edition/        # EditionService, EditionRepository, EditionCPT
│   │   │       │   ├── Enrollment/     # EnrollmentService, RegistrationRepository
│   │   │       │   ├── Invoicing/      # QuoteService, VoucherService
│   │   │       │   ├── Trajectory/     # TrajectoryService, TrajectoryDashboardService
│   │   │       │   ├── Attendance/     # AttendanceService, AttendanceRepository
│   │   │       │   ├── Questionnaire/  # QuestionnaireService
│   │   │       │   ├── Notification/   # NotificationService
│   │   │       │   ├── User/           # ProfileTypeService
│   │   │       │   ├── Mail/           # StrideMailBridge
│   │   │       │   ├── Audit/          # AuditBridge
│   │   │       │   ├── Assistant/      # ReadAbilityRegistrar, WriteAbilityRegistrar
│   │   │       │   └── PartnerAPI/     # REST API for partner organizations
│   │   │       ├── Admin/              # AdminDashboardService
│   │   │       ├── Handlers/           # AJAX handlers (ProfileHandler, ICalHandler, etc.)
│   │   │       ├── Integrations/       # LearnDashService, LearnDashHelper
│   │   │       ├── Contracts/          # Interfaces (LMSAdapterInterface, etc.)
│   │   │       ├── Domain/             # Value objects (Money, EditionStatus, etc.)
│   │   │       ├── Infrastructure/     # AbstractRepository, AbstractService, BatchQueryHelper
│   │   │       ├── assets/
│   │   │       │   ├── css/            # Admin CSS + per-module CSS
│   │   │       │   └── js/             # Admin JS + per-module JS
│   │   │       ├── templates/
│   │   │       │   ├── admin/          # Admin templates (dashboard, settings, handleiding)
│   │   │       │   └── pdf/            # PDF templates (quote.php)
│   │   │       ├── FieldRegistry.php   # Field name constants
│   │   │       └── plugin-config.php   # Service registration
│   │   ├── plugins/                     # Composer-managed plugins
│   │   └── themes/
│   │       └── stridence/
│   │           ├── functions.php        # Bootstrap lifecycle + inline shortcodes
│   │           ├── theme-config.php     # Frontend services config
│   │           ├── services/
│   │           │   └── frontend/
│   │           │       └── shortcodes/  # InterestShortcodes, IntakeShortcodes, EvaluationShortcodes
│   │           ├── helpers/             # icons.php, formatting.php, templates.php
│   │           ├── templates/           # View templates
│   │           │   ├── dashboard/
│   │           │   ├── course/
│   │           │   ├── enrollment/
│   │           │   ├── invoice/
│   │           │   ├── emails/
│   │           │   └── pdf/
│   │           └── src/css/             # Tailwind source + tokens.css
│   └── wp/                              # WordPress core (Bedrock)
├── config/                              # Bedrock config
├── vendor/                              # Composer dependencies
├── .env                                 # Environment config
└── composer.json
```

---

## Architecture Patterns

### Service Registration (stride-core/plugin-config.php)

```php
return [
    'bindings' => [
        LMSAdapterInterface::class => LearnDashService::class,
        EditionQueryInterface::class => EditionService::class,
    ],
    'services' => [
        \Stride\Integrations\LearnDash\LearnDashService::class,
        \Stride\Admin\AdminDashboardService::class,
        \Stride\Modules\Edition\EditionService::class,
        \Stride\Modules\Enrollment\EnrollmentService::class,
        \Stride\Modules\Questionnaire\QuestionnaireService::class,
        \Stride\Modules\Trajectory\TrajectoryService::class,
        \Stride\Modules\Attendance\AttendanceService::class,
        \Stride\Modules\Invoicing\QuoteService::class,
        \Stride\Modules\Notification\NotificationService::class,
        \Stride\Modules\Audit\AuditBridge::class,
        \Stride\Modules\Mail\StrideMailBridge::class,
        \Stride\Modules\PartnerAPI\PartnerAPIController::class,
        \Stride\Modules\User\ProfileTypeService::class,
        \Stride\Modules\Assistant\ReadAbilityRegistrar::class,
        \Stride\Modules\Assistant\WriteAbilityRegistrar::class,
    ],
];
```

### Service Class Pattern

```php
<?php
namespace Stride\Modules\Edition;

class EditionService implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Manages scheduled course offerings',
            'priority' => 5,
        ];
    }

    public function __construct(
        private readonly EditionRepository $repository,
    ) {
        $this->init();
    }

    private function init(): void
    {
        // Hook registrations
    }

    // Business methods...
}
```

### Container Access

```php
// Get service instance
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);

// Register singleton
ntdst_set(MyService::class, fn() => new MyService());

// Theme helper
stride_service(\Stride\Modules\Edition\EditionService::class);
```

### Thin Handler Pattern (AJAX)

Handlers in `stride-core/Handlers/` follow the thin handler pattern:
- No constructor DI - use `ntdst_get()` inside methods
- Register own AJAX actions in `init()` method
- Validate input, delegate to services, return response

```php
<?php
namespace Stride\Handlers;

final class ProfileHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_stride_update_profile', [$this, 'ajaxUpdateProfile']);
    }

    public function ajaxUpdateProfile(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_profile')) {
            wp_send_json_error(['message' => __('Invalid token.', 'stride')]);
        }

        $result = $this->handleUpdateProfile($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public function handleUpdateProfile(array $params): array|WP_Error
    {
        // Sanitize, validate, delegate to services
        $service = ntdst_get(SomeService::class);
        return $service->doWork($params);
    }
}
```

### Shortcode Organization

Most shortcodes are registered inline in `themes/stridence/functions.php` (e.g., `stride_enrollment`).
Newer shortcodes use focused classes in `themes/stridence/services/frontend/shortcodes/`:

| Class | Shortcodes |
|-------|------------|
| `InterestShortcodes` | `stride_interest` |
| `IntakeShortcodes` | `stride_intake` |
| `EvaluationShortcodes` | `stride_evaluation` |

### External Assets Pattern (Admin)

Admin CSS/JS/HTML extracted to external files in `stride-core/`:

```
stride-core/
├── Admin/
│   └── AdminDashboardService.php   # Slim orchestrator
├── assets/
│   ├── css/
│   │   ├── admin-dashboard.css     # Dashboard CSS
│   │   └── admin/                  # Per-module CSS
│   │       ├── edition-admin.css
│   │       ├── questionnaire-builder.css
│   │       ├── quote-admin.css
│   │       ├── settings.css
│   │       └── trajectory-admin.css
│   └── js/
│       ├── admin-dashboard.js      # Dashboard JS (Alpine.js)
│       └── admin/                  # Per-module JS
│           ├── edition-admin.js
│           ├── questionnaire-builder.js
│           ├── quote-admin.js
│           ├── settings.js
│           └── trajectory-admin.js
└── templates/
    ├── admin/                      # Admin templates
    │   ├── dashboard.php
    │   ├── handleiding.php
    │   └── settings.php + settings/*.php
    └── pdf/quote.php               # PDF templates
```

Load external assets using `dirname(__DIR__)` for path calculation:

```php
public function injectStyles(): void
{
    $cssPath = dirname(__DIR__) . '/assets/css/admin-dashboard.css';
    if (file_exists($cssPath)) {
        echo '<style id="stride-dashboard-styles">';
        include $cssPath;
        echo '</style>';
    }
}
```

---

## Edition/Session Data Model

The Edition/Session layer separates scheduled course offerings from LearnDash course content.

### Key Concepts

- **Course** (`sfwd-courses`): LearnDash content only (lessons, quizzes, certificates)
- **Edition** (`vad_edition`): A scheduled offering of a course (dates, price, venue, capacity)
- **Session** (`vad_session`): Individual meeting days within an edition (time slots, attendance)
- **Registration** (`wp_vad_registrations`): User enrollment in an edition

### CPTs and Tables

| Type | Purpose |
|------|---------|
| `vad_edition` | Scheduled course offerings with pricing, dates, venue |
| `vad_session` | Meeting days with time slots and attendance tracking |
| `vad_voucher` | Discount codes |
| `vad_quote` | Quotes/invoices |
| `wp_vad_registrations` | High-volume registration table |

### User Meta Keys

Personal and billing user meta are **separate concerns** — never conflate them:

| Field | Meta Key | Step | Purpose |
|-------|----------|------|---------|
| `organisation` | `organisation` | Personal | User's employer/organisation |
| `department` | `department` | Personal | User's department within organisation |
| `company` | `billing_company` | Billing | Company name on invoices |
| `address` | `billing_address_1` | Billing | Invoice address |
| `postal_code` | `billing_postcode` | Billing | Invoice postal code |
| `city` | `billing_city` | Billing | Invoice city |
| `vat_number` | `billing_vat` | Billing | VAT number |
| `invoice_email` | `invoice_email` | Billing | Invoice email |
| `gln_number` | `gln_number` | Billing | GLN number |

**Important:** `organisation` ≠ `billing_company`. A user's employer (personal) and their invoice company (billing) are two independent fields. Never fall back from one to the other.

### Core Services

```php
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

// EditionService - scheduled offerings
$editionService = ntdst_get(EditionService::class);
$edition = $editionService->getEdition($editionId);  // Returns WP_Post|WP_Error
$editions = $editionService->getEditionsForCourse($courseId);
$price = $editionService->getPrice($editionId);
$courseId = $editionService->getCourseId($editionId);

// SessionService - meeting days and attendance
$sessionService = ntdst_get(SessionService::class);
$sessions = $sessionService->getSessionsForEdition($editionId);
$sessionService->markPresent($sessionId, $userId);
$hours = $sessionService->getHoursAttended($userId, $editionId);

// RegistrationRepository - enrollments
$regRepo = ntdst_get(RegistrationRepository::class);
$regId = $regRepo->create([
    'user_id' => $userId,
    'edition_id' => $editionId,
    'status' => 'confirmed',
    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
]);

// Query by company (Partner API)
$results = $regRepo->findByCompany($companyId, [
    'status' => 'confirmed',
    'page' => 1,
    'per_page' => 20,
]);
```

### LearnDash Integration (3 business operations + static helper)

```php
use Stride\Contracts\LMSAdapterInterface;
use Stride\Integrations\LearnDash\LearnDashHelper;

// LMSAdapterInterface — business operations only (DI)
$lms = ntdst_get(LMSAdapterInterface::class);
$lms->grantAccess($userId, $courseId);   // On registration
$lms->revokeAccess($userId, $courseId);  // On cancellation
$lms->isComplete($userId, $courseId);    // Check completion (used in business logic)

// LearnDashHelper — read-only presentation (static, for templates)
LearnDashHelper::getProgress($courseId, $userId);
LearnDashHelper::getCertificateLink($courseId, $userId);
LearnDashHelper::getEnrolledCourses($userId);
LearnDashHelper::getCompletionDate($courseId, $userId);
LearnDashHelper::isComplete($courseId, $userId);        // Template convenience
LearnDashHelper::getCourseAction($courseId, $userId);   // CTA logic
LearnDashHelper::getLessons($courseId, $userId);         // Lesson lists
```

---

## Development Workflow

### Local Development
```bash
cd /home/ntdst/Sites/stride
ddev start
ddev launch           # Open site in browser
ddev ssh              # Shell into container
```

### WP-CLI Commands
```bash
ddev exec wp plugin list
ddev exec wp theme status
ddev exec wp cache flush
```

### Adding Plugins
```bash
composer require wpackagist-plugin/plugin-name
ddev exec wp plugin activate plugin-name
```

### Seed/Unseed Development Data
```bash
# Seed the database with test data (users, courses, editions, sessions, registrations, vouchers, quotes)
ddev exec wp eval-file scripts/seed.php

# Remove all seed data
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
```

Test credentials after seeding:
- All seed users have password: `seedpass123`
- Admin: `seed_admin@seed.test`
- Students: `seed_student1@seed.test` through `seed_student5@seed.test`
- Partner: `seed_partner@seed.test` (has `partner` role, company_id=1)

### Verify Plugin Load
```bash
ddev exec wp eval "echo class_exists('\Stride\Modules\Edition\EditionService') ? 'OK' : 'FAIL';"
```

### Running Tests

```bash
# Run all unit tests (fast, uses stubs)
ddev exec vendor/bin/phpunit --testsuite Unit

# Run all integration tests (slower, uses real WordPress)
ddev exec vendor/bin/phpunit --testsuite Integration

# Run specific test file
ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit

# Run with coverage (if xdebug enabled)
ddev exec vendor/bin/phpunit --testsuite Unit --coverage-text
```

**Test structure:**
- `tests/Unit/` - Fast isolated tests with mocked dependencies
- `tests/Integration/` - Full WordPress tests with real database
- `tests/Stubs/` - WordPress function stubs for unit testing
- `tests/TestCase.php` - Base class for unit tests
- `tests/Integration/bootstrap.php` - Loads WordPress for integration tests

---

## Partner API

REST API for partner organizations to manage their users' enrollments.

**Design doc:** `docs/plans/2026-02-25-partner-api-design.md`

### Authentication

Partners use WordPress Application Passwords with Basic auth:
```bash
curl -u "partner_user:xxxx xxxx xxxx xxxx" \
  https://stride.ddev.site/wp-json/stride/v1/partner/users
```

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stride/v1/partner/users` | List company users |
| GET | `/stride/v1/partner/enrollments` | List company enrollments |
| GET | `/stride/v1/partner/enrollments/{id}` | Single enrollment details |
| GET | `/stride/v1/partner/certificates` | List certificates |
| GET | `/stride/v1/partner/attendance` | Attendance records |
| POST | `/stride/v1/partner/enrollments` | Create enrollment |

### Company Scoping

- Partner user has `_stride_company_id` in usermeta
- All queries automatically scoped to partner's company
- `company_id` column in `wp_vad_registrations` table

### Key Files

- `Modules/PartnerAPI/PartnerAPIController.php` - REST controller
- `Modules/Enrollment/RegistrationRepository.php` - `findByCompany()` method
- `tests/Unit/PartnerAPIControllerTest.php` - Unit tests
- `tests/Integration/PartnerAPIIntegrationTest.php` - Integration tests

---

## Key Decisions

1. **Fresh Start**: Clean WordPress, not running alongside v3
2. **Historical Data**: Query old v3 database for past enrollments/invoices/certificates
3. **Quotes Only**: Stride creates quotes; Exact Online handles actual invoicing
4. **User Migration**: Port user data, maintain their history via DB bridge
5. **Simplified Admin**: Unified user profile view instead of 6+ tools
6. **Journey UX**: Trajectories shown as visual learning paths, not course grids
7. **Edition/Session Model**: LearnDash courses are content only; editions are scheduled offerings with dates, pricing, capacity; sessions are individual meeting days
8. **LearnDash as Content Engine**: Only 4 integration points: `grantAccess`, `revokeAccess`, `isComplete`, `getCertificateLink`
9. **Plugin Architecture**: Business logic in mu-plugin (`stride-core`), presentation in theme (`stride`)

---

## Stridence Theme (Public Frontend)

**Full specification:** `docs/plans/stride-theme-spec.md`

**Stack:** Tailwind CSS + Alpine.js + Vite
**Location:** `web/app/themes/stridence/`
**Language:** Dutch (nl_BE) UI, English code

### Theme Development

```bash
cd web/app/themes/stridence
npm run dev     # Vite dev server (localhost:5173)
npm run build   # Production build
```

### Key Theme Rules

1. **All UI text in Dutch** — labels, buttons, errors, empty states
2. **Server-render first** — pages must work without JS
3. **Alpine for UI state only** — menus, tabs, filters (no business logic)
4. **Edition is the enrollable unit** — users enroll in editions, not courses
5. **LearnDash content via `the_content()`** — never re-implement LD rendering
6. **Style LearnDash, don't replace** — CSS overrides only for lessons/quizzes
7. **Use `ntdstAPI` for AJAX** — never raw fetch() for WP endpoints
8. **Dashboard tabs use URL state** — `?tab=xxx` for bookmarkability

### Helper Functions

```php
stride_format_date($date)      // Dutch formatted date
stride_format_money($cents)    // "€ 45,00"
stride_enrollment_url($id)     // Enrollment page URL
stridence_icon($name, $class)  // Inline SVG icon
```

---

## Environment

- **Site URL:** https://stride.ddev.site
- **Admin:** https://stride.ddev.site/wp/wp-admin
- **Mailpit:** https://stride.ddev.site:8026
- **Database:** MariaDB 10.11 (user: db, pass: db)
- **PHP:** 8.3

---

## Related Documentation

- **Operational Config (DevOps):** `site.yml` — hosting, SSH, deploy commands, DDEV config. Read this for any deployment or infrastructure questions.
- **V4 Project Plan (Master):** `docs/V4-PROJECT-PLAN master.md`
- **V4 Architecture:** `docs/ARCHITECTURE-V4-PROPOSAL.md`
- **Stridence Theme Spec:** `docs/plans/stride-theme-spec.md`
- **V3 Analysis:** `docs/ARCHITECTURE-V3-ANALYSIS.md`
- **Phase Plans:** `plans/` — phase-1.5 through phase-5 implementation plans
- **Design Docs:** `docs/plans/` — dated design and implementation documents
- **Plugin Extraction Plan:** `plans/plugin-extraction.md`
- **Seed Scripts:** `scripts/seed.php`, `scripts/unseed.php`
- **V3 Codebase (reference):** `/home/ntdst/Sites/vad-vormingen/`
- **NTDST Core (Rossi reference):** `/home/ntdst/Sites/rossi/`
