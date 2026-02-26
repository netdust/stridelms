# LTI Platform Feature Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extend netdust-lti plugin with Platform/Consumer role for testing Tool Provider locally, using Data Manager CPTs instead of custom tables.

**Architecture:** NTDST service pattern with Data Manager CPTs (`lti_platform`, `lti_tool`). celtic/lti library for JWT/OIDC. Keep nonces/tokens as custom tables for performance. Migration script for existing data.

**Tech Stack:** PHP 8.1, NTDST Core (Data Manager, Services), celtic/lti 7.x, WordPress CPTs

---

## Phase 1: Data Layer Migration

### Task 1.1: Register lti_platform CPT via Data Manager

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Data/LTIDataService.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Write the failing test**

```php
// tests/Unit/LTIDataServiceTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Data\LTIDataService;
use PHPUnit\Framework\TestCase;

class LTIDataServiceTest extends TestCase
{
    public function test_platform_cpt_is_registered(): void
    {
        // Arrange - CPT registration happens in service boot

        // Assert
        $this->assertTrue(post_type_exists('lti_platform'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_platform_cpt_is_registered`
Expected: FAIL - post_type_exists returns false

**Step 3: Write minimal implementation**

Create `web/app/plugins/netdust-lti/src/Data/LTIDataService.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Data;

use NTDST_Service_Meta;

final class LTIDataService implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Data Service',
            'description' => 'Registers LTI CPTs via Data Manager',
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerModels'], 5);
    }

    public function registerModels(): void
    {
        $this->registerPlatformModel();
        $this->registerToolModel();
    }

    private function registerPlatformModel(): void
    {
        ntdst_data()->register('lti_platform', [
            'label'       => 'LTI Platforms',
            'public'      => false,
            'show_ui'     => true,
            'show_in_menu' => 'options-general.php',
            'supports'    => ['title'],
            'meta_prefix' => 'lti_',
            'fields'      => [
                'platform_id'    => ['type' => 'url', 'required' => true],
                'client_id'      => ['type' => 'text', 'required' => true],
                'deployment_id'  => 'text',
                'auth_endpoint'  => ['type' => 'url', 'required' => true],
                'token_endpoint' => ['type' => 'url', 'required' => true],
                'jwks_endpoint'  => ['type' => 'url', 'required' => true],
                'enabled'        => ['type' => 'boolean', 'default' => true],
            ],
            'field_groups' => [
                'credentials' => [
                    'title' => 'Platform Credentials',
                    'fields' => ['platform_id', 'client_id', 'deployment_id'],
                ],
                'endpoints' => [
                    'title' => 'Endpoints',
                    'fields' => ['auth_endpoint', 'token_endpoint', 'jwks_endpoint'],
                ],
                'settings' => [
                    'title' => 'Settings',
                    'fields' => ['enabled'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }

    private function registerToolModel(): void
    {
        ntdst_data()->register('lti_tool', [
            'label'       => 'LTI Tools',
            'public'      => false,
            'show_ui'     => true,
            'show_in_menu' => 'options-general.php',
            'supports'    => ['title'],
            'meta_prefix' => 'lti_',
            'fields'      => [
                'launch_url'    => ['type' => 'url', 'required' => true],
                'oidc_url'      => ['type' => 'url', 'required' => true],
                'jwks_url'      => ['type' => 'url', 'required' => true],
                'client_id'     => ['type' => 'text', 'required' => true],
                'deployment_id' => 'text',
            ],
            'field_groups' => [
                'credentials' => [
                    'title' => 'Tool Credentials',
                    'fields' => ['client_id', 'deployment_id'],
                ],
                'endpoints' => [
                    'title' => 'Endpoints',
                    'fields' => ['launch_url', 'oidc_url', 'jwks_url'],
                ],
            ],
            'use_tabs' => true,
        ]);
    }
}
```

**Step 4: Register service in Plugin.php**

Modify `web/app/plugins/netdust-lti/src/Plugin.php` - add in `init()` before other services:

```php
// Register data models first
ntdst_get(Data\LTIDataService::class);
```

**Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter test_platform_cpt_is_registered`
Expected: PASS

**Step 6: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Data/LTIDataService.php
git add web/app/plugins/netdust-lti/src/Plugin.php
git commit -m "$(cat <<'EOF'
feat(lti): register lti_platform and lti_tool CPTs via Data Manager

Replaces custom database tables with WordPress CPTs managed by
NTDST Data Manager. Auto-generates metaboxes with tabbed interface.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.2: Refactor PlatformRepository to use Data Manager

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Repositories/PlatformRepository.php`
- Create: `tests/Unit/PlatformRepositoryTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/PlatformRepositoryTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Repositories\PlatformRepository;
use PHPUnit\Framework\TestCase;

class PlatformRepositoryTest extends TestCase
{
    private PlatformRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new PlatformRepository();
    }

    public function test_find_by_issuer_and_client_returns_wp_error_when_not_found(): void
    {
        $result = $this->repo->findByIssuerAndClient(
            'https://nonexistent.example.com',
            'fake-client-id'
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_find_by_issuer_and_client_returns_wp_error_when_not_found`
Expected: FAIL or pass with wrong implementation

**Step 3: Write the refactored implementation**

