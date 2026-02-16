# Stride LMS V1 - Phase 0 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Set up clean architecture foundation before writing any business logic.

**Architecture:** Module-based structure with shared Contracts, Domain, and Infrastructure layers. All services follow ntdst-wp patterns.

**Tech Stack:** PHP 8.3, ntdst-core (DI, DataManager, Router), WordPress mu-plugin

**Reference Skill:** @ntdst-wp-dev for all PHP code patterns

---

## Prerequisites

- [ ] Existing stride-core code backed up or on separate branch
- [ ] Fresh stride-core directory ready
- [ ] ntdst-core mu-plugin installed and working

---

## Task 1: Create Module Directory Structure

**Files:**
- Create: `web/app/mu-plugins/stride-core/Contracts/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Domain/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Infrastructure/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Modules/User/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Handlers/.gitkeep`
- Create: `web/app/mu-plugins/stride-core/Adapters/.gitkeep`

**Step 1: Create directory structure**

```bash
mkdir -p web/app/mu-plugins/stride-core/{Contracts,Domain,Infrastructure,Handlers,Adapters}
mkdir -p web/app/mu-plugins/stride-core/Modules/{Edition,Enrollment,Invoicing,Trajectory,User}
touch web/app/mu-plugins/stride-core/{Contracts,Domain,Infrastructure,Handlers,Adapters}/.gitkeep
touch web/app/mu-plugins/stride-core/Modules/{Edition,Enrollment,Invoicing,Trajectory,User}/.gitkeep
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "chore: create module directory structure for stride-core v1"
```

---

## Task 2: Create RepositoryInterface Contract

**Files:**
- Create: `web/app/mu-plugins/stride-core/Contracts/RepositoryInterface.php`

**Step 1: Write interface**

```php
<?php

declare(strict_types=1);

namespace Stride\Contracts;

use WP_Error;
use WP_Post;

/**
 * Base repository contract for all data access.
 *
 * All repositories return WP_Error on failure, never null/false.
 */
interface RepositoryInterface
{
    /**
     * Find a single record by ID.
     *
     * @return WP_Post|WP_Error
     */
    public function find(int $id): WP_Post|WP_Error;

    /**
     * Create a new record.
     *
     * @param array<string, mixed> $data
     * @return WP_Post|WP_Error
     */
    public function create(array $data): WP_Post|WP_Error;

    /**
     * Update an existing record.
     *
     * @param array<string, mixed> $data
     * @return WP_Post|WP_Error
     */
    public function update(int $id, array $data): WP_Post|WP_Error;

    /**
     * Delete a record.
     *
     * @return bool|WP_Error
     */
    public function delete(int $id, bool $force = false): bool|WP_Error;
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Contracts/RepositoryInterface.php
```

Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Contracts/RepositoryInterface.php
git commit -m "feat(contracts): add RepositoryInterface base contract"
```

---

## Task 3: Create LMSAdapterInterface Contract

**Files:**
- Create: `web/app/mu-plugins/stride-core/Contracts/LMSAdapterInterface.php`

**Step 1: Write interface (4 methods only)**

```php
<?php

declare(strict_types=1);

namespace Stride\Contracts;

/**
 * LearnDash integration contract.
 *
 * Only 4 touch points with the LMS - keeps coupling minimal.
 */
interface LMSAdapterInterface
{
    /**
     * Grant course access to user.
     */
    public function grantAccess(int $userId, int $courseId): bool;

    /**
     * Revoke course access from user.
     */
    public function revokeAccess(int $userId, int $courseId): bool;

    /**
     * Check if user has completed the course.
     */
    public function isComplete(int $userId, int $courseId): bool;

