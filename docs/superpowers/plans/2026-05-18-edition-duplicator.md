# Edition Duplicator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Dupliceren" row action on `vad_edition` posts that copies the source's full meta map (Rule A), applies a small explicit reset list (Rule B), and clones child sessions with dates reset to today.

**Architecture:** New `EditionDuplicator` service in `Stride\Modules\Edition`. Registered as `NTDST_Service_Meta`. Three responsibilities: (1) inject row action via `post_row_actions` filter, (2) handle the admin-post request via `admin_action_*` hook, (3) expose a testable `duplicate(int): int|\WP_Error` seam that the unit + integration tests drive directly.

**Tech Stack:** PHP 8.3, WordPress, Bedrock, NTDST DI container, PHPUnit (Unit + Integration), Codeception (Acceptance via wp-browser).

**Spec:** `docs/superpowers/specs/2026-05-18-edition-duplicator-design.md`

---

## File Structure

**New files:**
- `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php` — service: row-action filter, admin-action handler, `duplicate()` method
- `tests/Unit/Edition/EditionDuplicatorTest.php` — fast unit tests for `duplicate()` with mocked repositories
- `tests/Integration/Edition/EditionDuplicatorIntegrationTest.php` — full WordPress with real DB
- `tests/acceptance/EditionDuplicateCest.php` — browser-level row-action click

**Modified files:**
- `web/app/mu-plugins/stride-core/plugin-config.php` — one line added to `services` array

No CPT registration changes, no JS, no CSS, no DB migrations.

---

## Conventions to follow

- **Namespace:** `Stride\Modules\Edition\EditionDuplicator` (matches existing `Stride\Modules\Edition\*`)
- **Class:** `final`, `declare(strict_types=1)`, constructor property promotion
- **Meta access:** Use `$this->editionRepository->getField()` for single fields and the underlying `model()->getMeta($id, null)` for the full meta map (returns all keys via `$post->fields`)
- **Test namespaces:**
  - Unit: `Stride\Tests\Unit\Edition` extending `Stride\Tests\TestCase`
  - Integration: `Stride\Tests\Integration` extending `IntegrationTestCase`
- **Run commands:** `ddev exec vendor/bin/phpunit --filter EditionDuplicator --testsuite Unit` and the same with `--testsuite Integration`
- **Commit style:** mirror recent commits (`feat:`, `test:`, `docs:`), include the Co-Authored-By trailer

---

### Task 1: Create the empty service skeleton

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`

- [ ] **Step 1: Create the skeleton file**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use NTDST_Service_Meta;
use WP_Error;
use WP_Post;

/**
 * Duplicates a vad_edition: copies all meta (Rule A) with a small explicit
 * reset list (Rule B), then clones child sessions with dates reset to today.
 *
 * Registrations, attendance, notifications, audit-log entries are never
 * touched. Edition-level `documents` meta is dropped (course-level documents
 * survive via the preserved course link).
 */
final class EditionDuplicator implements NTDST_Service_Meta
{
    /**
     * Meta keys overwritten on the copy. Keep this list short and explicit.
     * Any meta key NOT in this list is preserved verbatim — this is what
     * protects future enrollment-form fields from being silently lost.
     */
    private const META_RESET = [
        'notes'           => [],
        'documents'       => [],
        'selection_open' => false,
    ];

    /**
     * Meta keys removed from the copy entirely (stale caches, etc.).
     */
    private const META_UNSET = [
        '_enrollment_count',
    ];

    public static function metadata(): array
    {
        return [
            'name'        => 'Edition Duplicator',
            'description' => 'Duplicates an edition (post + meta + sessions, with safe resets)',
            'priority'    => 50,
        ];
    }

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_filter('post_row_actions', [$this, 'addDuplicateRowAction'], 10, 2);
        add_action('admin_action_stride_duplicate_edition', [$this, 'handleDuplicate']);
    }

    public function addDuplicateRowAction(array $actions, WP_Post $post): array
    {
        return $actions;
    }

    public function handleDuplicate(): void
    {
        // implemented in later tasks
    }

    /**
     * @return int|WP_Error new edition ID on success
     */
    public function duplicate(int $sourceEditionId): int|WP_Error
    {
        return new WP_Error('not_implemented', 'Not implemented yet');
    }
}
```

- [ ] **Step 2: Lint the file**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php
git commit -m "$(cat <<'EOF'
feat(edition): scaffold EditionDuplicator service