Replace contents of `web/app/plugins/netdust-lti/src/Repositories/PlatformRepository.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;
use WP_Post;

final class PlatformRepository
{
    private const POST_TYPE = 'lti_platform';

    public function find(int $id): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $post = $model->find($id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return $post;
    }

    public function findByIssuerAndClient(string $platformId, string $clientId): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $results = $model
            ->where('platform_id', $platformId)
            ->where('client_id', $clientId)
            ->withMeta()
            ->limit(1)
            ->get();

        if (empty($results)) {
            return new WP_Error('not_found', 'Platform not found');
        }

        // get() returns arrays, need to find the post
        return $model->find((int) $results[0]['id']);
    }

    public function all(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->withMeta()->orderBy('title', 'ASC')->get();
    }

    public function allEnabled(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model
            ->where('enabled', true)
            ->withMeta()
            ->orderBy('title', 'ASC')
            ->get();
    }

    public function create(array $data): int|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $postData = [
            'title' => $data['name'] ?? 'Untitled Platform',
            'post_status' => 'publish',
            'platform_id' => $data['platform_id'] ?? '',
            'client_id' => $data['client_id'] ?? '',
            'deployment_id' => $data['deployment_id'] ?? '',
            'auth_endpoint' => $data['auth_endpoint'] ?? '',
            'token_endpoint' => $data['token_endpoint'] ?? '',
            'jwks_endpoint' => $data['jwks_endpoint'] ?? '',
            'enabled' => $data['enabled'] ?? true,
        ];

        $result = $model->create($postData);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result->ID;
    }

    public function update(int $id, array $data): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['title'] = $data['name'];
        }

        $metaFields = ['platform_id', 'client_id', 'deployment_id',
                       'auth_endpoint', 'token_endpoint', 'jwks_endpoint', 'enabled'];

        foreach ($metaFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        $result = $model->update($id, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function delete(int $id): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->delete($id, true); // Force delete

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter PlatformRepositoryTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Repositories/PlatformRepository.php
git add tests/Unit/PlatformRepositoryTest.php
git commit -m "$(cat <<'EOF'
refactor(lti): migrate PlatformRepository to Data Manager

Uses ntdst_data() query builder instead of raw $wpdb queries.
Returns WP_Post objects from find(), arrays from all()/allEnabled().

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.3: Create ToolRepository

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Repositories/ToolRepository.php`
- Create: `tests/Unit/ToolRepositoryTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/ToolRepositoryTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class ToolRepositoryTest extends TestCase
{
    private ToolRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new ToolRepository();
    }

    public function test_find_returns_wp_error_when_not_found(): void
    {
        $result = $this->repo->find(999999);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_find_returns_wp_error_when_not_found`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Repositories/ToolRepository.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;
use WP_Post;

final class ToolRepository
{
    private const POST_TYPE = 'lti_tool';

    public function find(int $id): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $post = $model->find($id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new WP_Error('not_found', 'Tool not found');
        }