    /**
     * Get certificate download link if available.
     */
    public function getCertificateLink(int $userId, int $courseId): ?string;
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Contracts/LMSAdapterInterface.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Contracts/LMSAdapterInterface.php
git commit -m "feat(contracts): add LMSAdapterInterface with 4 touch points"
```

---

## Task 4: Create EditionQueryInterface Contract

**Files:**
- Create: `web/app/mu-plugins/stride-core/Contracts/EditionQueryInterface.php`

**Step 1: Write interface**

```php
<?php

declare(strict_types=1);

namespace Stride\Contracts;

use Stride\Domain\EditionStatus;

/**
 * Query interface for editions - used by other modules.
 *
 * Enrollment module depends on this interface, not EditionService directly.
 */
interface EditionQueryInterface
{
    /**
     * Check if edition has available spots.
     */
    public function hasAvailableSpots(int $editionId): bool;

    /**
     * Get current capacity count.
     */
    public function getRegisteredCount(int $editionId): int;

    /**
     * Get maximum capacity.
     */
    public function getCapacity(int $editionId): int;

    /**
     * Get edition status.
     */
    public function getStatus(int $editionId): EditionStatus;

    /**
     * Get linked LearnDash course ID.
     */
    public function getCourseId(int $editionId): ?int;

    /**
     * Check if edition exists and is valid.
     */
    public function exists(int $editionId): bool;
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Contracts/EditionQueryInterface.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Contracts/EditionQueryInterface.php
git commit -m "feat(contracts): add EditionQueryInterface for cross-module queries"
```

---

## Task 5: Create Money Value Object

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/Money.php`

**Step 1: Write immutable value object**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

use InvalidArgumentException;

/**
 * Immutable money value object.
 *
 * Stores amounts in cents to avoid float precision issues.
 * All operations return new instances.
 */
final readonly class Money
{
    private function __construct(
        private int $cents,
        private string $currency = 'EUR',
    ) {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    /**
     * Create from euro cents.
     */
    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Create from euro amount (e.g., 250.00).
     */
    public static function eur(float $amount): self
    {
        return new self((int) round($amount * 100));
    }

    /**
     * Create zero amount.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function amount(): float
    {
        return $this->cents / 100;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        $result = $this->cents - $other->cents;

        if ($result < 0) {
            throw new InvalidArgumentException('Result cannot be negative');
        }

        return new self($result, $this->currency);
    }

    public function multiply(float $factor): self
    {
        return new self((int) round($this->cents * $factor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents
            && $this->currency === $other->currency;
    }

    public function format(): string
    {
        return sprintf('€ %s', number_format($this->amount(), 2, ',', '.'));
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot operate on different currencies');
        }
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/Money.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/Money.php
git commit -m "feat(domain): add Money value object with immutable operations"
```

---

## Task 6: Create DateRange Value Object

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/DateRange.php`

**Step 1: Write value object**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable date range value object.
 *
 * Used for edition scheduling, choice windows, deadlines.
 */
final readonly class DateRange
{
    private function __construct(
        private DateTimeImmutable $start,
        private DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException('End date cannot be before start date');
        }
    }

    /**
     * Create from DateTime objects.
     */
    public static function from(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /**
     * Create from date strings (Y-m-d format).
     */
    public static function fromStrings(string $start, string $end): self
    {
        return new self(
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
        );
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    /**
     * Check if a date falls within this range (inclusive).
     */
    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    /**
     * Check if now is within this range.
     */
    public function isActive(): bool
    {
        return $this->contains(new DateTimeImmutable());
    }

    /**
     * Check if this range has passed.
     */
    public function isPast(): bool
    {
        return new DateTimeImmutable() > $this->end;
    }

    /**
     * Check if this range is in the future.
     */
    public function isFuture(): bool
    {
        return new DateTimeImmutable() < $this->start;
    }

    /**
     * Check if this range overlaps with another.
     */
    public function overlapsWith(DateRange $other): bool
    {
        return $this->start <= $other->end && $this->end >= $other->start;
    }

    /**
     * Get duration in days.
     */
    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function format(string $pattern = 'd/m/Y'): string
    {
        return sprintf(
            '%s - %s',
            $this->start->format($pattern),
            $this->end->format($pattern),
        );
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/DateRange.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/DateRange.php
git commit -m "feat(domain): add DateRange value object for scheduling"
```

---

## Task 7: Create EditionStatus Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/EditionStatus.php`

**Step 1: Write enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Edition status values.
 */
enum EditionStatus: string
{
    case Open = 'open';
    case Full = 'full';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case Announcement = 'announcement';
    case Completed = 'completed';

    /**
     * Check if enrollment is allowed.
     */
    public function allowsEnrollment(): bool
    {
        return $this === self::Open;
    }

    /**
     * Check if edition is active (not cancelled/completed).
     */
    public function isActive(): bool
    {
        return !in_array($this, [self::Cancelled, self::Completed], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open voor inschrijving',
            self::Full => 'Volzet',
            self::Cancelled => 'Geannuleerd',
            self::Postponed => 'Uitgesteld',
            self::Announcement => 'Aankondiging',
            self::Completed => 'Afgelopen',
        };
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/EditionStatus.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/EditionStatus.php
git commit -m "feat(domain): add EditionStatus enum"
```

---

## Task 8: Create RegistrationStatus Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/RegistrationStatus.php`

**Step 1: Write enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Registration status values.
 */
enum RegistrationStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Waitlist = 'waitlist';
    case Interest = 'interest';

    /**
     * Check if registration counts toward capacity.
     */
    public function countsTowardCapacity(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Check if user has active access.
     */
    public function hasAccess(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Bevestigd',
            self::Cancelled => 'Geannuleerd',
            self::Waitlist => 'Wachtlijst',
            self::Interest => 'Interesse',
        };
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/RegistrationStatus.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/RegistrationStatus.php
git commit -m "feat(domain): add RegistrationStatus enum"
```

---

## Task 9: Create QuoteStatus Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/QuoteStatus.php`

**Step 1: Write enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Quote/invoice status values.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Exported = 'exported';
    case Cancelled = 'cancelled';

    /**
     * Check if quote can be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if quote is finalized.
     */
    public function isFinalized(): bool
    {
        return in_array($this, [self::Sent, self::Exported], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Sent => 'Verzonden',
            self::Exported => 'Geëxporteerd',
            self::Cancelled => 'Geannuleerd',
        };
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/QuoteStatus.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/QuoteStatus.php
git commit -m "feat(domain): add QuoteStatus enum"
```

---

## Task 10: Create SessionType Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/SessionType.php`

**Step 1: Write enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Session type values.
 *
 * Determines how completion is tracked.
 */
enum SessionType: string
{
    case InPerson = 'in_person';
    case Webinar = 'webinar';
    case Online = 'online';
    case Assignment = 'assignment';

    /**
     * Check if session requires admin attendance marking.
     */
    public function requiresAttendanceMarking(): bool
    {
        return in_array($this, [self::InPerson, self::Webinar], true);
    }

    /**
     * Check if completion is tracked by LearnDash.
     */
    public function trackedByLMS(): bool
    {
        return in_array($this, [self::Online, self::Assignment], true);
    }

    /**
     * Check if session has a scheduled date/time.
     */
    public function isScheduled(): bool
    {
        return in_array($this, [self::InPerson, self::Webinar], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'Fysieke sessie',
            self::Webinar => 'Webinar',
            self::Online => 'Online module',
            self::Assignment => 'Opdracht',
        };
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Domain/SessionType.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/SessionType.php
git commit -m "feat(domain): add SessionType enum with completion tracking logic"
```

---

## Task 11: Create AbstractRepository Base Class

**Files:**
- Create: `web/app/mu-plugins/stride-core/Infrastructure/AbstractRepository.php`

**Step 1: Write base class**

```php
<?php

declare(strict_types=1);

namespace Stride\Infrastructure;

use Stride\Contracts\RepositoryInterface;
use WP_Error;
use WP_Post;

/**
 * Base repository for CPT-based entities.
 *
 * Uses ntdst_data() for all database operations.
 * Child classes must define $postType.
 */
abstract class AbstractRepository implements RepositoryInterface
{
    /**
     * Post type slug - must be set by child class.
     */
    protected string $postType;

    /**
     * Get the Data Manager model.
     */
    protected function model(): mixed
    {
        return ntdst_data()->get($this->postType);
    }

    public function find(int $id): WP_Post|WP_Error
    {
        $post = $this->model()->find($id);

        if ($post === null) {
            return new WP_Error(
                'not_found',
                sprintf('%s with ID %d not found', $this->postType, $id)
            );
        }

        return $post;
    }

    public function create(array $data): WP_Post|WP_Error
    {
        $result = $this->model()->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }

    public function update(int $id, array $data): WP_Post|WP_Error
    {
        $result = $this->model()->update($id, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->find($id);
    }

    public function delete(int $id, bool $force = false): bool|WP_Error
    {
        $result = $this->model()->delete($id, $force);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Get all records with optional filters.
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function all(array $filters = [], int $limit = -1): array
    {
        $query = $this->model();

        foreach ($filters as $field => $value) {
            $query = $query->where($field, $value);
        }

        return $query->limit($limit)->withMeta()->get();
    }

    /**
     * Count records matching filters.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        $query = $this->model();

        foreach ($filters as $field => $value) {
            $query = $query->where($field, $value);
        }

        return $query->count();
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Infrastructure/AbstractRepository.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Infrastructure/AbstractRepository.php
git commit -m "feat(infrastructure): add AbstractRepository base class"
```

---

## Task 12: Create AbstractService Base Class

**Files:**
- Create: `web/app/mu-plugins/stride-core/Infrastructure/AbstractService.php`

**Step 1: Write base class**

```php
<?php

declare(strict_types=1);

namespace Stride\Infrastructure;

use NTDST_Service_Meta;

/**
 * Base service class with common patterns.
 *
 * All services implement NTDST_Service_Meta for auto-discovery.
 */
abstract class AbstractService implements NTDST_Service_Meta
{
    /**
     * Service configuration from filters.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    public function __construct()
    {
        $this->config = $this->getDefaultConfig();
        $this->init();
    }

    /**
     * Get default configuration - override in child classes.
     *
     * @return array<string, mixed>
     */
    protected function getDefaultConfig(): array
    {
        $slug = $this->getConfigSlug();

        return apply_filters("stride_{$slug}_config", []);
    }

    /**
     * Get config slug for filters (e.g., 'edition' for EditionService).
     */
    abstract protected function getConfigSlug(): string;

    /**
     * Initialize service - register hooks here.
     */
    abstract protected function init(): void;

    /**
     * Fire a domain event via WordPress action.
     *
     * @param array<string, mixed> $data
     */
    protected function dispatch(string $event, array $data = []): void
    {
        do_action("stride/{$event}", $data);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Infrastructure/AbstractService.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Infrastructure/AbstractService.php
git commit -m "feat(infrastructure): add AbstractService base class"
```

---

## Task 13: Create LearnDashAdapter

**Files:**
- Create: `web/app/mu-plugins/stride-core/Adapters/LearnDashAdapter.php`

**Step 1: Write adapter implementing interface**

```php
<?php

declare(strict_types=1);

namespace Stride\Adapters;

use Stride\Contracts\LMSAdapterInterface;

/**
 * LearnDash implementation of LMS adapter.
 *
 * Only 4 methods - keeps coupling minimal.
 */
final class LearnDashAdapter implements LMSAdapterInterface
{
    public function grantAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('ld_update_course_access')) {
            return false;
        }

        ld_update_course_access($userId, $courseId, false);

        return true;
    }

    public function revokeAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('ld_update_course_access')) {
            return false;
        }

        ld_update_course_access($userId, $courseId, true);

        return true;
    }

    public function isComplete(int $userId, int $courseId): bool
    {
        if (!function_exists('learndash_course_completed')) {
            return false;
        }

        return learndash_course_completed($userId, $courseId);
    }

    public function getCertificateLink(int $userId, int $courseId): ?string
    {
        if (!function_exists('learndash_get_course_certificate_link')) {
            return null;
        }

        $link = learndash_get_course_certificate_link($courseId, $userId);

        return $link ?: null;
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Adapters/LearnDashAdapter.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Adapters/LearnDashAdapter.php
git commit -m "feat(adapters): add LearnDashAdapter with 4 touch points"
```

---

## Task 14: Create Plugin Config

**Files:**
- Create: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Write config file**

```php
<?php

declare(strict_types=1);

/**
 * Stride Core Plugin Configuration
 *
 * Service registration and DI bindings.
 */

use Stride\Adapters\LearnDashAdapter;
use Stride\Contracts\LMSAdapterInterface;

return [
    /**
     * DI Container Bindings
     *
     * Interface => Implementation mappings
     */
    'bindings' => [
        LMSAdapterInterface::class => LearnDashAdapter::class,
    ],

    /**
     * Services to auto-register
     *
     * Services are loaded in order, by priority from metadata().
     */
    'services' => [
        // Core services will be added as modules are built
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'edition' => [
            // Edition module config
        ],
        'enrollment' => [
            // Enrollment module config
        ],
        'invoicing' => [
            // Invoicing module config
        ],
        'trajectory' => [
            // Trajectory module config
        ],
    ],
];
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat: add plugin-config.php with DI bindings structure"
```

---

## Task 15: Create Autoloader

**Files:**
- Create: `web/app/mu-plugins/stride-core/autoload.php`

**Step 1: Write PSR-4 autoloader**

```php
<?php

declare(strict_types=1);

/**
 * Stride Core PSR-4 Autoloader
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Stride\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/autoload.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/autoload.php
git commit -m "feat: add PSR-4 autoloader for Stride namespace"
```

---

## Task 16: Create Plugin Bootstrap

**Files:**
- Create: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Write bootstrap file**

```php
<?php

declare(strict_types=1);

/**
 * Plugin Name: Stride Core
 * Description: Business logic for Stride LMS
 * Version: 1.0.0
 * Author: NTDST
 */

defined('ABSPATH') || exit;

// Load autoloader
require_once __DIR__ . '/autoload.php';

// Load config
$config = require __DIR__ . '/plugin-config.php';

// Register DI bindings
add_action('ntdst/core_ready', function () use ($config): void {
    foreach ($config['bindings'] as $interface => $implementation) {
        ntdst_set($interface, $implementation);
    }
});

// Register services
add_action('ntdst/features_ready', function () use ($config): void {
    foreach ($config['services'] as $serviceClass) {
        if (class_exists($serviceClass)) {
            ntdst_get($serviceClass);
        }
    }
});
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/stride-core.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat: add stride-core plugin bootstrap"
```

---

## Task 17: Verify Plugin Loads

**Step 1: Check plugin is recognized**

```bash
ddev exec wp plugin list | grep stride
```

Expected: stride-core appears in list

**Step 2: Test autoloader works**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';
echo class_exists('Stride\Domain\Money') ? 'Money: OK' : 'Money: FAIL';
echo PHP_EOL;
echo class_exists('Stride\Domain\EditionStatus') ? 'EditionStatus: OK' : 'EditionStatus: FAIL';
echo PHP_EOL;
echo interface_exists('Stride\Contracts\RepositoryInterface') ? 'RepositoryInterface: OK' : 'RepositoryInterface: FAIL';
"
```

Expected:
```
Money: OK
EditionStatus: OK
RepositoryInterface: OK
```

**Step 3: Commit verification**

```bash
git add -A
git commit -m "chore: verify Phase 0 foundation complete"
```

---

## Phase 0 Complete - Exit Criteria Check

- [x] Directory structure created
- [x] Contracts: RepositoryInterface, LMSAdapterInterface, EditionQueryInterface
- [x] Domain: Money, DateRange, EditionStatus, RegistrationStatus, QuoteStatus, SessionType
- [x] Infrastructure: AbstractRepository, AbstractService
- [x] Adapters: LearnDashAdapter
- [x] Plugin config and bootstrap
- [x] Autoloader working

---

## Next: Vertical Slice 1

Create separate plan: `docs/plans/2026-02-16-stride-v1-slice1-edition-registration.md`

Slice 1 covers:
- EditionRepository + EditionService
- RegistrationRepository + EnrollmentService
- Basic student dashboard page
- One complete flow: view editions → register → see in dashboard