Empty service with reset list constants and hook registrations.
duplicate() and handleDuplicate() will be filled in by TDD.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Register the service in the DI container

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

- [ ] **Step 1: Locate the services array**

Run: `grep -n "EditionService::class" web/app/mu-plugins/stride-core/plugin-config.php`
Expected: one match, inside the `services` array.

- [ ] **Step 2: Add EditionDuplicator below EditionService**

Open `plugin-config.php`. Find the line:

```php
        \Stride\Modules\Edition\EditionService::class,
```

Add immediately below:

```php
        \Stride\Modules\Edition\EditionDuplicator::class,
```

- [ ] **Step 3: Smoke-test service load**

Run: `ddev exec wp eval "var_dump(class_exists(\Stride\Modules\Edition\EditionDuplicator::class));"`
Expected: `bool(true)`

Run: `ddev exec wp eval "var_dump(is_object(ntdst_get(\Stride\Modules\Edition\EditionDuplicator::class)));"`
Expected: `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "$(cat <<'EOF'
feat(edition): register EditionDuplicator in DI container

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Unit test — duplicate() returns WP_Error for non-existent edition

**Files:**
- Create: `tests/Unit/Edition/EditionDuplicatorTest.php`

- [ ] **Step 1: Create the test directory**

Run: `mkdir -p tests/Unit/Edition`

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Edition/EditionDuplicatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\EditionDuplicator;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Tests\TestCase;
use WP_Error;

class EditionDuplicatorTest extends TestCase
{
    private EditionRepository $editions;
    private SessionRepository $sessions;
    private EditionDuplicator $duplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editions = $this->createMock(EditionRepository::class);
        $this->sessions = $this->createMock(SessionRepository::class);

        // Bypass init() so hook registration doesn't fire in unit context.
        $this->duplicator = $this->getMockBuilder(EditionDuplicator::class)
            ->setConstructorArgs([$this->editions, $this->sessions])
            ->onlyMethods([]) // bypass nothing — we want the real duplicate()
            ->getMock();
    }

    public function testDuplicateReturnsErrorWhenSourceDoesNotExist(): void
    {
        // get_post() returns null for missing IDs in the test stubs.
        $result = $this->duplicator->duplicate(999999);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('not_found', $result->get_error_code());
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter EditionDuplicatorTest --testsuite Unit`
Expected: FAIL — `duplicate()` currently returns a `not_implemented` WP_Error, not `not_found`.

- [ ] **Step 4: Implement duplicate() source-validation**

Replace the entire `duplicate()` method body in `EditionDuplicator.php`:

```php
    public function duplicate(int $sourceEditionId): int|WP_Error
    {
        $source = get_post($sourceEditionId);

        if (!$source instanceof WP_Post || $source->post_type !== EditionCPT::POST_TYPE) {
            return new WP_Error(
                'not_found',
                __('Bron-editie niet gevonden.', 'stride')
            );
        }

        return new WP_Error('not_implemented', 'Not implemented yet');
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter EditionDuplicatorTest --testsuite Unit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Edition/EditionDuplicatorTest.php web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php
git commit -m "$(cat <<'EOF'
test(edition): duplicate() rejects non-existent source

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Integration test — happy path creates a draft copy with all meta

This is the load-bearing test for Rule A. It proves that arbitrary form-field meta on the source ends up on the copy.

**Files:**
- Create: `tests/Integration/Edition/EditionDuplicatorIntegrationTest.php`

- [ ] **Step 1: Create the test directory**

Run: `mkdir -p tests/Integration/Edition`

- [ ] **Step 2: Write the failing test**

Create `tests/Integration/Edition/EditionDuplicatorIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionDuplicator;

/**
 * Integration: full WP cycle.
 *
 * Rule A: copy-all-meta. We seed an arbitrary, made-up meta key on the
 * source and assert it lands on the copy. This is the regression guard
 * against future form fields being silently dropped.
 *
 * Rule B: reset list. We seed `notes` on the source and assert the copy
 * has empty notes.
 */