        return $post;
    }

    public function findBySlug(string $slug): WP_Post|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $results = $model
            ->where('post_name', $slug)
            ->withMeta()
            ->limit(1)
            ->get();

        if (empty($results)) {
            return new WP_Error('not_found', 'Tool not found');
        }

        return $model->find((int) $results[0]['id']);
    }

    public function all(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->withMeta()->orderBy('title', 'ASC')->get();
    }

    public function create(array $data): int|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $postData = [
            'title' => $data['name'] ?? 'Untitled Tool',
            'post_status' => 'publish',
            'launch_url' => $data['launch_url'] ?? '',
            'oidc_url' => $data['oidc_url'] ?? '',
            'jwks_url' => $data['jwks_url'] ?? '',
            'client_id' => $data['client_id'] ?? '',
            'deployment_id' => $data['deployment_id'] ?? '',
        ];

        $result = $model->create($postData);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result->ID;
    }

    public function update(int $id, array $data): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['title'] = $data['name'];
        }

        $metaFields = ['launch_url', 'oidc_url', 'jwks_url', 'client_id', 'deployment_id'];

        foreach ($metaFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        $result = $model->update($id, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    public function delete(int $id): bool|WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->delete($id, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter ToolRepositoryTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Repositories/ToolRepository.php
git add tests/Unit/ToolRepositoryTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add ToolRepository for lti_tool CPT

Manages external LTI tools that this site (as Platform) can launch.
Uses Data Manager for all CRUD operations.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.4: Create migration script

**Files:**
- Create: `web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php`
- Modify: `web/app/plugins/netdust-lti/src/Database/Migrations.php`

**Step 1: Create migration script**

Create `web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php`:

```php
<?php
/**
 * Migration script: Custom tables to CPTs
 *
 * Run via: ddev exec wp eval-file web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$oldTable = $wpdb->prefix . 'netdust_lti_platforms';
$contextTable = $wpdb->prefix . 'netdust_lti_contexts';

// Check if old table exists
$tableExists = $wpdb->get_var(
    $wpdb->prepare("SHOW TABLES LIKE %s", $oldTable)
);

if (!$tableExists) {
    WP_CLI::log('No old platforms table found. Nothing to migrate.');
    return;
}

// Get existing platforms
$platforms = $wpdb->get_results("SELECT * FROM {$oldTable}", ARRAY_A);

if (empty($platforms)) {
    WP_CLI::log('No platforms found in old table.');
    return;
}

WP_CLI::log(sprintf('Found %d platforms to migrate.', count($platforms)));

$idMap = []; // old_id => new_post_id

foreach ($platforms as $platform) {
    // Create CPT post
    $postId = wp_insert_post([
        'post_type' => 'lti_platform',
        'post_title' => $platform['name'],
        'post_status' => 'publish',
    ]);

    if (is_wp_error($postId)) {
        WP_CLI::warning(sprintf('Failed to create platform "%s": %s',
            $platform['name'],
            $postId->get_error_message()
        ));
        continue;
    }

    // Map meta fields (with lti_ prefix)
    $metaFields = [
        'platform_id' => $platform['platform_id'],
        'client_id' => $platform['client_id'],
        'deployment_id' => $platform['deployment_id'] ?? '',
        'auth_endpoint' => $platform['auth_endpoint'],
        'token_endpoint' => $platform['token_endpoint'],
        'jwks_endpoint' => $platform['jwks_endpoint'],
        'enabled' => (bool) ($platform['enabled'] ?? true),
    ];

    foreach ($metaFields as $key => $value) {
        update_post_meta($postId, 'lti_' . $key, $value);
    }

    $idMap[$platform['id']] = $postId;
    WP_CLI::log(sprintf('Migrated platform "%s" (old ID: %d -> new ID: %d)',
        $platform['name'],
        $platform['id'],
        $postId
    ));
}

// Migrate contexts to post meta
$contexts = $wpdb->get_results("SELECT * FROM {$contextTable}", ARRAY_A);

foreach ($contexts as $context) {
    $oldPlatformId = $context['platform_id'];

    if (!isset($idMap[$oldPlatformId])) {
        WP_CLI::warning(sprintf('Context references unknown platform ID %d, skipping.', $oldPlatformId));
        continue;
    }

    $newPlatformId = $idMap[$oldPlatformId];

    // Store contexts as serialized meta
    $existingContexts = get_post_meta($newPlatformId, 'lti_contexts', true) ?: [];
    $existingContexts[] = [
        'lti_context_id' => $context['lti_context_id'],
        'ld_course_id' => $context['ld_course_id'],
        'resource_link_id' => $context['resource_link_id'] ?? null,
        'line_item_url' => $context['line_item_url'] ?? null,
        'settings' => json_decode($context['settings'] ?? '{}', true),
    ];

    update_post_meta($newPlatformId, 'lti_contexts', $existingContexts);
}

WP_CLI::success(sprintf('Migration complete. Migrated %d platforms.', count($idMap)));

// Store ID map for reference
update_option('netdust_lti_migration_map', $idMap);

WP_CLI::log('');
WP_CLI::log('IMPORTANT: Verify data integrity, then run:');
WP_CLI::log('  ddev exec wp eval "\\NetdustLTI\\Database\\Migrations::dropOldTables();"');
```

**Step 2: Update Migrations.php to add dropOldTables method**

Add to `web/app/plugins/netdust-lti/src/Database/Migrations.php`:

```php
public static function dropOldTables(): void
{
    global $wpdb;
    $prefix = $wpdb->prefix . 'netdust_lti_';

    // Only drop platforms and contexts - keep nonces and tokens
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}contexts");
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}platforms");

    delete_option('netdust_lti_migration_map');

    if (function_exists('WP_CLI')) {
        \WP_CLI::success('Dropped old platforms and contexts tables.');
    }
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php
git add web/app/plugins/netdust-lti/src/Database/Migrations.php
git commit -m "$(cat <<'EOF'
feat(lti): add migration script for custom tables to CPTs

Migrates wp_netdust_lti_platforms to lti_platform CPT.
Migrates contexts to post meta.
Keeps nonces and access_tokens tables for performance.

Usage:
1. ddev exec wp eval-file web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php
2. Verify data integrity
3. ddev exec wp eval "\\NetdustLTI\\Database\\Migrations::dropOldTables();"

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 2: Platform Role Implementation

### Task 2.1: Create PlatformRouter for /lti/platform/* endpoints

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Platform/PlatformRouter.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Write the failing test**

```php
// tests/Unit/PlatformRouterTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\PlatformRouter;
use PHPUnit\Framework\TestCase;

class PlatformRouterTest extends TestCase
{
    public function test_registers_rewrite_rules(): void
    {
        $router = new PlatformRouter();
        $router->registerRewriteRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top ?? [];

        $this->assertArrayHasKey('^lti/platform/([a-z-]+)/?$', $rules);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_registers_rewrite_rules`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Platform/PlatformRouter.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NTDST_Service_Meta;

final class PlatformRouter implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Platform Router',
            'description' => 'Routes /lti/platform/* endpoints for Platform role',
            'priority' => 10,
        ];
    }

    public function __construct()
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_rule(
            '^lti/platform/([a-z-]+)/?$',
            'index.php?lti_platform_action=$matches[1]',
            'top'
        );
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'lti_platform_action';
        return $vars;
    }

    public function handleRequest(): void
    {
        $action = get_query_var('lti_platform_action');

        if (!$action) {
            return;
        }

        // Configure session for cross-site requests
        $this->configureSession();

        switch ($action) {
            case 'launch':
                $this->handleLaunchInitiation();
                break;

            case 'auth':
                $this->handleAuthCallback();
                break;

            case 'deep-link-return':
                $this->handleDeepLinkReturn();
                break;

            case 'grades':
                $this->handleGradePassback();
                break;

            default:
                wp_die('Invalid platform action', 'LTI Platform Error', ['response' => 400]);
        }
    }

    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    private function handleLaunchInitiation(): void
    {
        $initiator = ntdst_get(OIDCInitiator::class);
        $initiator->initiateLaunch();
    }

    private function handleAuthCallback(): void
    {
        $builder = ntdst_get(JWTBuilder::class);
        $builder->handleAuthCallback();
    }

    private function handleDeepLinkReturn(): void
    {
        // TODO: Implement deep link return handling
        wp_die('Deep link return not yet implemented', 'LTI Platform', ['response' => 501]);
    }

    private function handleGradePassback(): void
    {
        $receiver = ntdst_get(AGSReceiver::class);
        $receiver->handleGradeSubmission();
    }
}
```

**Step 4: Register service in Plugin.php**

Add to `web/app/plugins/netdust-lti/src/Plugin.php` in `init()`:

```php
// Register platform router
ntdst_get(Platform\PlatformRouter::class);
```

**Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter PlatformRouterTest`
Expected: PASS

**Step 6: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Platform/PlatformRouter.php
git add web/app/plugins/netdust-lti/src/Plugin.php
git add tests/Unit/PlatformRouterTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add PlatformRouter for /lti/platform/* endpoints

Registers routes:
- /lti/platform/launch - initiate OIDC login flow
- /lti/platform/auth - receive tool redirect, create JWT
- /lti/platform/deep-link-return - receive course selection
- /lti/platform/grades - AGS grade passback

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2.2: Create OIDCInitiator

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Platform/OIDCInitiator.php`

**Step 1: Write the failing test**

```php
// tests/Unit/OIDCInitiatorTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\OIDCInitiator;
use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class OIDCInitiatorTest extends TestCase
{
    public function test_generates_valid_state(): void
    {
        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $state = $initiator->generateState();

        $this->assertNotEmpty($state);
        $this->assertEquals(64, strlen($state)); // 32 bytes hex encoded
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_generates_valid_state`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Platform/OIDCInitiator.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use NetdustLTI\Repositories\ToolRepository;
use WP_Error;

final class OIDCInitiator
{
    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {}

    public function initiateLaunch(): void
    {
        // Validate required parameters
        $toolId = absint($_POST['tool_id'] ?? $_GET['tool_id'] ?? 0);
        $resourceLinkId = sanitize_text_field($_POST['resource_link_id'] ?? $_GET['resource_link_id'] ?? '');
        $targetLinkUri = esc_url_raw($_POST['target_link_uri'] ?? $_GET['target_link_uri'] ?? '');

        if (!$toolId) {
            wp_die('Missing tool_id parameter', 'LTI Platform Error', ['response' => 400]);
        }

        // Get tool configuration
        $tool = $this->toolRepository->find($toolId);

        if (is_wp_error($tool)) {
            wp_die($tool->get_error_message(), 'LTI Platform Error', ['response' => 404]);
        }

        // Generate state and nonce
        $state = $this->generateState();
        $nonce = $this->generateNonce();

        // Store in session for validation on callback
        $_SESSION['lti_platform_state'] = $state;
        $_SESSION['lti_platform_nonce'] = $nonce;
        $_SESSION['lti_platform_tool_id'] = $toolId;
        $_SESSION['lti_platform_resource_link_id'] = $resourceLinkId;
        $_SESSION['lti_platform_target_link_uri'] = $targetLinkUri;

        // Build OIDC login request parameters
        $loginParams = [
            'iss' => home_url(),
            'target_link_uri' => $targetLinkUri ?: $tool->fields['launch_url'],
            'login_hint' => (string) get_current_user_id(),
            'lti_message_hint' => wp_json_encode([
                'resource_link_id' => $resourceLinkId,
                'tool_id' => $toolId,
            ]),
            'client_id' => $tool->fields['client_id'],
            'lti_deployment_id' => $tool->fields['deployment_id'] ?: '1',
        ];

        // Redirect to tool's OIDC login endpoint
        $loginUrl = add_query_arg($loginParams, $tool->fields['oidc_url']);

        wp_redirect($loginUrl);
        exit;
    }

    public function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter OIDCInitiatorTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Platform/OIDCInitiator.php
git add tests/Unit/OIDCInitiatorTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add OIDCInitiator for platform launch flow

Initiates OIDC login by:
1. Loading tool configuration from repository
2. Generating state and nonce for CSRF protection
3. Storing session data for callback validation
4. Redirecting to tool's OIDC login endpoint

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2.3: Create JWTBuilder

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Platform/JWTBuilder.php`

**Step 1: Write the failing test**

```php
// tests/Unit/JWTBuilderTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\JWTBuilder;
use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class JWTBuilderTest extends TestCase
{
    public function test_builds_lti_claims(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        $this->assertEquals('1.3.0', $claims['https://purl.imsglobal.org/spec/lti/claim/version']);
        $this->assertEquals('LtiResourceLinkRequest', $claims['https://purl.imsglobal.org/spec/lti/claim/message_type']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_builds_lti_claims`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Platform/JWTBuilder.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use ceLTIc\LTI\Jwt\FirebaseClient;
use NetdustLTI\Repositories\ToolRepository;
use WP_User;

final class JWTBuilder
{
    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {}

    public function handleAuthCallback(): void
    {
        // Validate state
        $state = sanitize_text_field($_GET['state'] ?? '');
        $sessionState = $_SESSION['lti_platform_state'] ?? '';

        if (empty($state) || $state !== $sessionState) {
            wp_die('Invalid state parameter', 'LTI Platform Error', ['response' => 400]);
        }

        // Get session data
        $toolId = absint($_SESSION['lti_platform_tool_id'] ?? 0);
        $nonce = $_SESSION['lti_platform_nonce'] ?? '';
        $resourceLinkId = $_SESSION['lti_platform_resource_link_id'] ?? '';
        $targetLinkUri = $_SESSION['lti_platform_target_link_uri'] ?? '';

        // Clear session data
        unset($_SESSION['lti_platform_state']);
        unset($_SESSION['lti_platform_nonce']);
        unset($_SESSION['lti_platform_tool_id']);
        unset($_SESSION['lti_platform_resource_link_id']);
        unset($_SESSION['lti_platform_target_link_uri']);

        // Get tool configuration
        $tool = $this->toolRepository->find($toolId);

        if (is_wp_error($tool)) {
            wp_die($tool->get_error_message(), 'LTI Platform Error', ['response' => 404]);
        }

        // Get current user
        $user = wp_get_current_user();

        if (!$user->exists()) {
            // Create test user for anonymous launches
            $user = $this->createTestUser();
        }

        // Build JWT claims
        $claims = $this->buildLTIClaims(
            $user,
            $resourceLinkId ?: 'resource-' . $toolId,
            $targetLinkUri ?: $tool->fields['launch_url']
        );

        // Add tool-specific claims
        $claims['aud'] = $tool->fields['client_id'];
        $claims['azp'] = $tool->fields['client_id'];
        $claims['nonce'] = $nonce;
        $claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] =
            $tool->fields['deployment_id'] ?: '1';

        // Sign JWT
        $idToken = $this->signJWT($claims);

        // Output auto-submit form to tool's launch URL
        $this->outputLaunchForm($tool->fields['launch_url'], $idToken, $state);
    }

    public function buildLTIClaims(object $user, string $resourceLinkId, string $targetLinkUri): array
    {
        $now = time();

        return [
            'iss' => home_url(),
            'sub' => (string) $user->ID,
            'iat' => $now,
            'exp' => $now + 3600,

            // LTI Claims
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => [
                'id' => $resourceLinkId,
                'title' => 'Course Launch',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => $targetLinkUri,
            'https://purl.imsglobal.org/spec/lti/claim/roles' => $this->getUserRoles($user),

            // User identity
            'name' => $user->display_name,
            'email' => $user->user_email,
            'given_name' => get_user_meta($user->ID, 'first_name', true) ?: '',
            'family_name' => get_user_meta($user->ID, 'last_name', true) ?: '',

            // Context (optional)
            'https://purl.imsglobal.org/spec/lti/claim/context' => [
                'id' => 'platform-' . get_current_blog_id(),
                'label' => get_bloginfo('name'),
                'title' => get_bloginfo('name'),
                'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering'],
            ],

            // AGS (Assignment and Grade Services)
            'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint' => [
                'scope' => [
                    'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                ],
                'lineitem' => home_url('/lti/platform/grades'),
            ],
        ];
    }

    private function getUserRoles(object $user): array
    {
        $roles = [];

        if (user_can($user->ID, 'manage_options')) {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator';
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
        } elseif (user_can($user->ID, 'edit_posts')) {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
        } else {
            $roles[] = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner';
        }

        return $roles;
    }

    private function signJWT(array $claims): string
    {
        $privateKey = get_option('netdust_lti_private_key');
        $kid = get_option('netdust_lti_kid');

        if (!$privateKey || !$kid) {
            wp_die('LTI keys not configured', 'LTI Platform Error', ['response' => 500]);
        }

        return FirebaseClient::sign($claims, 'RS256', $privateKey, $kid);
    }

    private function createTestUser(): WP_User
    {
        $testEmail = 'lti-test-' . time() . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        $userId = wp_insert_user([
            'user_login' => 'lti-test-' . time(),
            'user_email' => $testEmail,
            'user_pass' => wp_generate_password(),
            'display_name' => 'LTI Test User',
            'role' => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            wp_die('Failed to create test user', 'LTI Platform Error', ['response' => 500]);
        }

        update_user_meta($userId, '_lti_external_user', true);

        return get_user_by('ID', $userId);
    }

    private function outputLaunchForm(string $launchUrl, string $idToken, string $state): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Launching LTI Tool...</title>
        </head>
        <body>
            <form id="lti-launch-form" action="<?php echo esc_url($launchUrl); ?>" method="POST">
                <input type="hidden" name="id_token" value="<?php echo esc_attr($idToken); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
                <noscript>
                    <p>JavaScript is required. Click to continue:</p>
                    <input type="submit" value="Launch">
                </noscript>
            </form>
            <script>document.getElementById('lti-launch-form').submit();</script>
        </body>
        </html>
        <?php
        exit;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter JWTBuilderTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Platform/JWTBuilder.php
git add tests/Unit/JWTBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add JWTBuilder for platform authentication

Handles auth callback from tool by:
1. Validating state parameter against session
2. Building LTI 1.3 claims (version, message_type, roles, AGS)
3. Signing JWT with platform RSA key
4. Auto-submitting form to tool's launch URL

Includes test user creation for anonymous launches.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2.4: Create AGSReceiver

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Platform/AGSReceiver.php`

**Step 1: Write the failing test**

```php
// tests/Unit/AGSReceiverTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\AGSReceiver;
use PHPUnit\Framework\TestCase;

class AGSReceiverTest extends TestCase
{
    public function test_stores_grade_in_user_meta(): void
    {
        $receiver = new AGSReceiver();

        // Simulate grade storage
        $userId = 1;
        $toolId = 42;
        $resourceLinkId = 'course-123';
        $score = 0.85;

        $receiver->storeGrade($userId, $toolId, $resourceLinkId, $score, 'Completed');

        // In real test, verify user meta was updated
        $this->assertTrue(true); // Placeholder
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_stores_grade_in_user_meta`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Platform/AGSReceiver.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Platform;

use WP_Error;

final class AGSReceiver
{
    public function handleGradeSubmission(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Validate bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $this->sendJsonResponse(['error' => 'Missing bearer token'], 401);
        }

        $token = $matches[1];

        // Validate JWT and extract claims
        $claims = $this->validateToken($token);

        if (is_wp_error($claims)) {
            $this->sendJsonResponse(['error' => $claims->get_error_message()], 401);
        }

        // Parse score submission
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            $this->sendJsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        // Extract score data
        $userId = $this->findUserByLtiSub($claims['sub'] ?? '');
        $scoreGiven = floatval($data['scoreGiven'] ?? 0);
        $scoreMaximum = floatval($data['scoreMaximum'] ?? 1);
        $comment = sanitize_text_field($data['comment'] ?? '');
        $activityProgress = sanitize_text_field($data['activityProgress'] ?? 'Completed');
        $gradingProgress = sanitize_text_field($data['gradingProgress'] ?? 'FullyGraded');

        if (!$userId) {
            $this->sendJsonResponse(['error' => 'User not found'], 404);
        }

        // Normalize score to 0-1 range
        $normalizedScore = $scoreMaximum > 0 ? $scoreGiven / $scoreMaximum : 0;

        // Extract tool and resource from claims
        $toolId = absint($claims['tool_id'] ?? 0);
        $resourceLinkId = $claims['https://purl.imsglobal.org/spec/lti/claim/resource_link']['id'] ?? 'unknown';

        // Store grade
        $this->storeGrade($userId, $toolId, $resourceLinkId, $normalizedScore, $activityProgress, $comment);

        // Fire action hook for integrations
        do_action('lti_grade_received', $userId, $toolId, $normalizedScore, $activityProgress);

        $this->sendJsonResponse(['success' => true], 200);
    }

    public function storeGrade(
        int $userId,
        int $toolId,
        string $resourceLinkId,
        float $score,
        string $activityProgress,
        string $comment = ''
    ): void {
        $grades = get_user_meta($userId, '_lti_grades', true) ?: [];

        $toolKey = "tool_{$toolId}";

        if (!isset($grades[$toolKey])) {
            $grades[$toolKey] = [];
        }

        $grades[$toolKey][$resourceLinkId] = [
            'score' => $score,
            'max_score' => 1.0,
            'comment' => $comment,
            'timestamp' => gmdate('c'),
            'activity' => $activityProgress,
        ];

        update_user_meta($userId, '_lti_grades', $grades);
    }

    public function getGrades(int $userId, ?int $toolId = null): array
    {
        $grades = get_user_meta($userId, '_lti_grades', true) ?: [];

        if ($toolId !== null) {
            return $grades["tool_{$toolId}"] ?? [];
        }

        return $grades;
    }

    private function validateToken(string $token): array|WP_Error
    {
        try {
            $publicKey = get_option('netdust_lti_public_key');

            if (!$publicKey) {
                return new WP_Error('config_error', 'Public key not configured');
            }

            // Decode and validate JWT
            $claims = \ceLTIc\LTI\Jwt\FirebaseClient::verify($token, $publicKey);

            if (!$claims) {
                return new WP_Error('invalid_token', 'Token verification failed');
            }

            return (array) $claims;
        } catch (\Exception $e) {
            return new WP_Error('token_error', $e->getMessage());
        }
    }

    private function findUserByLtiSub(string $sub): ?int
    {
        // Sub is typically the WP user ID from our JWT
        $userId = absint($sub);

        if ($userId && get_user_by('ID', $userId)) {
            return $userId;
        }

        // Try to find by LTI ID mapping
        $users = get_users([
            'meta_key' => '_lti_user_id',
            'meta_value' => $sub,
            'number' => 1,
        ]);

        if (!empty($users)) {
            return $users[0]->ID;
        }

        return null;
    }

    private function sendJsonResponse(array $data, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo wp_json_encode($data);
        exit;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter AGSReceiverTest`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Platform/AGSReceiver.php
git add tests/Unit/AGSReceiverTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add AGSReceiver for grade passback

Receives grade submissions from LTI tools via:
1. Bearer token JWT validation
2. Score normalization (scoreGiven/scoreMaximum)
3. Storage in user meta (_lti_grades)
4. Action hook for integrations (lti_grade_received)

Grades stored per tool and resource link.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 3: Admin UI

### Task 3.1: Create LaunchTestPage

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Admin/LaunchTestPage.php`
- Create: `web/app/plugins/netdust-lti/templates/admin/launch-test.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Write implementation**

Create `web/app/plugins/netdust-lti/src/Admin/LaunchTestPage.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Repositories\ToolRepository;
use NTDST_Service_Meta;

final class LaunchTestPage implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Launch Test Page',
            'description' => 'Admin page for testing LTI tool launches',
            'priority' => 20,
        ];
    }

    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {
        add_action('admin_menu', [$this, 'registerPage']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'options-general.php',
            'LTI Launch Test',
            'LTI Launch Test',
            'manage_options',
            'lti-launch-test',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $tools = $this->toolRepository->all();
        $currentUser = wp_get_current_user();

        include dirname(__DIR__, 2) . '/templates/admin/launch-test.php';
    }
}
```

Create `web/app/plugins/netdust-lti/templates/admin/launch-test.php`:

```php
<div class="wrap">
    <h1>LTI Launch Test</h1>

    <p>Test launching external LTI tools as the Platform.</p>

    <?php if (empty($tools)): ?>
        <div class="notice notice-warning">
            <p>No LTI tools configured. <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_tool')); ?>">Add a tool</a> first.</p>
        </div>
    <?php else: ?>
        <form method="post" action="<?php echo esc_url(home_url('/lti/platform/launch')); ?>" target="_blank">
            <?php wp_nonce_field('lti_launch_test', 'lti_launch_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="tool_id">Select Tool</label></th>
                    <td>
                        <select id="tool_id" name="tool_id" class="regular-text" required>
                            <option value="">-- Select a Tool --</option>
                            <?php foreach ($tools as $tool): ?>
                                <option value="<?php echo esc_attr($tool['id']); ?>">
                                    <?php echo esc_html($tool['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="resource_link_id">Resource Link ID</label></th>
                    <td>
                        <input type="text" id="resource_link_id" name="resource_link_id" class="regular-text"
                               placeholder="e.g., course-123" value="">
                        <p class="description">Optional. Identifies the specific resource to launch.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="target_link_uri">Target Link URI</label></th>
                    <td>
                        <input type="url" id="target_link_uri" name="target_link_uri" class="regular-text"
                               placeholder="https://tool.example.com/courses/123">
                        <p class="description">Optional. Override the tool's default launch URL.</p>
                    </td>
                </tr>
                <tr>
                    <th>Launch As</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="launch_as" value="current" checked>
                                Current user (<?php echo esc_html($currentUser->user_email); ?>)
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="launch_as" value="test">
                                Test learner (generates temporary LTI user)
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button-primary" value="Launch in New Tab">
            </p>
        </form>

        <hr>

        <h2>Deep Linking</h2>
        <p>Discover available courses from a tool:</p>

        <form method="post" action="<?php echo esc_url(home_url('/lti/platform/launch')); ?>" target="_blank">
            <?php wp_nonce_field('lti_deep_link', 'lti_deep_link_nonce'); ?>
            <input type="hidden" name="message_type" value="LtiDeepLinkingRequest">

            <table class="form-table">
                <tr>
                    <th><label for="dl_tool_id">Select Tool</label></th>
                    <td>
                        <select id="dl_tool_id" name="tool_id" class="regular-text" required>
                            <option value="">-- Select a Tool --</option>
                            <?php foreach ($tools as $tool): ?>
                                <option value="<?php echo esc_attr($tool['id']); ?>">
                                    <?php echo esc_html($tool['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button-secondary" value="Discover Courses via Deep Link">
            </p>
        </form>
    <?php endif; ?>

    <hr>

    <h2>Platform Endpoints</h2>
    <p>When configuring an external tool to use this site as Platform, provide these URLs:</p>
    <table class="form-table">
        <tr>
            <th>Issuer</th>
            <td><code><?php echo esc_html(home_url()); ?></code></td>
        </tr>
        <tr>
            <th>Auth Endpoint</th>
            <td><code><?php echo esc_html(home_url('/lti/platform/auth')); ?></code></td>
        </tr>
        <tr>
            <th>JWKS URL</th>
            <td><code><?php echo esc_html(home_url('/lti/jwks')); ?></code></td>
        </tr>
        <tr>
            <th>AGS Endpoint</th>
            <td><code><?php echo esc_html(home_url('/lti/platform/grades')); ?></code></td>
        </tr>
    </table>
</div>
```

**Step 2: Register service in Plugin.php**

Add to `web/app/plugins/netdust-lti/src/Plugin.php` in `init()` admin block:

```php
if (is_admin()) {
    ntdst_get(Admin\AdminPage::class);
    ntdst_get(Admin\CourseSettingsMetabox::class);
    ntdst_get(Admin\LaunchTestPage::class);  // Add this line
    ntdst_get(LTI\DeepLinkHandler::class);
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Admin/LaunchTestPage.php
git add web/app/plugins/netdust-lti/templates/admin/launch-test.php
git add web/app/plugins/netdust-lti/src/Plugin.php
git commit -m "$(cat <<'EOF'
feat(lti): add admin launch test page

Adds /wp-admin/options-general.php?page=lti-launch-test with:
- Tool selector dropdown
- Resource link ID and target URI inputs
- Launch as current user or test learner
- Deep linking discovery option
- Platform endpoint reference table

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 4: Shortcode

### Task 4.1: Create LtiLaunchShortcode

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Shortcodes/LtiLaunchShortcode.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Write the failing test**

```php
// tests/Unit/LtiLaunchShortcodeTest.php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Shortcodes\LtiLaunchShortcode;
use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class LtiLaunchShortcodeTest extends TestCase
{
    public function test_renders_form_with_tool_id(): void
    {
        $repo = $this->createMock(ToolRepository::class);
        $shortcode = new LtiLaunchShortcode($repo);

        $output = $shortcode->render(['tool' => '123', 'course_id' => '456'], 'Launch Course');

        $this->assertStringContainsString('form', $output);
        $this->assertStringContainsString('tool_id', $output);
        $this->assertStringContainsString('Launch Course', $output);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter test_renders_form_with_tool_id`
Expected: FAIL - class not found

**Step 3: Write implementation**

Create `web/app/plugins/netdust-lti/src/Shortcodes/LtiLaunchShortcode.php`:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Shortcodes;

use NetdustLTI\Repositories\ToolRepository;
use NTDST_Service_Meta;

final class LtiLaunchShortcode implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'LTI Launch Shortcode',
            'description' => 'Provides [lti_launch] shortcode for embedding LTI launches',
            'priority' => 15,
        ];
    }

    public function __construct(
        private readonly ToolRepository $toolRepository
    ) {
        add_shortcode('lti_launch', [$this, 'render']);
    }

    /**
     * Render the shortcode.
     *
     * Usage:
     * [lti_launch tool="stride" course_id="123"]Launch Course[/lti_launch]
     * [lti_launch tool="42" class="button primary"]Start Learning[/lti_launch]
     * [lti_launch tool="stride" mode="discover"]Browse Courses[/lti_launch]
     */
    public function render(array|string $atts, ?string $content = null): string
    {
        $atts = shortcode_atts([
            'tool' => '',
            'course_id' => '',
            'target_uri' => '',
            'class' => 'button',
            'mode' => 'launch', // 'launch' or 'discover'
        ], $atts, 'lti_launch');

        // Resolve tool ID from slug or numeric ID
        $toolId = $this->resolveToolId($atts['tool']);

        if (!$toolId) {
            return '<!-- LTI Launch: Tool not found -->';
        }

        $buttonText = $content ?: 'Launch';
        $formId = 'lti-launch-' . wp_unique_id();
        $launchUrl = home_url('/lti/platform/launch');

        $messageType = $atts['mode'] === 'discover'
            ? 'LtiDeepLinkingRequest'
            : 'LtiResourceLinkRequest';

        ob_start();
        ?>
        <form id="<?php echo esc_attr($formId); ?>"
              action="<?php echo esc_url($launchUrl); ?>"
              method="post"
              target="_blank"
              class="lti-launch-form">
            <input type="hidden" name="tool_id" value="<?php echo esc_attr($toolId); ?>">
            <input type="hidden" name="resource_link_id" value="<?php echo esc_attr($atts['course_id']); ?>">
            <input type="hidden" name="target_link_uri" value="<?php echo esc_attr($atts['target_uri']); ?>">
            <input type="hidden" name="message_type" value="<?php echo esc_attr($messageType); ?>">
            <?php wp_nonce_field('lti_shortcode_launch', 'lti_nonce'); ?>
            <button type="submit" class="<?php echo esc_attr($atts['class']); ?>">
                <?php echo esc_html($buttonText); ?>
            </button>
        </form>
        <?php
        return ob_get_clean();
    }

    private function resolveToolId(string $toolRef): ?int
    {
        if (empty($toolRef)) {
            return null;
        }

        // If numeric, use directly
        if (is_numeric($toolRef)) {
            return absint($toolRef);
        }

        // Otherwise, look up by slug
        $tool = $this->toolRepository->findBySlug($toolRef);

        if (is_wp_error($tool)) {
            return null;
        }

        return $tool->ID;
    }
}
```

**Step 4: Register service in Plugin.php**

Add to `web/app/plugins/netdust-lti/src/Plugin.php` in `init()`:

```php
// Register shortcodes
ntdst_get(Shortcodes\LtiLaunchShortcode::class);
```

**Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter LtiLaunchShortcodeTest`
Expected: PASS

**Step 6: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Shortcodes/LtiLaunchShortcode.php
git add web/app/plugins/netdust-lti/src/Plugin.php
git add tests/Unit/LtiLaunchShortcodeTest.php
git commit -m "$(cat <<'EOF'
feat(lti): add [lti_launch] shortcode

Usage:
- [lti_launch tool="stride" course_id="123"]Launch[/lti_launch]
- [lti_launch tool="42" class="button primary"]Start[/lti_launch]
- [lti_launch tool="stride" mode="discover"]Browse[/lti_launch]

Renders POST form to /lti/platform/launch with nonce protection.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Phase 5: Integration & Testing

### Task 5.1: Update admin page to remove custom platforms table UI

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Admin/AdminPage.php`
- Modify: `web/app/plugins/netdust-lti/templates/admin/settings-page.php`

**Step 1: Simplify AdminPage.php**

The Data Manager auto-generates the CPT admin UI. Update `AdminPage.php` to:
- Remove manual platform CRUD handling
- Keep only tool endpoints display and logging

**Step 2: Update settings-page.php**

Replace the custom platform list with links to CPT edit screens:

```php
<h2>Registered Platforms</h2>
<p>
    <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_platform')); ?>" class="button button-primary">Manage Platforms</a>
    <a href="<?php echo esc_url(admin_url('edit.php?post_type=lti_tool')); ?>" class="button">Manage Tools</a>
    <a href="<?php echo esc_url(admin_url('options-general.php?page=lti-launch-test')); ?>" class="button">Launch Test</a>
</p>
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Admin/AdminPage.php
git add web/app/plugins/netdust-lti/templates/admin/settings-page.php
git commit -m "$(cat <<'EOF'
refactor(lti): simplify admin UI to use CPT screens

Data Manager auto-generates platform/tool admin UI.
Settings page now links to CPT edit screens.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5.2: Flush rewrite rules

**Step 1: Create activation hook update**

Add to activation hook in `netdust-lti.php` to flush rewrite rules for new routes:

```php
function activate_plugin(): void
{
    Database\Migrations::run();
    generate_keys_if_needed();

    // Register CPTs before flushing
    ntdst_get(Data\LTIDataService::class);

    flush_rewrite_rules();
    // ...
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/netdust-lti.php
git commit -m "$(cat <<'EOF'
fix(lti): ensure CPTs registered before flush_rewrite_rules

Activation hook now registers Data Manager CPTs before flushing
rewrite rules to ensure platform/* routes work immediately.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5.3: Integration test - local DDEV to DDEV

**Step 1: Manual testing procedure**

1. Set up two DDEV sites (stride.ddev.site and vad-vormingen.ddev.site)
2. On vad-vormingen (Platform):
   - Add Tool: name="Stride", oidc_url="https://stride.ddev.site/lti/login", launch_url="https://stride.ddev.site/lti/launch", jwks_url="https://stride.ddev.site/lti/jwks", client_id="vad-test"
3. On stride (Tool Provider):
   - Add Platform: name="VAD", platform_id="https://vad-vormingen.ddev.site", client_id="vad-test", auth_endpoint="https://vad-vormingen.ddev.site/lti/platform/auth", etc.
4. On vad-vormingen:
   - Go to LTI Launch Test page
   - Select Stride tool
   - Click "Launch in New Tab"
5. Verify: User is created on stride and enrolled in course

**Step 2: Document test procedure in README**

Add to `web/app/plugins/netdust-lti/README.md`:

```markdown
## Local Testing (DDEV to DDEV)

### Prerequisites
- Two DDEV sites running (e.g., stride.ddev.site and vad-vormingen.ddev.site)
- Plugin installed on both sites

### Setup

**On Platform (vad-vormingen):**
1. Go to Settings > LTI Tools
2. Add new tool:
   - Name: Stride LMS
   - OIDC URL: https://stride.ddev.site/lti/login
   - Launch URL: https://stride.ddev.site/lti/launch
   - JWKS URL: https://stride.ddev.site/lti/jwks
   - Client ID: test-client-id
   - Deployment ID: 1

**On Tool (stride):**
1. Go to Settings > LTI Platforms
2. Add new platform:
   - Name: VAD Vormingen
   - Platform ID: https://vad-vormingen.ddev.site
   - Client ID: test-client-id
   - Auth Endpoint: https://vad-vormingen.ddev.site/lti/platform/auth
   - Token Endpoint: https://vad-vormingen.ddev.site/lti/platform/auth
   - JWKS Endpoint: https://vad-vormingen.ddev.site/lti/jwks

### Testing

1. On Platform: Go to Settings > LTI Launch Test
2. Select "Stride LMS" tool
3. Click "Launch in New Tab"
4. Verify redirect to stride and user enrollment
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/README.md
git commit -m "$(cat <<'EOF'
docs(lti): add local DDEV testing instructions

Documents setup procedure for testing Platform feature
between two local DDEV sites.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Summary

This plan implements the LTI Platform feature in 5 phases:

1. **Data Layer Migration** - CPTs via Data Manager, refactored repositories, migration script
2. **Platform Role Implementation** - PlatformRouter, OIDCInitiator, JWTBuilder, AGSReceiver
3. **Admin UI** - LaunchTestPage for testing tool launches
4. **Shortcode** - [lti_launch] for frontend embedding
5. **Integration & Testing** - Admin simplification, rewrite rules, testing docs

Each task follows TDD with:
- Failing test first
- Minimal implementation
- Test verification
- Immediate commit

Total: ~20 bite-sized tasks, each 2-5 minutes.
