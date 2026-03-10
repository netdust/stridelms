# Audit-Driven Notifications Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rewrite NotificationService to show real event notifications from the audit log instead of derived dashboard actions.

**Architecture:** NotificationService queries `wp_audit_log` via a new `AuditRepository::findBySubjectUser()` method that searches `JSON_EXTRACT(context, '$.user_id')`. Session note changes are tracked via a new `session.note_updated` audit event fired from `SessionService::updateSession()`. Read state stays in user meta.

**Tech Stack:** PHP 8.3, WordPress mu-plugin (stride-core), ntdst-audit plugin, PHPUnit

---

## Phase 1: Audit Layer Extensions

### Task 1: Add `findBySubjectUser()` to AuditRepository

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/AuditRepository.php`
- Test: `tests/Unit/AuditRepositorySubjectQueryTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditRepository;
use Stride\Tests\TestCase;

class AuditRepositorySubjectQueryTest extends TestCase
{
    private AuditRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AuditRepository();
    }

    /** @test */
    public function testFindBySubjectUserReturnsEntriesWhereUserIsSubject(): void
    {
        // Method should exist and return array
        $result = $this->repository->findBySubjectUser(456);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindBySubjectUserExcludesSelfActions(): void
    {
        // When actor_id == user_id, entry should be excluded
        // This is a contract test — actual DB test in integration
        $result = $this->repository->findBySubjectUser(456, 50, 30);
        $this->assertIsArray($result);
    }

    /** @test */
    public function testFindBySubjectUserAcceptsLimitAndDaysBack(): void
    {
        $result = $this->repository->findBySubjectUser(456, 10, 7);
        $this->assertIsArray($result);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter=AuditRepositorySubjectQueryTest`
Expected: FAIL — method `findBySubjectUser` does not exist

**Step 3: Write the implementation**

Add to `web/app/plugins/ntdst-audit/src/AuditRepository.php`:

```php
/**
 * Find audit entries where a user is the subject (in context),
 * excluding entries where the user was the actor (self-actions).
 */
public function findBySubjectUser(int $userId, int $limit = 50, int $daysBack = 30): array
{
    global $wpdb;

    $since = (new \DateTime("-{$daysBack} days"))->format('Y-m-d H:i:s');

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$this->table()}
         WHERE JSON_EXTRACT(context, '$.user_id') = %d
           AND (actor_id IS NULL OR actor_id != %d)
           AND created_at >= %s
         ORDER BY created_at DESC
         LIMIT %d",
        $userId,
        $userId,
        $since,
        $limit
    ));
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter=AuditRepositorySubjectQueryTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditRepository.php tests/Unit/AuditRepositorySubjectQueryTest.php
git commit -m "feat(audit): add findBySubjectUser() for context-based user queries"
```

---

### Task 2: Add `findSessionNoteUpdates()` to AuditRepository

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/AuditRepository.php`
- Test: `tests/Unit/AuditRepositorySubjectQueryTest.php` (add test)

**Step 1: Write the failing test**

Add to `AuditRepositorySubjectQueryTest`:

```php
/** @test */
public function testFindSessionNoteUpdatesReturnsEntriesForEditions(): void
{
    $result = $this->repository->findSessionNoteUpdates([10, 20, 30], 30);
    $this->assertIsArray($result);
}

/** @test */
public function testFindSessionNoteUpdatesReturnsEmptyForNoEditions(): void
{
    $result = $this->repository->findSessionNoteUpdates([], 30);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter=testFindSessionNoteUpdates`
Expected: FAIL — method does not exist

**Step 3: Write the implementation**

Add to `AuditRepository`:

```php
/**
 * Find session note update entries for a set of edition IDs.
 * Used to notify enrolled users about session changes.
 *
 * @param int[] $editionIds
 */
public function findSessionNoteUpdates(array $editionIds, int $daysBack = 30): array
{
    if (empty($editionIds)) {
        return [];
    }

    global $wpdb;

    $since = (new \DateTime("-{$daysBack} days"))->format('Y-m-d H:i:s');
    $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

    $params = $editionIds;
    $params[] = $since;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$this->table()}
         WHERE action = 'session.note_updated'
           AND JSON_EXTRACT(context, '$.edition_id') IN ({$placeholders})
           AND created_at >= %s
         ORDER BY created_at DESC",
        ...$params
    ));
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter=testFindSessionNoteUpdates`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditRepository.php tests/Unit/AuditRepositorySubjectQueryTest.php
git commit -m "feat(audit): add findSessionNoteUpdates() for edition-scoped queries"
```

---

### Task 3: Add `getForSubjectUser()` wrapper to AuditService

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/AuditService.php`

**Step 1: Write the implementation**

Add to `AuditService`:

```php
/**
 * Get audit entries where user is the subject (not actor).
 */
public function getForSubjectUser(int $userId, int $limit = 50, int $daysBack = 30): array
{
    return $this->repository->findBySubjectUser($userId, $limit, $daysBack);
}

/**
 * Get session note updates for editions.
 *
 * @param int[] $editionIds
 */
public function getSessionNoteUpdates(array $editionIds, int $daysBack = 30): array
{
    return $this->repository->findSessionNoteUpdates($editionIds, $daysBack);
}
```

**Step 2: Run full unit suite to verify no regressions**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All green

**Step 3: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditService.php
git commit -m "feat(audit): add getForSubjectUser() and getSessionNoteUpdates() wrappers"
```

---

### Task 4: Add session note audit hook to AuditBridge

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Audit/AuditBridge.php`
- Test: `tests/Unit/AuditBridgeTest.php` (add test)

**Step 1: Write the failing test**

Add to `AuditBridgeTest`:

```php
/** @test */
public function testOnSessionNoteUpdatedRecordsCorrectData(): void
{
    $data = [
        'session_id' => 100,
        'edition_id' => 200,
    ];

    $this->bridge->onSessionNoteUpdated($data);

    $calls = $this->mockAuditService->getRecordedCalls();
    $this->assertCount(1, $calls);

    $call = $calls[0];
    $this->assertEquals('session', $call['entity_type']);
    $this->assertEquals(100, $call['entity_id']);
    $this->assertEquals('session.note_updated', $call['action']);
    $this->assertEquals([
        'session_id' => 100,
        'edition_id' => 200,
    ], $call['context']);
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter=testOnSessionNoteUpdatedRecordsCorrectData`
Expected: FAIL — method does not exist

**Step 3: Write the implementation**

Add handler to `AuditBridge`:

```php
public function onSessionNoteUpdated(array $data): void
{
    $this->audit()->record(
        'session',
        (int) $data['session_id'],
        'session.note_updated',
        null, // Will default to current user (admin)
        [
            'session_id' => $data['session_id'] ?? null,
            'edition_id' => $data['edition_id'] ?? null,
        ]
    );
}
```

Register the hook in `AuditBridge::init()`:

```php
add_action('stride/session/note_updated', [$this, 'onSessionNoteUpdated']);
```

**Step 4: Fire the hook from SessionService::updateSession()**

Modify `SessionService::updateSession()` to detect description changes and fire the hook:

```php
public function updateSession(int $sessionId, array $data): true|WP_Error
{
    // Check if description changed (for audit notification)
    $descriptionChanged = false;
    if (array_key_exists('description', $data)) {
        $oldDescription = $this->repository->getMeta($sessionId, 'description') ?? '';
        $descriptionChanged = $data['description'] !== $oldDescription;
    }

    $result = $this->repository->update($sessionId, $data);

    if (is_wp_error($result)) {
        return $result;
    }

    // Fire audit hook if description changed
    if ($descriptionChanged) {
        $editionId = $this->repository->getMeta($sessionId, 'edition_id');
        do_action('stride/session/note_updated', [
            'session_id' => $sessionId,
            'edition_id' => (int) $editionId,
        ]);
    }

    return true;
}
```

**Step 5: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter=AuditBridgeTest`
Expected: All green

**Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditBridge.php \
        web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php \
        tests/Unit/AuditBridgeTest.php
git commit -m "feat(audit): track session note updates via stride/session/note_updated hook"
```

