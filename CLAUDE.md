# Stride LMS - Claude Code Guide

## Project Overview

**Stride** - Modern LMS platform for VAD training management. Clean rewrite of VAD Vormingen v3 with focus on simplicity, better UX, and maintainable code.

**Key Technologies:**
- Framework: NTDST Core (DI container, Bootstrap, Router)
- Stack: Bedrock WordPress
- LMS: LearnDash
- CRM/Forms: FluentCRM, FluentForms, Fluent SMTP
- Frontend: TBD (likely Alpine.js + Tailwind or UIkit)

**Project Plan:** See `docs/V4-PROJECT-PLAN master.md` for the full feature inventory and 9-phase implementation plan.

**Current Phase:** Phase 1.5 - Edition/Session Layer (critical path)

---

## Useful Skills

Use these skills during development:

### Planning & Workflow
| Skill | Usage |
|-------|-------|
| `/do:plan` | Transform feature descriptions into structured implementation plans |
| `/do:work` | Execute work plans efficiently while maintaining quality |
| `/do:quick` | Fast path for small tasks - skip planning, just do the work |
| `/do:review` | Code review (light mode default, thorough mode optional) |
| `/deepen-plan` | Enhance a plan with research |
| `/review-plan` | Get reviewer feedback on a plan before implementation |

### NTDST WordPress Development
| Skill | Usage |
|-------|-------|
| `/ntdst-wp-dev` | Generate production-ready WordPress code following NTDST framework patterns. Use when creating services, data models, API endpoints. |
| `/ntdst-wp-workflow` | Development workflow commands - setup, deployment, syncing, templates |

### Performance & Quality
| Skill | Usage |
|-------|-------|
| `/wp-perf` | Quick WordPress performance scan |
| `/wp-perf-review` | WordPress performance code review |
| `/do:compound` | Document a recently solved problem to compound knowledge |

### Task Management
| Skill | Usage |
|-------|-------|
| `/file-todos` | File-based todo tracking in todos/ directory |

---

## Useful Agents

These specialized agents help with complex tasks:

### Code Quality
| Agent | When to Use |
|-------|-------------|
| `ntdst-wp-backend-reviewer` | After implementing PHP services, API endpoints, or modifying existing code. Strict NTDST framework compliance review. |
| `code-simplicity-reviewer` | Final review pass to ensure code is minimal and follows YAGNI principles. Use before finalizing changes. |
| `netdust-frontend-reviewer` | Review JavaScript code for race conditions, page transitions, scroll management. |

### Architecture & Planning
| Agent | When to Use |
|-------|-------------|
| `Plan` | Design implementation strategy for features. Returns step-by-step plans. |
| `architecture-strategist` | Analyze code changes from architectural perspective, evaluate design decisions. |
| `spec-flow-analyzer` | Analyze specifications for user flows and gap identification. Use when planning features. |

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
├── docs/
│   ├── V4-PROJECT-PLAN master.md    # Feature inventory & 9-phase implementation
│   ├── ARCHITECTURE-V4-PROPOSAL.md  # Architecture decisions & design
│   └── ARCHITECTURE-V3-ANALYSIS.md  # V3 analysis for reference
├── scripts/
│   ├── seed.php                     # Development data seeder
│   └── unseed.php                   # Seed data cleanup
├── web/
│   ├── app/
│   │   ├── mu-plugins/
│   │   │   ├── ntdst-coreloader.php    # Framework loader
│   │   │   └── ntdst-core/             # DI, Bootstrap, Router, Theme
│   │   ├── plugins/                     # Composer-managed plugins
│   │   └── themes/
│   │       └── stride/
│   │           ├── functions.php        # Bootstrap lifecycle
│   │           ├── theme-config.php     # Services & module config
│   │           ├── services/            # Business logic
│   │           │   ├── core/            # CourseService, EditionService, SessionService, RegistrationRepository
│   │           │   ├── enrollment/      # Enrollment workflows
│   │           │   ├── invoicing/       # Quote generation
│   │           │   ├── voucher/         # Voucher management
│   │           │   ├── admin/           # Admin dashboard
│   │           │   └── integrations/    # LearnDash, FluentCRM bridges
│   │           └── templates/           # View templates
│   │               ├── dashboard/
│   │               ├── course/
│   │               ├── invoice/
│   │               ├── admin/
│   │               ├── emails/
│   │               └── pdf/
│   └── wp/                              # WordPress core (Bedrock)
├── config/                              # Bedrock config
├── vendor/                              # Composer dependencies
├── .env                                 # Environment config
└── composer.json
```

---

## Architecture Patterns

### Service Registration (theme-config.php)

```php
'services' => [
    'core' => [
        'stride\\services\\core\\CourseService',
        'stride\\services\\enrollment\\EnrollmentService',
    ],
    'conditional' => [
        'learndash' => [
            'service' => 'stride\\services\\integrations\\LearnDashService',
            'condition' => fn() => defined('LEARNDASH_VERSION'),
        ],
    ],
    'auto_discover' => true,
    'discovery_paths' => [get_stylesheet_directory() . '/services'],
],
```

### Service Class Pattern

```php
<?php
namespace stride\services\core;

class CourseService implements NTDST_ServiceInterface
{
    public static function metadata(): array
    {
        return [
            'name' => 'Course Service',
            'description' => 'Course data and status management',
            'priority' => 10,
        ];
    }

    public function __construct()
    {
        // Hook registrations
    }

    // Business methods...
}
```

### Container Access

```php
// Get service instance
$courseService = ntdst_get(CourseService::class);

// Register singleton
ntdst_set(MyService::class, fn() => new MyService());

// Theme helper
stride_service(CourseService::class);
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

### Core Services

```php
// EditionService - scheduled offerings
$editionService = ntdst_get(EditionService::class);
$edition = $editionService->getEdition($editionId);
$editions = $editionService->getEditionsForCourse($courseId);
$price = $editionService->getPrice($editionId);

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
```

### LearnDash Integration (4 points only)

```php
// CourseService wraps LearnDash
$courseService = ntdst_get(CourseService::class);
$courseService->grantAccess($userId, $courseId);   // On registration
$courseService->revokeAccess($userId, $courseId);  // On cancellation
$courseService->isComplete($userId, $courseId);    // Check completion
$courseService->getCertificateLink($userId, $courseId);
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

---

## Environment

- **Site URL:** https://stride.ddev.site
- **Admin:** https://stride.ddev.site/wp/wp-admin
- **Mailpit:** https://stride.ddev.site:8026
- **Database:** MariaDB 10.11 (user: db, pass: db)
- **PHP:** 8.3

---

## Related Documentation

- **V4 Project Plan (Master):** `docs/V4-PROJECT-PLAN master.md`
- **V4 Architecture:** `docs/ARCHITECTURE-V4-PROPOSAL.md`
- **V3 Analysis:** `docs/ARCHITECTURE-V3-ANALYSIS.md`
- **Seed Scripts:** `scripts/seed.php`, `scripts/unseed.php`
- **V3 Codebase (reference):** `/home/ntdst/Sites/vad-vormingen/`
- **NTDST Core (Rossi reference):** `/home/ntdst/Sites/rossi/`