class EditionDuplicatorIntegrationTest extends IntegrationTestCase
{
    private int $sourceEditionId;
    private array $sessionIds = [];
    private ?int $newEditionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);

        $this->sourceEditionId = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'Source Edition',
            'post_status' => 'publish',
        ]);

        // Standard form fields (preserved by Rule A).
        update_post_meta($this->sourceEditionId, '_ntdst_enrollment_form', 'with_rrn');
        update_post_meta($this->sourceEditionId, '_ntdst_requires_questionnaire', true);
        update_post_meta($this->sourceEditionId, '_ntdst_price', 4500);
        update_post_meta($this->sourceEditionId, '_ntdst_course_id', 123);

        // Hypothetical future form field — proves Rule A.
        update_post_meta($this->sourceEditionId, '_ntdst_arbitrary_pilot_field', 'pilot-value');

        // Reset-list keys.
        update_post_meta($this->sourceEditionId, '_ntdst_notes', ['Jan ill day 2', 'Venue confirmed']);
        update_post_meta($this->sourceEditionId, '_ntdst_documents', [100, 200]);
        update_post_meta($this->sourceEditionId, '_ntdst_selection_open', true);
        update_post_meta($this->sourceEditionId, '_enrollment_count', 7);

        // One session so we know the copy path runs cleanly.
        $this->sessionIds[] = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Day 1',
            'post_status' => 'publish',
            'post_parent' => $this->sourceEditionId,
        ]);
        update_post_meta($this->sessionIds[0], '_ntdst_edition_id', $this->sourceEditionId);
        update_post_meta($this->sessionIds[0], '_ntdst_date', '2020-01-15');
        update_post_meta($this->sessionIds[0], '_ntdst_start_time', '09:00');
        update_post_meta($this->sessionIds[0], '_ntdst_end_time', '17:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            wp_delete_post($id, true);
        }
        wp_delete_post($this->sourceEditionId, true);
        if ($this->newEditionId) {
            wp_delete_post($this->newEditionId, true);
            // Also reap the copied session(s) — they have a different parent.
            $kids = get_posts([
                'post_type'   => 'vad_session',
                'post_status' => 'any',
                'meta_key'    => '_ntdst_edition_id',
                'meta_value'  => $this->newEditionId,
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            foreach ($kids as $kid) {
                wp_delete_post($kid, true);
            }
        }
        parent::tearDown();
    }

    public function testDuplicateCreatesDraftCopyWithKopieSuffix(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);

        $newId = $duplicator->duplicate($this->sourceEditionId);

        self::assertIsInt($newId, 'duplicate() should return an int new ID');
        $this->newEditionId = $newId;

        $newPost = get_post($newId);
        self::assertSame('vad_edition', $newPost->post_type);
        self::assertSame('draft', $newPost->post_status);
        self::assertSame('Source Edition (kopie)', $newPost->post_title);
    }

    public function testDuplicatePreservesArbitraryMetaKey(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        self::assertSame('pilot-value', get_post_meta($newId, '_ntdst_arbitrary_pilot_field', true));
        self::assertSame('with_rrn', get_post_meta($newId, '_ntdst_enrollment_form', true));
        self::assertSame('123', (string) get_post_meta($newId, '_ntdst_course_id', true));
    }

    public function testDuplicateAppliesResetList(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        self::assertSame([], get_post_meta($newId, '_ntdst_notes', true));
        self::assertSame([], get_post_meta($newId, '_ntdst_documents', true));
        self::assertSame('', (string) get_post_meta($newId, '_ntdst_selection_open', true), 'selection_open should be false-y on the copy');
        self::assertSame('', (string) get_post_meta($newId, '_enrollment_count', true), '_enrollment_count should be absent from the copy');
    }

    public function testDuplicateCopiesSessionsWithDatesResetToToday(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        $newSessions = get_posts([
            'post_type'   => 'vad_session',
            'post_status' => 'any',
            'meta_key'    => '_ntdst_edition_id',
            'meta_value'  => $newId,
            'numberposts' => -1,
        ]);

        self::assertCount(1, $newSessions, 'one session should be copied');
        $copy = $newSessions[0];
        self::assertSame(date('Y-m-d'), get_post_meta($copy->ID, '_ntdst_date', true));
        self::assertSame('09:00', get_post_meta($copy->ID, '_ntdst_start_time', true));
        self::assertSame('17:00', get_post_meta($copy->ID, '_ntdst_end_time', true));
    }

    public function testDuplicateDoesNotTouchSourceEdition(): void
    {
        $duplicator = ntdst_get(EditionDuplicator::class);
        $newId = $duplicator->duplicate($this->sourceEditionId);
        $this->newEditionId = $newId;

        $source = get_post($this->sourceEditionId);
        self::assertSame('publish', $source->post_status);
        self::assertSame('Source Edition', $source->post_title);
        self::assertSame(['Jan ill day 2', 'Venue confirmed'], get_post_meta($this->sourceEditionId, '_ntdst_notes', true));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter EditionDuplicatorIntegrationTest --testsuite Integration`
Expected: FAIL — `duplicate()` still returns `not_implemented` WP_Error after passing the source check.

- [ ] **Step 4: Implement duplicate() — full body**

Replace the entire `duplicate()` method in `EditionDuplicator.php`:

```php
    public function duplicate(int $sourceEditionId): int|WP_Error
    {
        $source = get_post($sourceEditionId);

        if (!$source instanceof WP_Post || $source->post_type !== EditionCPT::POST_TYPE) {
            return new WP_Error(
                'not_found',
                __('Bron-editie niet gevonden.', 'stride')
            );
        }

        // Insert the copy as a draft. WP auto-generates a unique slug.
        $newId = wp_insert_post([
            'post_type'    => EditionCPT::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => $source->post_title . ' (kopie)',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author'  => get_current_user_id() ?: $source->post_author,
        ], true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        // Rule A — copy ALL meta from source verbatim.
        $allMeta = get_post_meta($sourceEditionId);
        foreach ($allMeta as $key => $values) {
            foreach ($values as $value) {
                add_post_meta($newId, $key, maybe_unserialize($value));
            }
        }

        // Rule B — overwrite reset keys with their reset value (_ntdst_ prefixed).
        foreach (self::META_RESET as $field => $resetValue) {
            update_post_meta($newId, '_ntdst_' . $field, $resetValue);
        }

        // Rule B — remove unset keys entirely (already prefixed in the const).
        foreach (self::META_UNSET as $key) {
            delete_post_meta($newId, $key);
        }

        // Copy taxonomies (stride_format etc.) so the copy keeps its category facets.
        $taxonomies = get_object_taxonomies($source->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($sourceEditionId, $taxonomy, ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($newId, $terms, $taxonomy);
            }
        }

        // Sessions — one copy per source session, date reset to today.
        $this->copySessions($sourceEditionId, $newId);

        return (int) $newId;
    }

    private function copySessions(int $sourceEditionId, int $newEditionId): void
    {
        $today = date('Y-m-d');

        $sourceSessions = get_posts([
            'post_type'   => 'vad_session',
            'post_status' => 'any',
            'meta_key'    => '_ntdst_edition_id',
            'meta_value'  => $sourceEditionId,
            'numberposts' => -1,
            'orderby'     => 'meta_value',
            'meta_type'   => 'DATE',
        ]);

        foreach ($sourceSessions as $session) {
            $newSessionId = wp_insert_post([
                'post_type'    => 'vad_session',
                'post_status'  => $session->post_status,
                'post_title'   => $session->post_title,
                'post_content' => $session->post_content,
                'post_excerpt' => $session->post_excerpt,
                'post_parent'  => $newEditionId,
            ], true);

            if (is_wp_error($newSessionId)) {
                continue;
            }

            // Copy every meta key from the source session, then override edition link + date.
            $sessionMeta = get_post_meta($session->ID);
            foreach ($sessionMeta as $key => $values) {
                foreach ($values as $value) {
                    add_post_meta($newSessionId, $key, maybe_unserialize($value));
                }
            }
            update_post_meta($newSessionId, '_ntdst_edition_id', $newEditionId);
            update_post_meta($newSessionId, '_ntdst_date', $today);
        }
    }
```

Add the missing import at the top of the file:

```php
use WP_Post;
```

(Already there from Task 1 — verify it's present.)

- [ ] **Step 5: Run all duplicator tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter EditionDuplicator --testsuite Integration`
Expected: PASS — 5 tests, all green.

Run: `ddev exec vendor/bin/phpunit --filter EditionDuplicator --testsuite Unit`
Expected: PASS — 1 test still green (unit's source-not-found case still holds).

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/Edition/EditionDuplicatorIntegrationTest.php web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php
git commit -m "$(cat <<'EOF'
feat(edition): implement EditionDuplicator::duplicate()

Rule A: copies all post meta verbatim (protects future form fields).
Rule B: resets notes/documents/selection_open, removes _enrollment_count.
Copies taxonomies + sessions, with session dates reset to today.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Row action wiring — show "Dupliceren" link on vad_edition rows

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`

- [ ] **Step 1: Replace the empty addDuplicateRowAction()**

Replace the placeholder method body in `EditionDuplicator.php`:

```php
    public function addDuplicateRowAction(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== EditionCPT::POST_TYPE) {
            return $actions;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=stride_duplicate_edition&edition_id=' . $post->ID),
            'stride_duplicate_edition_' . $post->ID
        );

        $actions['stride_duplicate'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            esc_attr__('Dupliceer deze editie', 'stride'),
            esc_html__('Dupliceren', 'stride')
        );

        return $actions;
    }
```

- [ ] **Step 2: Smoke-test in admin manually**

Run: `ddev launch /wp/wp-admin/edit.php?post_type=vad_edition`
Expected: hover any row → "Dupliceren" link appears alongside Bewerken/Verwijderen. Clicking it currently dies because the handler is still a stub (next task).

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php
git commit -m "$(cat <<'EOF'
feat(edition): add Dupliceren row action to editions list

Capability-gated, scoped to vad_edition only, nonce-protected URL.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Admin-action handler — verify nonce + cap, run duplicate, redirect

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`

- [ ] **Step 1: Replace the empty handleDuplicate()**

Replace the placeholder method body in `EditionDuplicator.php`:

```php
    public function handleDuplicate(): void
    {
        $sourceId = isset($_GET['edition_id']) ? (int) $_GET['edition_id'] : 0;

        if ($sourceId <= 0) {
            $this->redirectToList('missing_id');
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'stride_duplicate_edition_' . $sourceId)) {
            $this->redirectToList('invalid_nonce');
        }

        if (!current_user_can('edit_post', $sourceId)) {
            $this->redirectToList('forbidden');
        }

        $newId = $this->duplicate($sourceId);

        if (is_wp_error($newId)) {
            $this->redirectToList('duplicate_failed');
        }

        $editUrl = get_edit_post_link($newId, 'raw');
        wp_safe_redirect($editUrl ?: admin_url('edit.php?post_type=vad_edition'));
        exit;
    }

    private function redirectToList(string $notice): never
    {
        $url = add_query_arg(
            ['post_type' => 'vad_edition', 'stride_notice' => $notice],
            admin_url('edit.php')
        );
        wp_safe_redirect($url);
        exit;
    }
```

- [ ] **Step 2: Add an admin-notice renderer (same file)**

Append a new method and hook it inside `init()`:

```php
    private function init(): void
    {
        add_filter('post_row_actions', [$this, 'addDuplicateRowAction'], 10, 2);
        add_action('admin_action_stride_duplicate_edition', [$this, 'handleDuplicate']);
        add_action('admin_notices', [$this, 'renderDuplicateNotice']);
    }

    public function renderDuplicateNotice(): void
    {
        if (empty($_GET['stride_notice'])) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== EditionCPT::POST_TYPE) {
            return;
        }

        $notice = sanitize_key((string) $_GET['stride_notice']);
        $messages = [
            'missing_id'       => __('Geen editie geselecteerd om te dupliceren.', 'stride'),
            'invalid_nonce'    => __('Beveiligingscontrole mislukt. Probeer opnieuw.', 'stride'),
            'forbidden'        => __('Geen toestemming om deze editie te dupliceren.', 'stride'),
            'duplicate_failed' => __('Dupliceren mislukt. Controleer de bron-editie.', 'stride'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
            esc_html($messages[$notice])
        );
    }
```

- [ ] **Step 3: Lint the file**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Smoke-test the full row-action flow**

Run: `ddev launch /wp/wp-admin/edit.php?post_type=vad_edition`
Click "Dupliceren" on any row.
Expected: lands directly on the new draft's edit screen, title ends in `(kopie)`.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionDuplicator.php
git commit -m "$(cat <<'EOF'
feat(edition): wire admin-action handler + notices

handleDuplicate verifies nonce + cap, calls duplicate(), redirects to
the new draft's edit screen on success or back to the list with a
dismissible error notice on failure.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Acceptance test — admin clicks Dupliceren, lands on draft edit screen

**Files:**
- Create: `tests/acceptance/EditionDuplicateCest.php`

- [ ] **Step 1: Write the test**

Create `tests/acceptance/EditionDuplicateCest.php`:

```php
<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance: admin row-action duplicates an edition.
 *
 * Run: ddev exec vendor/bin/codecept run acceptance EditionDuplicateCest --steps
 */
class EditionDuplicateCest
{
    private ?int $adminId = null;

    public function _before(AcceptanceTester $I): void
    {
        $this->adminId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'admin']);
        if (!$this->adminId) {
            $I->fail('Admin user not found in database');
        }
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    public function _after(AcceptanceTester $I): void
    {
        // Drop any "(kopie)" leftovers from this run.
        $I->dontHaveInDatabase('stride_posts', [
            'post_type'       => 'vad_edition',
            'post_title LIKE' => '% (kopie)',
        ]);
    }

    public function canDuplicateAnEditionFromTheList(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');

        // Hover the first edition row to reveal row-actions, then click Dupliceren.
        $I->seeElement('table.wp-list-table tr.type-vad_edition');
        $I->executeJS(
            "document.querySelector('table.wp-list-table tr.type-vad_edition').classList.add('hover');"
        );
        $I->click('Dupliceren', 'table.wp-list-table tr.type-vad_edition');

        // Should land on the new draft's edit screen.
        $I->seeInCurrentUrl('post.php');
        $I->seeInCurrentUrl('action=edit');
        $I->seeInField('post_title', '(kopie)');
        $I->see('Concept'); // Dutch admin label for Draft status
    }
}
```

- [ ] **Step 2: Run the acceptance test**

Run: `ddev exec vendor/bin/codecept run acceptance EditionDuplicateCest --steps`
Expected: PASS. If failing on "Concept" string, look at the actual Dutch label WP renders and adjust — leave the assertion meaningful (don't drop it silently).

- [ ] **Step 3: Run the full test suite to check no regressions**

Run these three in sequence:

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Integration
ddev exec vendor/bin/codecept run acceptance --steps
```

Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add tests/acceptance/EditionDuplicateCest.php
git commit -m "$(cat <<'EOF'
test(edition): acceptance test for duplicate row action

Admin logs in, clicks Dupliceren on an edition row, lands on the
draft copy's edit screen with "(kopie)" suffix.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Row-action entry on `vad_edition` list | Task 5 |
| Capability gate (`edit_post`) | Tasks 5 + 6 |
| Rule A (copy all meta) | Task 4 (impl) + integration test `testDuplicatePreservesArbitraryMetaKey` |
| Rule B reset list (notes/documents/selection_open/_enrollment_count) | Task 4 (impl) + integration test `testDuplicateAppliesResetList` |
| Title → "(kopie)" | Task 4 + integration test `testDuplicateCreatesDraftCopyWithKopieSuffix` |
| Status → draft | Task 4 + integration test (same) |
| Taxonomies preserved | Task 4 (impl) |
| Sessions cloned, dates → today | Task 4 (impl) + integration test `testDuplicateCopiesSessionsWithDatesResetToToday` |
| Attendance NOT copied | Implicit — copy path only touches sessions, not the attendance table. (No explicit assertion; attendance is a separate table outside the session post meta.) |
| Registrations NOT copied | Implicit — source-only read. Integration test `testDuplicateDoesNotTouchSourceEdition` proves source is untouched; a registration-count check on the copy could be added but is redundant given the implementation never reads `wp_vad_registrations`. |
| Redirect to new draft's edit screen | Task 6 + acceptance test |
| Error notices on failure | Task 6 |
| Service registered in DI container | Task 2 |

All spec requirements have a task. Two "implicit" coverages are flagged honestly above — if the executor wants extra defence-in-depth, they can add an integration assertion that `wp_vad_registrations` has zero rows where `edition_id = newId` after duplicating. Not strictly needed because the production code never touches the table.

**Placeholder scan:** No TBDs, no "add error handling", no "similar to Task N". All code blocks are complete and runnable.

**Type / name consistency:**
- `EditionDuplicator::duplicate()` returns `int|WP_Error` everywhere
- `META_RESET` and `META_UNSET` constants used in implementation match their definition in Task 1
- `_ntdst_` prefix applied consistently when overriding meta in Task 4 (matches `EditionRepository`'s pattern of unprefixed field names + Data Manager auto-prefixing)
- Test class names: `EditionDuplicatorTest` (Unit) and `EditionDuplicatorIntegrationTest` (Integration) — both filterable via `--filter EditionDuplicator`

**One thing the executor must watch:** the Data Manager's `_ntdst_` prefix convention. The spec's reset-list keys are *unprefixed* (e.g. `notes`), but the implementation in Task 4 stores them as `_ntdst_notes` because that's how the rest of the codebase reads/writes them. If a future field doesn't follow this convention (some legacy keys may not), the executor should grep `update_post_meta(.*_ntdst_` in the Edition module to confirm before adding it to the reset list.