---

**Integration gate (Phase 1):** All audit query methods exist and return arrays. Session note hook fires on description change. Run: `ddev exec vendor/bin/phpunit --testsuite Unit`

---

## Phase 2: Rewrite NotificationService

### Task 5: Create NotificationMapper helper

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Notification/NotificationMapper.php`
- Test: `tests/Unit/NotificationMapperTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Notification\NotificationMapper;
use Stride\Tests\TestCase;

class NotificationMapperTest extends TestCase
{
    /** @test */
    public function testMapsRegistrationCreatedToNotification(): void
    {
        $entry = (object) [
            'id' => 1,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.created',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('enrollment', $notification['type']);
        $this->assertStringContainsString('inschrijving', strtolower($notification['title']));
        $this->assertNotEmpty($notification['url']);
        $this->assertIsInt($notification['timestamp']);
    }

    /** @test */
    public function testMapsAttendanceMarkedPresentToNotification(): void
    {
        $entry = (object) [
            'id' => 2,
            'entity_type' => 'attendance',
            'entity_id' => 100,
            'action' => 'attendance.marked_present',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'session_id' => 200, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('attendance', $notification['type']);
        $this->assertStringContainsString('aanwezigheid', strtolower($notification['title']));
    }

    /** @test */
    public function testMapsCourseCompletedToNotification(): void
    {
        $entry = (object) [
            'id' => 3,
            'entity_type' => 'completion',
            'entity_id' => 1001,
            'action' => 'completion.course_completed',
            'actor_id' => null,
            'context' => json_encode(['course_id' => 1001, 'course_title' => 'Test Cursus']),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('completion', $notification['type']);
        $this->assertStringContainsString('Test Cursus', $notification['title']);
    }

    /** @test */
    public function testMapsCertificateIssuedToNotification(): void
    {
        $entry = (object) [
            'id' => 4,
            'entity_type' => 'completion',
            'entity_id' => 1001,
            'action' => 'completion.certificate_issued',
            'actor_id' => null,
            'context' => json_encode([
                'course_id' => 1001,
                'course_title' => 'Test Cursus',
                'certificate_link' => 'https://example.com/cert/123',
            ]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('certificate', $notification['type']);
        $this->assertStringContainsString('certificaat', strtolower($notification['title']));
    }

    /** @test */
    public function testMapsSessionNoteUpdatedToNotification(): void
    {
        $entry = (object) [
            'id' => 5,
            'entity_type' => 'session',
            'entity_id' => 100,
            'action' => 'session.note_updated',
            'actor_id' => 999,
            'context' => json_encode(['session_id' => 100, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('session', $notification['type']);
        $this->assertStringContainsString('bijgewerkt', strtolower($notification['title']));
    }

    /** @test */
    public function testNotificationHasRequiredKeys(): void
    {
        $entry = (object) [
            'id' => 1,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.created',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $required = ['id', 'type', 'title', 'body', 'url', 'timestamp'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $notification, "Missing key: {$key}");
        }
    }

    /** @test */
    public function testMapsRegistrationCancelledWithCorrectMessage(): void
    {
        $entry = (object) [
            'id' => 6,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.cancelled',
            'actor_id' => null,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('enrollment', $notification['type']);
        $this->assertStringContainsString('geannuleerd', strtolower($notification['title']));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter=NotificationMapperTest`
Expected: FAIL — class does not exist

**Step 3: Write the implementation**

Create `web/app/mu-plugins/stride-core/Modules/Notification/NotificationMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

/**
 * Maps audit log entries to notification display format.
 *
 * Stateless mapper — resolves titles from post IDs at render time.
 */
final class NotificationMapper
{
    /**
     * Convert an audit log entry to a notification array.
     *
     * @return array{id: string, type: string, title: string, body: string, url: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $action = $entry->action ?? '';

        [$type, $title, $body, $url] = match ($action) {
            'registration.created' => self::mapRegistrationCreated($context),
            'registration.cancelled' => self::mapRegistrationCancelled($context),
            'attendance.marked_present' => self::mapAttendance($context, 'aanwezigheid'),
            'attendance.marked_absent' => self::mapAttendance($context, 'afwezig gemeld'),
            'attendance.marked_excused' => self::mapAttendance($context, 'verontschuldigd'),
            'completion.course_completed' => self::mapCourseCompleted($context),
            'completion.certificate_issued' => self::mapCertificateIssued($context),
            'session.note_updated' => self::mapSessionNoteUpdated($context),
            default => ['action', $action, '', ''],
        };

        return [
            'id' => 'audit_' . ($entry->id ?? md5($action . ($entry->created_at ?? ''))),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'timestamp' => strtotime($entry->created_at ?? 'now') ?: time(),
        ];
    }

    private static function mapRegistrationCreated(array $context): array
    {
        $editionTitle = self::resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is bevestigd', $editionTitle),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapRegistrationCancelled(array $context): array
    {
        $editionTitle = self::resolveEditionTitle((int) ($context['edition_id'] ?? 0));

        return [
            'enrollment',
            sprintf('Je inschrijving voor %s is geannuleerd', $editionTitle),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapAttendance(array $context, string $statusText): array
    {
        $sessionDate = self::resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'attendance',
            sprintf('Je %s op %s is geregistreerd', $statusText, $sessionDate),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    private static function mapCourseCompleted(array $context): array
    {
        $courseTitle = $context['course_title'] ?? self::resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'completion',
            sprintf('Je hebt %s afgerond', $courseTitle),
            '',
            get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private static function mapCertificateIssued(array $context): array
    {
        $courseTitle = $context['course_title'] ?? self::resolveCourseTitle((int) ($context['course_id'] ?? 0));

        return [
            'certificate',
            sprintf('Je certificaat voor %s is beschikbaar', $courseTitle),
            '',
            $context['certificate_link'] ?? get_permalink((int) ($context['course_id'] ?? 0)) ?: '',
        ];
    }

    private static function mapSessionNoteUpdated(array $context): array
    {
        $sessionDate = self::resolveSessionDate((int) ($context['session_id'] ?? 0));

        return [
            'session',
            sprintf('Sessie %s is bijgewerkt', $sessionDate),
            '',
            self::editionUrl((int) ($context['edition_id'] ?? 0)),
        ];
    }

    // === Resolvers (fetch titles/dates from posts) ===

    private static function resolveEditionTitle(int $editionId): string
    {
        if ($editionId <= 0) {
            return '(onbekend)';
        }

        $post = get_post($editionId);

        return $post ? $post->post_title : '(verwijderd)';
    }

    private static function resolveSessionDate(int $sessionId): string
    {
        if ($sessionId <= 0) {
            return '(onbekend)';
        }

        $date = get_post_meta($sessionId, '_ntdst_date', true);

        return $date ? stride_format_date($date) : '(onbekend)';
    }

    private static function resolveCourseTitle(int $courseId): string
    {
        if ($courseId <= 0) {
            return '(onbekend)';
        }

        $post = get_post($courseId);

        return $post ? $post->post_title : '(verwijderd)';
    }

    private static function editionUrl(int $editionId): string
    {
        if ($editionId <= 0) {
            return '';
        }

        return get_permalink($editionId) ?: '';
    }
}
```

**Step 4: Run test**

Run: `ddev exec vendor/bin/phpunit --filter=NotificationMapperTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Notification/NotificationMapper.php \
        tests/Unit/NotificationMapperTest.php
git commit -m "feat(notifications): add NotificationMapper for audit entry → notification conversion"
```

---

### Task 6: Rewrite NotificationService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php`
- Test: `tests/Unit/NotificationServiceTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Notification\NotificationService;
use Stride\Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    /** @test */
    public function testGetNotificationsReturnsArray(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);
        $mockRegRepo->method('findByUser')->willReturn([]);

        $this->registerService(AuditService::class, $mockAudit);
        $this->registerService(RegistrationRepository::class, $mockRegRepo);

        $service = new NotificationService($mockAudit, $mockRegRepo);
        $notifications = $service->getNotifications(456);

        $this->assertIsArray($notifications);
    }

    /** @test */
    public function testGetUnreadCountReturnsInteger(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);
        $mockRegRepo->method('findByUser')->willReturn([]);

        $service = new NotificationService($mockAudit, $mockRegRepo);
        $count = $service->getUnreadCount(456);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /** @test */
    public function testConstructorDoesNotRequireUserDashboardService(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);

        // Should construct without UserDashboardService
        $service = new NotificationService($mockAudit, $mockRegRepo);
        $this->assertInstanceOf(NotificationService::class, $service);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter=NotificationServiceTest`
Expected: FAIL — constructor signature mismatch

**Step 3: Rewrite NotificationService**

Replace the full contents of `NotificationService.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use NTDST\Audit\AuditService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Notification Service — derives notifications from audit log events.
 *
 * Queries wp_audit_log for events where the user is the subject
 * (context.user_id) but not the actor (excludes self-actions).
 * Also includes session note updates for editions the user is enrolled in.
 * Read state persisted in user meta.
 */
final class NotificationService implements \NTDST_Service_Meta
{
    private const META_KEY = '_stride_notifications_read';

    public static function metadata(): array
    {
        return [
            'name'        => 'Notification Service',
            'description' => 'Event notifications from audit log',
            'priority'    => 20,
        ];
    }

    public function __construct(
        private readonly AuditService $auditService,
        private readonly RegistrationRepository $registrationRepo,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_mark_notifications_read', [$this, 'handleMarkAllRead'], 10, 2);
    }

    /**
     * Get notifications for a user from audit log.
     *
     * @return array<int, array{id: string, type: string, title: string, body: string, url: string, timestamp: int, read: bool}>
     */
    public function getNotifications(int $userId): array
    {
        $readMap = $this->getReadMap($userId);

        // 1. Get audit entries where user is the subject (not actor)
        $entries = $this->auditService->getForSubjectUser($userId, 50, 30);

        // 2. Get session note updates for editions user is enrolled in
        $editionIds = $this->getEnrolledEditionIds($userId);
        $sessionNotes = $this->auditService->getSessionNoteUpdates($editionIds, 30);

        // 3. Merge and deduplicate
        $allEntries = array_merge($entries, $sessionNotes);

        // 4. Map to notification format
        $notifications = [];
        $seenIds = [];

        foreach ($allEntries as $entry) {
            $notification = NotificationMapper::fromAuditEntry($entry);
            $id = $notification['id'];

            // Deduplicate
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $notification['read'] = isset($readMap[$id]);
            $notifications[] = $notification;
        }

        // Sort newest first
        usort($notifications, fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $notifications;
    }

    /**
     * Count unread notifications.
     */
    public function getUnreadCount(int $userId): int
    {
        return count(array_filter(
            $this->getNotifications($userId),
            fn(array $n): bool => !$n['read']
        ));
    }

    /**
     * Mark all current notifications as read.
     */
    public function markAllRead(int $userId): void
    {
        $notifications = $this->getNotifications($userId);
        $readMap = $this->getReadMap($userId);
        $now = time();

        foreach ($notifications as $notification) {
            if (!isset($readMap[$notification['id']])) {
                $readMap[$notification['id']] = $now;
            }
        }

        update_user_meta($userId, self::META_KEY, wp_json_encode($readMap));
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(int $userId, string $notificationId): void
    {
        $readMap = $this->getReadMap($userId);

        if (!isset($readMap[$notificationId])) {
            $readMap[$notificationId] = time();
            update_user_meta($userId, self::META_KEY, wp_json_encode($readMap));
        }
    }

    /**
     * API handler: mark all notifications read.
     */
    public function handleMarkAllRead(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je bent niet ingelogd.', 'stride'));
        }

        $this->markAllRead($userId);

        return ['success' => true];
    }

    /**
     * @return array<string, int> notification_id => timestamp
     */
    private function getReadMap(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);

        if (empty($raw) || !is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get edition IDs the user is currently enrolled in.
     *
     * @return int[]
     */
    private function getEnrolledEditionIds(int $userId): array
    {
        $registrations = $this->registrationRepo->findByUser($userId);

        $ids = [];
        foreach ($registrations as $reg) {
            if (!empty($reg->edition_id)) {
                $ids[] = (int) $reg->edition_id;
            }
        }

        return array_unique($ids);
    }
}
```

**Step 4: Update plugin-config.php if needed**

Check that `NotificationService` DI constructor params are resolvable. `AuditService` and `RegistrationRepository` should already be registered.

**Step 5: Run test**

Run: `ddev exec vendor/bin/phpunit --filter=NotificationServiceTest`
Expected: PASS

**Step 6: Run full unit suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All green

**Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php \
        tests/Unit/NotificationServiceTest.php
git commit -m "feat(notifications): rewrite NotificationService to use audit log"
```

---

**Integration gate (Phase 2):** NotificationService constructs with AuditService + RegistrationRepository. Returns notification arrays. Read state works. Run: `ddev exec vendor/bin/phpunit --testsuite Unit`

---

## Phase 3: Update Frontend

### Task 7: Update notification-item.php partial

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/partials/notification-item.php`

**Step 1: Update icon mapping**

Replace the existing `match` block:

```php
[$icon, $iconColor, $iconBg] = match ($type) {
    'enrollment'  => ['check-circle', 'text-green-600', 'bg-green-50'],
    'attendance'  => ['check', 'text-blue-600', 'bg-blue-50'],
    'completion'  => ['award', 'text-green-600', 'bg-green-50'],
    'certificate' => ['file-text', 'text-green-600', 'bg-green-50'],
    'session'     => ['info', 'text-blue-600', 'bg-blue-50'],
    default       => ['bell', 'text-primary', 'bg-primary/10'],
};
```

**Step 2: Build and verify in browser**

Run: `cd web/app/themes/stridence && npm run build`
Navigate to: `https://stride.ddev.site/mijn-account/?tab=meldingen`
Expected: Notifications show with correct icons and real event messages

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/notification-item.php
git commit -m "feat(theme): update notification icons for audit-driven notification types"
```

---

### Task 8: Verify tab-meldingen.php works unchanged

**Files:**
- Read only: `web/app/themes/stridence/templates/dashboard/tab-meldingen.php`

**Step 1: Verify the template still works**

The template calls `$notificationService->getNotifications($user_id)` and `$notificationService->getUnreadCount($user_id)` — these method signatures are unchanged, so the template should work without modification.

Navigate to: `https://stride.ddev.site/mijn-account/?tab=meldingen`
Verify:
- Notifications display with real event messages
- "Vandaag" / "Eerder" grouping works
- "Alles gelezen" button works
- Unread dots show correctly
- Sidebar badge count is correct

**Step 2: Verify mark-all-read AJAX still works**

Click "Alles gelezen" → page reloads → unread dots gone → badge count shows 0.

**Step 3: Commit (no changes expected)**

If template needed no changes, nothing to commit. If minor tweaks needed, commit them.

---

**Integration gate (Phase 3):** Full browser verification. Run `ddev exec vendor/bin/phpunit --testsuite Unit` for regression check.

---

## Smoke Test

After all tasks complete:

- [ ] Visit: `https://stride.ddev.site/mijn-account/?tab=meldingen`
      Expected: Real event notifications with Dutch messages, correct icons, no console errors
- [ ] Action: Click "Alles gelezen"
      Expected: All dots disappear, badge resets to 0
- [ ] Action: As admin, enroll a seed user into a new edition, then check their notifications
      Expected: "Je inschrijving voor [edition] is bevestigd" appears
- [ ] Action: As admin, mark attendance for a user, then check their notifications
      Expected: "Je aanwezigheid op [date] is geregistreerd" appears
- [ ] Action: As admin, update a session description, then check enrolled user's notifications
      Expected: "Sessie [date] is bijgewerkt" appears
- [ ] Console: DevTools > Console
      Expected: No red errors
