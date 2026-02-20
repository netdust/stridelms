# Netdust LTI Plugin Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a WordPress plugin for LTI 1.3 integration with LearnDash and TinCanny, enabling external LMS platforms to embed courses with automatic grade passback.

**Architecture:** Hybrid approach — use `celtic/lti` library for protocol handling (JWT, OIDC, AGS), own the business logic and persistence via NTDST Core patterns.

**Tech Stack:** PHP 8.1+, celtic/lti v5.x, WordPress 6.x, NTDST Core DI, LearnDash, TinCanny

**Execution Mode:** RIGOROUS — per-task mini-plans, TDD where applicable, self-review before marking done.

---

## Phase 1: Core Launch (MVP)

### Task 1.1: Plugin Scaffold

**Files:**
- Create: `web/app/plugins/netdust-lti/netdust-lti.php`
- Create: `web/app/plugins/netdust-lti/composer.json`
- Create: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Create plugin directory and main file**

```php
<?php
/**
 * Plugin Name: Netdust LTI
 * Plugin URI: https://netdust.be
 * Description: LTI 1.3 Tool Provider for LearnDash integration
 * Version: 1.0.0
 * Author: Netdust
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

declare(strict_types=1);

namespace NetdustLTI;

defined('ABSPATH') || exit;

// Check NTDST Core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Netdust LTI</strong> requires NTDST Core to be active.</p></div>';
    });
    return;
}

// Autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Bootstrap
add_action('plugins_loaded', function() {
    ntdst_get(Plugin::class);
}, 20);
```

**Step 2: Create composer.json**

```json
{
    "name": "netdust/netdust-lti",
    "description": "LTI 1.3 Tool Provider for LearnDash",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.1",
        "celtic/lti": "^5.3"
    },
    "autoload": {
        "psr-4": {
            "NetdustLTI\\": "src/"
        }
    }
}
```

**Step 3: Create Plugin bootstrap class**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI;

use NTDST_Service_Meta;

final class Plugin implements NTDST_Service_Meta
{
    public const VERSION = '1.0.0';
    public const SLUG = 'netdust-lti';

    public function __construct()
    {
        $this->init();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Netdust LTI',
            'description' => 'LTI 1.3 Tool Provider',
            'priority' => 10,
        ];
    }

    private function init(): void
    {
        // Register activation hook
        register_activation_hook(
            dirname(__DIR__) . '/netdust-lti.php',
            [$this, 'activate']
        );

        // Register deactivation hook
        register_deactivation_hook(
            dirname(__DIR__) . '/netdust-lti.php',
            [$this, 'deactivate']
        );
    }

    public function activate(): void
    {
        // Will be implemented in Task 1.2
        flush_rewrite_rules();
    }

    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function pluginPath(): string
    {
        return dirname(__DIR__);
    }

    public static function pluginUrl(): string
    {
        return plugin_dir_url(dirname(__DIR__) . '/netdust-lti.php');
    }
}
```

**Step 4: Install dependencies and verify**

Run:
```bash
cd web/app/plugins/netdust-lti && composer install
```

**Step 5: Activate and verify**

Run:
```bash
ddev exec wp plugin activate netdust-lti
ddev exec wp eval "echo class_exists('\NetdustLTI\Plugin') ? 'OK' : 'FAIL';"
```
Expected: OK

**Step 6: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): scaffold netdust-lti plugin with composer"
```

---

### Task 1.2: Database Migrations

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Database/Migrations.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Create Migrations class**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Database;

final class Migrations
{
    private const VERSION = '1.0.0';
    private const OPTION_KEY = 'netdust_lti_db_version';

    public static function run(): void
    {
        $currentVersion = get_option(self::OPTION_KEY, '0.0.0');

        if (version_compare($currentVersion, self::VERSION, '<')) {
            self::createTables();
            update_option(self::OPTION_KEY, self::VERSION);
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'netdust_lti_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Platforms table
        $sql = "CREATE TABLE {$prefix}platforms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            platform_id VARCHAR(255) NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            deployment_id VARCHAR(255) DEFAULT NULL,
            auth_endpoint VARCHAR(512) NOT NULL,
            token_endpoint VARCHAR(512) NOT NULL,
            jwks_endpoint VARCHAR(512) NOT NULL,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY platform_client (platform_id, client_id)
        ) {$charset};";
        dbDelta($sql);

        // Contexts table
        $sql = "CREATE TABLE {$prefix}contexts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            lti_context_id VARCHAR(255) NOT NULL,
            ld_course_id BIGINT UNSIGNED NOT NULL,
            resource_link_id VARCHAR(255) DEFAULT NULL,
            line_item_url VARCHAR(512) DEFAULT NULL,
            settings JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY context_resource (platform_id, lti_context_id, resource_link_id),
            KEY ld_course (ld_course_id)
        ) {$charset};";
        dbDelta($sql);

        // Nonces table
        $sql = "CREATE TABLE {$prefix}nonces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY platform_nonce (platform_id, nonce),
            KEY expires (expires_at)
        ) {$charset};";
        dbDelta($sql);

        // Access tokens table (for AGS)
        $sql = "CREATE TABLE {$prefix}access_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            token TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            scopes TEXT,
            created_at DATETIME NOT NULL,
            UNIQUE KEY platform_token (platform_id)
        ) {$charset};";
        dbDelta($sql);
    }

    public static function dropTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'netdust_lti_';

        $wpdb->query("DROP TABLE IF EXISTS {$prefix}access_tokens");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}nonces");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}contexts");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}platforms");

        delete_option(self::OPTION_KEY);
    }
}
```

**Step 2: Update Plugin.php to run migrations**

In `Plugin::activate()`, add:

```php
public function activate(): void
{
    Database\Migrations::run();
    $this->generateKeysIfNeeded();
    flush_rewrite_rules();
}

private function generateKeysIfNeeded(): void
{
    if (get_option('netdust_lti_private_key')) {
        return;
    }

    $config = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $keyPair = openssl_pkey_new($config);
    openssl_pkey_export($keyPair, $privateKey);
    $keyDetails = openssl_pkey_get_details($keyPair);

    update_option('netdust_lti_private_key', $privateKey);
    update_option('netdust_lti_public_key', $keyDetails['key']);
    update_option('netdust_lti_kid', 'netdust-lti-' . time());
}
```

**Step 3: Verify tables are created**

Run:
```bash
ddev exec wp plugin deactivate netdust-lti && ddev exec wp plugin activate netdust-lti
ddev exec wp db query "SHOW TABLES LIKE '%netdust_lti%';"
```
Expected: 4 tables listed

**Step 4: Verify keys are generated**

Run:
```bash
ddev exec wp option get netdust_lti_kid
```
Expected: `netdust-lti-<timestamp>`

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add database migrations and key generation"
```

---

### Task 1.3: Domain Value Objects

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Domain/Platform.php`
- Create: `web/app/plugins/netdust-lti/src/Domain/LtiClaims.php`

**Step 1: Create Platform value object**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Domain;

final readonly class Platform
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $platformId,
        public string $clientId,
        public ?string $deploymentId,
        public string $authEndpoint,
        public string $tokenEndpoint,
        public string $jwksEndpoint,
        public bool $enabled,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: $row['name'],
            platformId: $row['platform_id'],
            clientId: $row['client_id'],
            deploymentId: $row['deployment_id'],
            authEndpoint: $row['auth_endpoint'],
            tokenEndpoint: $row['token_endpoint'],
            jwksEndpoint: $row['jwks_endpoint'],
            enabled: (bool) $row['enabled'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'platform_id' => $this->platformId,
            'client_id' => $this->clientId,
            'deployment_id' => $this->deploymentId,
            'auth_endpoint' => $this->authEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_endpoint' => $this->jwksEndpoint,
            'enabled' => $this->enabled ? 1 : 0,
        ];
    }
}
```

**Step 2: Create LtiClaims value object**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Domain;

final readonly class LtiClaims
{
    public function __construct(
        public string $sub,
        public ?string $email,
        public ?string $name,
        public ?string $givenName,
        public ?string $familyName,
        public ?string $contextId,
        public ?string $contextTitle,
        public ?string $resourceLinkId,
        public ?string $resourceLinkTitle,
        public array $roles,
        public array $custom,
        public ?string $lineItemUrl,
        public ?string $lineItemsUrl,
        public ?string $scoresUrl,
    ) {}

    public static function fromLtiTool(\ceLTIc\LTI\Tool $tool): self
    {
        $userResult = $tool->userResult;
        $context = $tool->context;
        $resourceLink = $tool->resourceLink;

        // Extract AGS endpoints from message parameters
        $agsEndpoint = $tool->messageParameters['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] ?? [];

        return new self(
            sub: $userResult->ltiUserId ?? '',
            email: $userResult->email,
            name: $userResult->fullname,
            givenName: $userResult->firstname,
            familyName: $userResult->lastname,
            contextId: $context?->ltiContextId,
            contextTitle: $context?->title,
            resourceLinkId: $resourceLink?->ltiResourceLinkId,
            resourceLinkTitle: $resourceLink?->title,
            roles: $userResult->roles ?? [],
            custom: $resourceLink?->getSetting('custom') ?? [],
            lineItemUrl: $agsEndpoint['lineitem'] ?? null,
            lineItemsUrl: $agsEndpoint['lineitems'] ?? null,
            scoresUrl: $agsEndpoint['scores'] ?? null,
        );
    }

    public function isInstructor(): bool
    {
        foreach ($this->roles as $role) {
            if (str_contains($role, 'Instructor') || str_contains($role, 'Administrator')) {
                return true;
            }
        }
        return false;
    }

    public function isLearner(): bool
    {
        foreach ($this->roles as $role) {
            if (str_contains($role, 'Learner') || str_contains($role, 'Student')) {
                return true;
            }
        }
        return false;
    }

    public function getCourseId(): ?int
    {
        return isset($this->custom['ld_course_id']) ? (int) $this->custom['ld_course_id'] : null;
    }
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Domain
git commit -m "feat(lti): add Platform and LtiClaims domain objects"
```

---

### Task 1.4: Platform Repository

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Repositories/PlatformRepository.php`

**Step 1: Create PlatformRepository**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use NetdustLTI\Domain\Platform;
use WP_Error;

final class PlatformRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_platforms';
    }

    public function find(int $id): Platform|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return Platform::fromRow($row);
    }

    public function findByIssuerAndClient(string $platformId, string $clientId): Platform|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE platform_id = %s AND client_id = %s",
                $platformId,
                $clientId
            ),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return Platform::fromRow($row);
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY name",
            ARRAY_A
        );

        return array_map(fn($row) => Platform::fromRow($row), $rows);
    }

    public function allEnabled(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY name",
            ARRAY_A
        );

        return array_map(fn($row) => Platform::fromRow($row), $rows);
    }

    public function create(Platform $platform): int|WP_Error
    {
        $data = $platform->toArray();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->insert($this->table, $data);

        if ($result === false) {
            return new WP_Error('insert_failed', $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, Platform $platform): bool|WP_Error
    {
        $data = $platform->toArray();
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update($this->table, $data, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('update_failed', $this->wpdb->last_error);
        }

        return true;
    }

    public function delete(int $id): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('delete_failed', $this->wpdb->last_error);
        }

        return true;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Repositories
git commit -m "feat(lti): add PlatformRepository"
```

---

### Task 1.5: Context and Nonce Repositories

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Repositories/ContextRepository.php`
- Create: `web/app/plugins/netdust-lti/src/Repositories/NonceRepository.php`

**Step 1: Create ContextRepository**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use WP_Error;

final class ContextRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_contexts';
    }

    public function find(int $id): array|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Context not found');
        }

        $row['settings'] = json_decode($row['settings'] ?? '{}', true);
        return $row;
    }

    public function findByLtiContext(int $platformId, string $ltiContextId, ?string $resourceLinkId = null): array|null
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE platform_id = %d AND lti_context_id = %s",
            $platformId,
            $ltiContextId
        );

        if ($resourceLinkId) {
            $sql .= $this->wpdb->prepare(" AND resource_link_id = %s", $resourceLinkId);
        }

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['settings'] = json_decode($row['settings'] ?? '{}', true);
        return $row;
    }

    public function findByCourseId(int $courseId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE ld_course_id = %d",
                $courseId
            ),
            ARRAY_A
        );

        return array_map(function($row) {
            $row['settings'] = json_decode($row['settings'] ?? '{}', true);
            return $row;
        }, $rows);
    }

    public function create(array $data): int|WP_Error
    {
        $insert = [
            'platform_id' => $data['platform_id'],
            'lti_context_id' => $data['lti_context_id'],
            'ld_course_id' => $data['ld_course_id'],
            'resource_link_id' => $data['resource_link_id'] ?? null,
            'line_item_url' => $data['line_item_url'] ?? null,
            'settings' => json_encode($data['settings'] ?? []),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $this->wpdb->insert($this->table, $insert);

        if ($result === false) {
            return new WP_Error('insert_failed', $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): bool|WP_Error
    {
        $update = [
            'line_item_url' => $data['line_item_url'] ?? null,
            'settings' => json_encode($data['settings'] ?? []),
            'updated_at' => current_time('mysql'),
        ];

        $result = $this->wpdb->update($this->table, $update, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('update_failed', $this->wpdb->last_error);
        }

        return true;
    }
}
```

**Step 2: Create NonceRepository**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

final class NonceRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_nonces';
    }

    public function exists(int $platformId, string $nonce): bool
    {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT 1 FROM {$this->table} WHERE platform_id = %d AND nonce = %s AND expires_at > NOW()",
                $platformId,
                $nonce
            )
        );

        return $result !== null;
    }

    public function save(int $platformId, string $nonce, int $expiresAt): bool
    {
        $result = $this->wpdb->insert(
            $this->table,
            [
                'platform_id' => $platformId,
                'nonce' => $nonce,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ]
        );

        return $result !== false;
    }

    public function cleanup(): int
    {
        return (int) $this->wpdb->query(
            "DELETE FROM {$this->table} WHERE expires_at < NOW()"
        );
    }
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Repositories
git commit -m "feat(lti): add Context and Nonce repositories"
```

---

### Task 1.6: WordPress DataConnector for celtic/lti

**Files:**
- Create: `web/app/plugins/netdust-lti/src/DataConnector/WPDataConnector.php`

**Step 1: Create WPDataConnector**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\DataConnector;

use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\PlatformNonce;
use ceLTIc\LTI\AccessToken;
use ceLTIc\LTI\Context;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\UserResult;
use NetdustLTI\Repositories\PlatformRepository;
use NetdustLTI\Repositories\NonceRepository;

final class WPDataConnector extends DataConnector
{
    private \wpdb $wpdb;
    private string $prefix;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->prefix = $wpdb->prefix . 'netdust_lti_';
        parent::__construct(null, $this->prefix);
    }

    // Platform methods
    public function loadPlatform(Platform $platform): bool
    {
        $sql = "SELECT * FROM {$this->prefix}platforms WHERE ";

        if ($platform->getRecordId()) {
            $sql .= $this->wpdb->prepare("id = %d", $platform->getRecordId());
        } elseif ($platform->platformId && $platform->clientId) {
            $sql .= $this->wpdb->prepare(
                "platform_id = %s AND client_id = %s",
                $platform->platformId,
                $platform->clientId
            );
        } else {
            return false;
        }

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return false;
        }

        $platform->setRecordId((int) $row['id']);
        $platform->name = $row['name'];
        $platform->platformId = $row['platform_id'];
        $platform->clientId = $row['client_id'];
        $platform->deploymentId = $row['deployment_id'];
        $platform->authenticationUrl = $row['auth_endpoint'];
        $platform->accessTokenUrl = $row['token_endpoint'];
        $platform->jku = $row['jwks_endpoint'];
        $platform->enabled = (bool) $row['enabled'];
        $platform->created = strtotime($row['created_at']);
        $platform->updated = strtotime($row['updated_at']);

        return true;
    }

    public function savePlatform(Platform $platform): bool
    {
        $data = [
            'name' => $platform->name,
            'platform_id' => $platform->platformId,
            'client_id' => $platform->clientId,
            'deployment_id' => $platform->deploymentId,
            'auth_endpoint' => $platform->authenticationUrl,
            'token_endpoint' => $platform->accessTokenUrl,
            'jwks_endpoint' => $platform->jku,
            'enabled' => $platform->enabled ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];

        if ($platform->getRecordId()) {
            return $this->wpdb->update(
                $this->prefix . 'platforms',
                $data,
                ['id' => $platform->getRecordId()]
            ) !== false;
        }

        $data['created_at'] = current_time('mysql');
        $result = $this->wpdb->insert($this->prefix . 'platforms', $data);

        if ($result) {
            $platform->setRecordId((int) $this->wpdb->insert_id);
        }

        return $result !== false;
    }

    public function deletePlatform(Platform $platform): bool
    {
        // Cascade delete
        $this->wpdb->delete($this->prefix . 'access_tokens', ['platform_id' => $platform->getRecordId()]);
        $this->wpdb->delete($this->prefix . 'nonces', ['platform_id' => $platform->getRecordId()]);
        $this->wpdb->delete($this->prefix . 'contexts', ['platform_id' => $platform->getRecordId()]);

        return $this->wpdb->delete(
            $this->prefix . 'platforms',
            ['id' => $platform->getRecordId()]
        ) !== false;
    }

    public function getPlatforms(): array
    {
        $platforms = [];
        $rows = $this->wpdb->get_results(
            "SELECT id FROM {$this->prefix}platforms ORDER BY name",
            ARRAY_A
        );

        foreach ($rows as $row) {
            $platform = Platform::fromRecordId((int) $row['id'], $this);
            $platforms[] = $platform;
        }

        return $platforms;
    }

    // Nonce methods (required for security)
    public function loadPlatformNonce(PlatformNonce $nonce): bool
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}nonces
                 WHERE platform_id = %d AND nonce = %s AND expires_at > NOW()",
                $nonce->getPlatform()->getRecordId(),
                $nonce->getValue()
            ),
            ARRAY_A
        );

        return $row !== null;
    }

    public function savePlatformNonce(PlatformNonce $nonce): bool
    {
        return $this->wpdb->insert(
            $this->prefix . 'nonces',
            [
                'platform_id' => $nonce->getPlatform()->getRecordId(),
                'nonce' => $nonce->getValue(),
                'expires_at' => date('Y-m-d H:i:s', $nonce->expires),
            ]
        ) !== false;
    }

    // Access Token methods (required for AGS)
    public function loadAccessToken(AccessToken $accessToken): bool
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}access_tokens
                 WHERE platform_id = %d AND expires_at > NOW()",
                $accessToken->getPlatform()->getRecordId()
            ),
            ARRAY_A
        );

        if (!$row) {
            return false;
        }

        $accessToken->token = $row['token'];
        $accessToken->expires = strtotime($row['expires_at']);
        $accessToken->scopes = json_decode($row['scopes'] ?? '[]', true);

        return true;
    }

    public function saveAccessToken(AccessToken $accessToken): bool
    {
        // Delete existing
        $this->wpdb->delete(
            $this->prefix . 'access_tokens',
            ['platform_id' => $accessToken->getPlatform()->getRecordId()]
        );

        return $this->wpdb->insert(
            $this->prefix . 'access_tokens',
            [
                'platform_id' => $accessToken->getPlatform()->getRecordId(),
                'token' => $accessToken->token,
                'expires_at' => date('Y-m-d H:i:s', $accessToken->expires),
                'scopes' => json_encode($accessToken->scopes),
                'created_at' => current_time('mysql'),
            ]
        ) !== false;
    }

    // Context methods (minimal implementation)
    public function loadContext(Context $context): bool
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}contexts
                 WHERE platform_id = %d AND lti_context_id = %s",
                $context->getPlatform()->getRecordId(),
                $context->ltiContextId
            ),
            ARRAY_A
        );

        if (!$row) {
            return false;
        }

        $context->setRecordId((int) $row['id']);
        $context->title = $row['ld_course_id']; // Store LD course ID
        $context->created = strtotime($row['created_at']);
        $context->updated = strtotime($row['updated_at']);

        return true;
    }

    public function saveContext(Context $context): bool
    {
        // Minimal implementation - contexts are managed by ContextRepository
        return true;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/DataConnector
git commit -m "feat(lti): add WPDataConnector for celtic/lti library"
```

---

### Task 1.7: LTI Tool Class

**Files:**
- Create: `web/app/plugins/netdust-lti/src/LTI/NetdustLTITool.php`

**Step 1: Create NetdustLTITool**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\DataConnector\DataConnector;
use NetdustLTI\Domain\LtiClaims;
use NetdustLTI\Services\UserProvisioner;
use NetdustLTI\Services\CourseEnroller;

final class NetdustLTITool extends Tool
{
    private ?LtiClaims $claims = null;

    public function __construct(?DataConnector $dataConnector = null)
    {
        parent::__construct($dataConnector);

        // LTI 1.3 Configuration
        $this->signatureMethod = 'RS256';
        $this->jku = home_url('/lti/jwks');
        $this->kid = get_option('netdust_lti_kid', 'netdust-lti-key-1');
        $this->rsaKey = get_option('netdust_lti_private_key');
    }

    protected function onLaunch(): void
    {
        ntdst_log('lti')->info('Launch received', [
            'platform' => $this->platform->platformId ?? 'unknown',
            'user' => $this->userResult->ltiUserId ?? 'unknown',
        ]);

        // Parse claims
        $this->claims = LtiClaims::fromLtiTool($this);

        // Provision user
        $provisioner = ntdst_get(UserProvisioner::class);
        $user = $provisioner->provision($this->claims);

        if (is_wp_error($user)) {
            ntdst_log('lti')->error('User provisioning failed', [
                'error' => $user->get_error_message(),
            ]);
            $this->reason = $user->get_error_message();
            $this->ok = false;
            return;
        }

        // Enroll in course if specified
        $courseId = $this->claims->getCourseId();
        if ($courseId) {
            $enroller = ntdst_get(CourseEnroller::class);
            $enroller->enroll($user, $courseId, $this->claims, $this->platform->getRecordId());
        }

        // Log user in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false);

        ntdst_log('lti')->info('Launch successful', [
            'user_id' => $user->ID,
            'course_id' => $courseId,
        ]);

        // Redirect to course or dashboard
        if ($courseId) {
            $this->redirectUrl = get_permalink($courseId);
        } else {
            $this->redirectUrl = home_url('/dashboard/');
        }
    }

    protected function onContentItem(): void
    {
        ntdst_log('lti')->info('Deep linking request', [
            'platform' => $this->platform->platformId ?? 'unknown',
        ]);

        // Store return info in session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['lti_deep_link'] = [
            'platform_id' => $this->platform->getRecordId(),
            'return_url' => $this->returnUrl,
            'data' => $this->messageParameters['https://purl.imsglobal.org/spec/lti-dl/claim/data'] ?? null,
        ];

        // Redirect to course picker
        $this->redirectUrl = admin_url('admin.php?page=netdust-lti-deep-link');
    }

    protected function onError(): void
    {
        ntdst_log('lti')->error('Launch error', [
            'reason' => $this->reason,
            'platform' => $this->platform?->platformId ?? 'unknown',
        ]);

        $this->errorOutput = sprintf(
            '<div class="lti-error"><h1>LTI Launch Error</h1><p>%s</p></div>',
            esc_html($this->reason)
        );
    }

    public function getClaims(): ?LtiClaims
    {
        return $this->claims;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/LTI
git commit -m "feat(lti): add NetdustLTITool class extending celtic/lti"
```

---

### Task 1.8: User Provisioner Service

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Services/UserProvisioner.php`

**Step 1: Create UserProvisioner**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use NetdustLTI\Domain\LtiClaims;
use WP_User;
use WP_Error;

final class UserProvisioner
{
    private const META_LTI_SUB = '_netdust_lti_sub';
    private const META_LTI_PROVISIONED = '_netdust_lti_provisioned';

    public function provision(LtiClaims $claims): WP_User|WP_Error
    {
        // 1. Look up by LTI sub (most reliable for repeat launches)
        $userId = $this->findByLtiSub($claims->sub);

        // 2. Look up by email
        if (!$userId && $claims->email) {
            $existing = get_user_by('email', $claims->email);
            $userId = $existing?->ID;
        }

        // 3. Create new user
        if (!$userId) {
            $userId = $this->createUser($claims);

            if (is_wp_error($userId)) {
                return $userId;
            }

            // Mark as LTI-provisioned
            update_user_meta($userId, self::META_LTI_PROVISIONED, 1);
        }

        // Store/update LTI sub
        update_user_meta($userId, self::META_LTI_SUB, $claims->sub);

        // Update last LTI login
        update_user_meta($userId, '_netdust_lti_last_login', current_time('mysql'));

        $user = get_user_by('id', $userId);

        if (!$user) {
            return new WP_Error('user_not_found', 'User could not be retrieved');
        }

        return $user;
    }

    private function findByLtiSub(string $sub): ?int
    {
        global $wpdb;

        $userId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::META_LTI_SUB,
                $sub
            )
        );

        return $userId ? (int) $userId : null;
    }

    private function createUser(LtiClaims $claims): int|WP_Error
    {
        // Generate username
        $username = $this->generateUsername($claims);

        // Create user
        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $claims->email ?? $username . '@lti.local',
            'user_pass' => wp_generate_password(24),
            'display_name' => $claims->name ?? $username,
            'first_name' => $claims->givenName ?? '',
            'last_name' => $claims->familyName ?? '',
            'role' => $claims->isInstructor() ? 'instructor' : 'subscriber',
        ]);

        return $userId;
    }

    private function generateUsername(LtiClaims $claims): string
    {
        $base = '';

        if ($claims->email) {
            $base = sanitize_user(explode('@', $claims->email)[0], true);
        } elseif ($claims->name) {
            $base = sanitize_user(str_replace(' ', '_', strtolower($claims->name)), true);
        } else {
            $base = 'lti_user';
        }

        // Ensure unique
        $username = $base;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    public function isLtiUser(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_LTI_PROVISIONED, true);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Services
git commit -m "feat(lti): add UserProvisioner service"
```

---

### Task 1.9: Course Enroller Service

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Services/CourseEnroller.php`

**Step 1: Create CourseEnroller**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use NetdustLTI\Domain\LtiClaims;
use NetdustLTI\Repositories\ContextRepository;
use WP_User;

final class CourseEnroller
{
    private const META_LTI_CONTEXT = '_netdust_lti_context_';

    public function __construct(
        private readonly ContextRepository $contextRepository,
    ) {}

    public function enroll(WP_User $user, int $courseId, LtiClaims $claims, int $platformId): void
    {
        // Check if LearnDash is active
        if (!function_exists('ld_update_course_access')) {
            ntdst_log('lti')->warning('LearnDash not available for enrollment', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
            ]);
            return;
        }

        // Check if already enrolled
        $hasAccess = sfwd_lms_has_access($courseId, $user->ID);

        if (!$hasAccess) {
            // Grant course access
            ld_update_course_access($user->ID, $courseId, false);

            ntdst_log('lti')->info('User enrolled in course', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
            ]);
        }

        // Store LTI context for grade passback
        $this->storeLtiContext($user->ID, $courseId, $claims, $platformId);
    }

    private function storeLtiContext(int $userId, int $courseId, LtiClaims $claims, int $platformId): void
    {
        $contextData = [
            'platform_id' => $platformId,
            'lti_context_id' => $claims->contextId,
            'resource_link_id' => $claims->resourceLinkId,
            'lti_user_id' => $claims->sub,
            'line_item_url' => $claims->lineItemUrl,
            'line_items_url' => $claims->lineItemsUrl,
            'scores_url' => $claims->scoresUrl,
            'stored_at' => current_time('mysql'),
        ];

        // Store in user meta for quick access during grade passback
        update_user_meta(
            $userId,
            self::META_LTI_CONTEXT . $courseId,
            $contextData
        );

        // Also store/update in contexts table
        $existing = $this->contextRepository->findByLtiContext(
            $platformId,
            $claims->contextId ?? '',
            $claims->resourceLinkId
        );

        if ($existing) {
            $this->contextRepository->update($existing['id'], [
                'line_item_url' => $claims->lineItemUrl,
            ]);
        } elseif ($claims->contextId) {
            $this->contextRepository->create([
                'platform_id' => $platformId,
                'lti_context_id' => $claims->contextId,
                'ld_course_id' => $courseId,
                'resource_link_id' => $claims->resourceLinkId,
                'line_item_url' => $claims->lineItemUrl,
            ]);
        }
    }

    public function getLtiContext(int $userId, int $courseId): ?array
    {
        $context = get_user_meta($userId, self::META_LTI_CONTEXT . $courseId, true);
        return $context ?: null;
    }

    public function hasLtiContext(int $userId, int $courseId): bool
    {
        return $this->getLtiContext($userId, $courseId) !== null;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Services
git commit -m "feat(lti): add CourseEnroller service"
```

---

### Task 1.10: Endpoint Router

**Files:**
- Create: `web/app/plugins/netdust-lti/src/LTI/EndpointRouter.php`
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`

**Step 1: Create EndpointRouter**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use NetdustLTI\DataConnector\WPDataConnector;

final class EndpointRouter
{
    public function __construct()
    {
        add_action('init', [$this, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'registerQueryVars']);
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^lti/([a-z-]+)/?$', 'index.php?lti_action=$matches[1]', 'top');
    }

    public function registerQueryVars(array $vars): array
    {
        $vars[] = 'lti_action';
        return $vars;
    }

    public function handleRequest(): void
    {
        $action = get_query_var('lti_action');

        if (!$action) {
            return;
        }

        // Configure session for cross-site requests
        $this->configureSession();

        switch ($action) {
            case 'login':
            case 'launch':
                $this->handleLaunch();
                break;

            case 'jwks':
                $this->handleJwks();
                break;

            case 'deep-link':
                $this->handleDeepLink();
                break;

            default:
                wp_die('Invalid LTI action', 'LTI Error', ['response' => 400]);
        }
    }

    private function configureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Cross-site session cookies for LTI in iframe
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                ini_set('session.cookie_samesite', 'None');
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    private function handleLaunch(): void
    {
        $dataConnector = new WPDataConnector();
        $tool = new NetdustLTITool($dataConnector);

        $tool->handleRequest();

        if ($tool->redirectUrl) {
            wp_redirect($tool->redirectUrl);
            exit;
        }

        if (!$tool->ok) {
            wp_die(
                esc_html($tool->reason ?: 'LTI launch failed'),
                'LTI Error',
                ['response' => 400]
            );
        }

        // Output error if set
        if ($tool->errorOutput) {
            echo $tool->errorOutput;
            exit;
        }
    }

    private function handleJwks(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');

        $publicKey = get_option('netdust_lti_public_key');
        $kid = get_option('netdust_lti_kid');

        if (!$publicKey || !$kid) {
            http_response_code(500);
            echo json_encode(['error' => 'Keys not configured']);
            exit;
        }

        // Convert PEM to JWK
        $jwk = \ceLTIc\LTI\Jwt\Jwt::getJWK($publicKey, 'RS256', $kid);

        echo json_encode(['keys' => [$jwk]]);
        exit;
    }

    private function handleDeepLink(): void
    {
        // Deep linking uses the same launch handler initially
        // The tool's onContentItem() method handles the redirect
        $this->handleLaunch();
    }
}
```

**Step 2: Update Plugin.php to register router**

Add to `Plugin::init()`:

```php
private function init(): void
{
    // Register endpoints
    ntdst_get(LTI\EndpointRouter::class);

    // ... existing code ...
}
```

**Step 3: Flush rewrite rules**

Run:
```bash
ddev exec wp rewrite flush
```

**Step 4: Verify endpoints**

Run:
```bash
curl -I "https://stride.ddev.site/lti/jwks"
```
Expected: HTTP 200 with JSON content-type

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add EndpointRouter with OIDC/launch/JWKS handlers"
```

---

### Task 1.11: Admin Platform Management

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Admin/AdminPage.php`
- Create: `web/app/plugins/netdust-lti/src/Admin/PlatformListTable.php`
- Create: `web/app/plugins/netdust-lti/templates/admin/settings-page.php`
- Create: `web/app/plugins/netdust-lti/templates/admin/platform-form.php`

**Step 1: Create AdminPage**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Repositories\PlatformRepository;
use NetdustLTI\Domain\Platform;

final class AdminPage
{
    private PlatformRepository $platformRepository;

    public function __construct()
    {
        $this->platformRepository = ntdst_get(PlatformRepository::class);

        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function registerMenu(): void
    {
        add_options_page(
            'Netdust LTI',
            'Netdust LTI',
            'manage_options',
            'netdust-lti',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $action = $_GET['action'] ?? 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                $this->renderPlatformForm();
                break;

            default:
                $this->renderPlatformList();
        }
    }

    private function renderPlatformList(): void
    {
        $listTable = new PlatformListTable($this->platformRepository);
        $listTable->prepare_items();

        include dirname(__DIR__, 2) . '/templates/admin/settings-page.php';
    }

    private function renderPlatformForm(): void
    {
        $platform = null;
        $platformId = isset($_GET['platform_id']) ? (int) $_GET['platform_id'] : null;

        if ($platformId) {
            $platform = $this->platformRepository->find($platformId);
            if (is_wp_error($platform)) {
                $platform = null;
            }
        }

        include dirname(__DIR__, 2) . '/templates/admin/platform-form.php';
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['netdust_lti_platform_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_platform_nonce'], 'netdust_lti_save_platform')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $platform = new Platform(
            id: isset($_POST['platform_id']) ? (int) $_POST['platform_id'] : null,
            name: sanitize_text_field($_POST['name']),
            platformId: esc_url_raw($_POST['platform_url']),
            clientId: sanitize_text_field($_POST['client_id']),
            deploymentId: sanitize_text_field($_POST['deployment_id']) ?: null,
            authEndpoint: esc_url_raw($_POST['auth_endpoint']),
            tokenEndpoint: esc_url_raw($_POST['token_endpoint']),
            jwksEndpoint: esc_url_raw($_POST['jwks_endpoint']),
            enabled: isset($_POST['enabled']),
        );

        if ($platform->id) {
            $result = $this->platformRepository->update($platform->id, $platform);
        } else {
            $result = $this->platformRepository->create($platform);
        }

        if (is_wp_error($result)) {
            add_settings_error('netdust_lti', 'save_failed', $result->get_error_message());
        } else {
            wp_redirect(admin_url('options-general.php?page=netdust-lti&saved=1'));
            exit;
        }
    }

    public function getToolEndpoints(): array
    {
        return [
            'oidc_login' => home_url('/lti/login'),
            'launch' => home_url('/lti/launch'),
            'jwks' => home_url('/lti/jwks'),
            'deep_link' => home_url('/lti/deep-link'),
        ];
    }
}
```

**Step 2: Create PlatformListTable**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Repositories\PlatformRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PlatformListTable extends \WP_List_Table
{
    private PlatformRepository $repository;

    public function __construct(PlatformRepository $repository)
    {
        $this->repository = $repository;

        parent::__construct([
            'singular' => 'platform',
            'plural' => 'platforms',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'name' => 'Name',
            'platform_id' => 'Platform ID',
            'client_id' => 'Client ID',
            'enabled' => 'Status',
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items = $this->repository->all();
    }

    protected function column_name($item): string
    {
        $editUrl = admin_url('options-general.php?page=netdust-lti&action=edit&platform_id=' . $item->id);
        $deleteUrl = wp_nonce_url(
            admin_url('options-general.php?page=netdust-lti&action=delete&platform_id=' . $item->id),
            'delete_platform_' . $item->id
        );

        $actions = [
            'edit' => sprintf('<a href="%s">Edit</a>', esc_url($editUrl)),
            'delete' => sprintf('<a href="%s" onclick="return confirm(\'Delete this platform?\')">Delete</a>', esc_url($deleteUrl)),
        ];

        return sprintf('%s %s', esc_html($item->name), $this->row_actions($actions));
    }

    protected function column_platform_id($item): string
    {
        return esc_html($item->platformId);
    }

    protected function column_client_id($item): string
    {
        return esc_html($item->clientId);
    }

    protected function column_enabled($item): string
    {
        return $item->enabled ? '<span style="color:green">Enabled</span>' : '<span style="color:gray">Disabled</span>';
    }
}
```

**Step 3: Create settings-page.php template**

```php
<div class="wrap">
    <h1>Netdust LTI Settings</h1>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success"><p>Platform saved successfully.</p></div>
    <?php endif; ?>

    <h2>Your Tool Endpoints</h2>
    <table class="form-table">
        <?php
        $adminPage = ntdst_get(\NetdustLTI\Admin\AdminPage::class);
        $endpoints = $adminPage->getToolEndpoints();
        foreach ($endpoints as $name => $url): ?>
            <tr>
                <th><?php echo esc_html(ucwords(str_replace('_', ' ', $name))); ?></th>
                <td><code><?php echo esc_html($url); ?></code></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Registered Platforms</h2>
    <p><a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti&action=add')); ?>" class="button">Add Platform</a></p>

    <?php $listTable->display(); ?>
</div>
```

**Step 4: Create platform-form.php template**

```php
<div class="wrap">
    <h1><?php echo $platform ? 'Edit Platform' : 'Add Platform'; ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('netdust_lti_save_platform', 'netdust_lti_platform_nonce'); ?>

        <?php if ($platform): ?>
            <input type="hidden" name="platform_id" value="<?php echo esc_attr($platform->id); ?>">
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="name">Name</label></th>
                <td><input type="text" id="name" name="name" class="regular-text" value="<?php echo esc_attr($platform?->name ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="platform_url">Platform ID (Issuer)</label></th>
                <td><input type="url" id="platform_url" name="platform_url" class="regular-text" value="<?php echo esc_attr($platform?->platformId ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="client_id">Client ID</label></th>
                <td><input type="text" id="client_id" name="client_id" class="regular-text" value="<?php echo esc_attr($platform?->clientId ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="deployment_id">Deployment ID</label></th>
                <td><input type="text" id="deployment_id" name="deployment_id" class="regular-text" value="<?php echo esc_attr($platform?->deploymentId ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="auth_endpoint">Auth Endpoint</label></th>
                <td><input type="url" id="auth_endpoint" name="auth_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->authEndpoint ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="token_endpoint">Token Endpoint</label></th>
                <td><input type="url" id="token_endpoint" name="token_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->tokenEndpoint ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="jwks_endpoint">JWKS Endpoint</label></th>
                <td><input type="url" id="jwks_endpoint" name="jwks_endpoint" class="regular-text" value="<?php echo esc_attr($platform?->jwksEndpoint ?? ''); ?>" required></td>
            </tr>
            <tr>
                <th><label for="enabled">Enabled</label></th>
                <td><input type="checkbox" id="enabled" name="enabled" value="1" <?php checked($platform?->enabled ?? true); ?>></td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button-primary" value="Save Platform">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="button">Cancel</a>
        </p>
    </form>
</div>
```

**Step 5: Register AdminPage in Plugin**

Add to `Plugin::init()`:

```php
if (is_admin()) {
    ntdst_get(Admin\AdminPage::class);
}
```

**Step 6: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add admin UI for platform management"
```

---

## Phase 1 Complete Checkpoint

At this point you have a working LTI 1.3 MVP:
- Plugin scaffold with composer dependencies
- Database tables for platforms, contexts, nonces, tokens
- RSA key generation for JWT signing
- Domain objects (Platform, LtiClaims)
- Repositories (Platform, Context, Nonce)
- WPDataConnector for celtic/lti library
- NetdustLTITool with launch handling
- UserProvisioner and CourseEnroller services
- EndpointRouter with /lti/login, /lti/launch, /lti/jwks
- Admin UI for platform registration

**Test the MVP:**
1. Register a test platform using 1EdTech reference implementation
2. Perform a test launch
3. Verify user is created and enrolled

---

## Phase 2: Grade Passback

### Task 2.1: GradePassbackService

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Services/GradePassbackService.php`

**Step 1: Create GradePassbackService**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Services;

use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\Enum\ServiceAction;
use NetdustLTI\DataConnector\WPDataConnector;
use WP_Error;

final class GradePassbackService
{
    public function postCompletion(int $userId, int $courseId): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: 1,
            maxScore: 1,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    public function postQuizScore(int $userId, int $courseId, float $score, float $maxScore): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: $score,
            maxScore: $maxScore,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    public function postTinCannyScore(int $userId, int $courseId, float $result): bool|WP_Error
    {
        return $this->postScore(
            userId: $userId,
            courseId: $courseId,
            score: $result,
            maxScore: 100,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded'
        );
    }

    private function postScore(
        int $userId,
        int $courseId,
        float $score,
        float $maxScore,
        string $activityProgress,
        string $gradingProgress
    ): bool|WP_Error {
        // Get LTI context from user meta
        $context = get_user_meta($userId, '_netdust_lti_context_' . $courseId, true);

        if (!$context) {
            return new WP_Error('no_context', 'No LTI context found for this user/course');
        }

        if (empty($context['line_item_url']) && empty($context['scores_url'])) {
            return new WP_Error('no_ags', 'No AGS endpoint available');
        }

        ntdst_log('lti-grade')->info('Posting score', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'score' => "{$score}/{$maxScore}",
            'platform_id' => $context['platform_id'],
        ]);

        try {
            $dataConnector = new WPDataConnector();
            $platform = Platform::fromRecordId($context['platform_id'], $dataConnector);

            if (!$platform) {
                return new WP_Error('platform_not_found', 'Platform not found');
            }

            // Build outcome
            $outcome = new Outcome($score, $maxScore);
            $outcome->activityProgress = $activityProgress;
            $outcome->gradingProgress = $gradingProgress;

            // Post to AGS
            $scoreUrl = $context['scores_url'] ?? $context['line_item_url'] . '/scores';
            $ltiUserId = $context['lti_user_id'];

            $scoreData = [
                'userId' => $ltiUserId,
                'scoreGiven' => $score,
                'scoreMaximum' => $maxScore,
                'activityProgress' => $activityProgress,
                'gradingProgress' => $gradingProgress,
                'timestamp' => date('c'),
            ];

            $result = $this->sendAgsScore($platform, $scoreUrl, $scoreData);

            if (is_wp_error($result)) {
                ntdst_log('lti-grade')->error('AGS failed', [
                    'error' => $result->get_error_message(),
                ]);
                return $result;
            }

            ntdst_log('lti-grade')->info('Score posted successfully');
            return true;

        } catch (\Exception $e) {
            ntdst_log('lti-grade')->error('Exception posting score', [
                'error' => $e->getMessage(),
            ]);
            return new WP_Error('exception', $e->getMessage());
        }
    }

    private function sendAgsScore(Platform $platform, string $scoreUrl, array $scoreData): bool|WP_Error
    {
        // Get access token
        $accessToken = $platform->getAccessToken(['https://purl.imsglobal.org/spec/lti-ags/scope/score']);

        if (!$accessToken) {
            return new WP_Error('token_failed', 'Could not obtain access token');
        }

        $response = wp_remote_post($scoreUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken->token,
                'Content-Type' => 'application/vnd.ims.lis.v1.score+json',
            ],
            'body' => json_encode($scoreData),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'ags_error',
                'AGS returned ' . $code . ': ' . wp_remote_retrieve_body($response)
            );
        }

        return true;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Services
git commit -m "feat(lti): add GradePassbackService for AGS score posting"
```

---

### Task 2.2: LearnDash Bridge

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Bridges/LearnDashBridge.php`

**Step 1: Create LearnDashBridge**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Bridges;

use NetdustLTI\Services\GradePassbackService;
use NetdustLTI\Services\CourseEnroller;

final class LearnDashBridge
{
    public function __construct(
        private readonly GradePassbackService $gradeService,
        private readonly CourseEnroller $enroller,
    ) {
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 1);
        add_action('learndash_quiz_completed', [$this, 'onQuizCompleted'], 10, 2);
    }

    public function onCourseCompleted(array $data): void
    {
        $userId = $data['user']->ID;
        $courseId = $data['course']->ID;

        if (!$this->shouldPostGrade($courseId, 'course_complete')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($userId, $courseId)) {
            return;
        }

        $result = $this->gradeService->postCompletion($userId, $courseId);

        if (is_wp_error($result)) {
            ntdst_log('lti-grade')->warning('Course completion grade failed', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'error' => $result->get_error_message(),
            ]);
        }
    }

    public function onQuizCompleted(array $data, \WP_User $user): void
    {
        $courseId = $data['course']->ID ?? null;

        if (!$courseId) {
            return;
        }

        if (!$this->shouldPostGrade($courseId, 'quiz_score')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($user->ID, $courseId)) {
            return;
        }

        $score = $data['score'] ?? 0;
        $maxScore = $data['count'] ?? 100;

        $result = $this->gradeService->postQuizScore($user->ID, $courseId, $score, $maxScore);

        if (is_wp_error($result)) {
            ntdst_log('lti-grade')->warning('Quiz grade failed', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
                'error' => $result->get_error_message(),
            ]);
        }
    }

    private function shouldPostGrade(int $courseId, string $trigger): bool
    {
        $settings = get_post_meta($courseId, '_netdust_lti_grade_settings', true) ?: [];
        return !empty($settings[$trigger]);
    }
}
```

**Step 2: Register bridge in Plugin**

Add to `Plugin::init()`:

```php
// Register bridges after LearnDash is loaded
add_action('learndash_init', function() {
    ntdst_get(Bridges\LearnDashBridge::class);
});
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add LearnDashBridge for course/quiz grade passback"
```

---

### Task 2.3: TinCanny Bridge

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Bridges/TinCannyBridge.php`

**Step 1: Create TinCannyBridge**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Bridges;

use NetdustLTI\Services\GradePassbackService;
use NetdustLTI\Services\CourseEnroller;

final class TinCannyBridge
{
    public function __construct(
        private readonly GradePassbackService $gradeService,
        private readonly CourseEnroller $enroller,
    ) {
        add_action('tincanny_module_result_processed', [$this, 'onModuleResult'], 10, 3);
    }

    public function onModuleResult(int $moduleId, int $userId, float $result): void
    {
        // Get course from TinCanny's last known course
        $courseId = (int) get_user_meta($userId, 'tincan_last_known_ld_course', true);

        if (!$courseId) {
            return;
        }

        if (!$this->shouldPostGrade($courseId, 'tincanny_complete')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($userId, $courseId)) {
            return;
        }

        $gradeResult = $this->gradeService->postTinCannyScore($userId, $courseId, $result);

        if (is_wp_error($gradeResult)) {
            ntdst_log('lti-grade')->warning('TinCanny grade failed', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'error' => $gradeResult->get_error_message(),
            ]);
        }
    }

    private function shouldPostGrade(int $courseId, string $trigger): bool
    {
        $settings = get_post_meta($courseId, '_netdust_lti_grade_settings', true) ?: [];
        return !empty($settings[$trigger]);
    }
}
```

**Step 2: Register bridge in Plugin**

Add to `Plugin::init()`:

```php
// Register TinCanny bridge if available
if (class_exists('UCTINCAN\Database')) {
    ntdst_get(Bridges\TinCannyBridge::class);
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add TinCannyBridge for xAPI grade passback"
```

---

### Task 2.4: Course Settings Metabox

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Admin/CourseSettingsMetabox.php`

**Step 1: Create CourseSettingsMetabox**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

final class CourseSettingsMetabox
{
    private const META_KEY = '_netdust_lti_grade_settings';

    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_sfwd-courses', [$this, 'save']);
    }

    public function register(): void
    {
        add_meta_box(
            'netdust_lti_grade_settings',
            'LTI Grade Passback',
            [$this, 'render'],
            'sfwd-courses',
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        $settings = get_post_meta($post->ID, self::META_KEY, true) ?: [];

        wp_nonce_field('netdust_lti_course_settings', 'netdust_lti_course_nonce');
        ?>
        <p>Push grades to external LMS when:</p>
        <label>
            <input type="checkbox" name="lti_grade[course_complete]" value="1"
                <?php checked(!empty($settings['course_complete'])); ?>>
            Course completed
        </label><br>
        <label>
            <input type="checkbox" name="lti_grade[quiz_score]" value="1"
                <?php checked(!empty($settings['quiz_score'])); ?>>
            Quiz completed
        </label><br>
        <label>
            <input type="checkbox" name="lti_grade[tincanny_complete]" value="1"
                <?php checked(!empty($settings['tincanny_complete'])); ?>>
            TinCanny module completed
        </label>
        <?php
    }

    public function save(int $postId): void
    {
        if (!isset($_POST['netdust_lti_course_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_course_nonce'], 'netdust_lti_course_settings')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $settings = [];

        if (isset($_POST['lti_grade']) && is_array($_POST['lti_grade'])) {
            foreach ($_POST['lti_grade'] as $key => $value) {
                $settings[sanitize_key($key)] = 1;
            }
        }

        update_post_meta($postId, self::META_KEY, $settings);
    }
}
```

**Step 2: Register metabox in Plugin**

Add to `Plugin::init()`:

```php
if (is_admin()) {
    ntdst_get(Admin\AdminPage::class);
    ntdst_get(Admin\CourseSettingsMetabox::class);
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add per-course grade passback settings metabox"
```

---

## Phase 2 Complete Checkpoint

Grade passback is now functional:
- GradePassbackService posts scores via AGS
- LearnDashBridge triggers on course/quiz completion
- TinCannyBridge triggers on xAPI module completion
- Per-course settings control which triggers are active

---

## Phase 3: Deep Linking

### Task 3.1: Deep Link Handler and Course Picker

**Files:**
- Create: `web/app/plugins/netdust-lti/src/LTI/DeepLinkHandler.php`
- Create: `web/app/plugins/netdust-lti/templates/deep-link-picker.php`

**Step 1: Create DeepLinkHandler**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\LTI;

use ceLTIc\LTI\Content\LtiLinkItem;
use ceLTIc\LTI\Platform;
use NetdustLTI\DataConnector\WPDataConnector;

final class DeepLinkHandler
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'handleSubmission']);
    }

    public function registerPage(): void
    {
        add_submenu_page(
            null, // Hidden from menu
            'Select Course',
            'Select Course',
            'manage_options',
            'netdust-lti-deep-link',
            [$this, 'renderPicker']
        );
    }

    public function renderPicker(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die('Invalid deep link session');
        }

        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        include dirname(__DIR__, 2) . '/templates/deep-link-picker.php';
    }

    public function handleSubmission(): void
    {
        if (!isset($_POST['netdust_lti_deep_link_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_deep_link_nonce'], 'netdust_lti_deep_link')) {
            wp_die('Security check failed');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['lti_deep_link'])) {
            wp_die('Invalid deep link session');
        }

        $courseId = (int) $_POST['course_id'];
        $course = get_post($courseId);

        if (!$course || $course->post_type !== 'sfwd-courses') {
            wp_die('Invalid course');
        }

        $deepLinkData = $_SESSION['lti_deep_link'];
        unset($_SESSION['lti_deep_link']);

        $this->sendResponse($deepLinkData, $course);
    }

    private function sendResponse(array $deepLinkData, \WP_Post $course): void
    {
        $dataConnector = new WPDataConnector();
        $platform = Platform::fromRecordId($deepLinkData['platform_id'], $dataConnector);

        // Create LTI Resource Link item
        $item = new LtiLinkItem();
        $item->setTitle($course->post_title);
        $item->setUrl(home_url('/lti/launch'));
        $item->setText(wp_strip_all_tags($course->post_excerpt ?: ''));

        // Custom parameters for launch
        $item->setCustom([
            'ld_course_id' => $course->ID,
        ]);

        // Line item for gradebook
        $item->setLineItem([
            'label' => $course->post_title . ' - Completion',
            'scoreMaximum' => 100,
            'resourceId' => 'course-' . $course->ID,
            'tag' => 'completion',
        ]);

        // Build and send response
        $tool = new NetdustLTITool($dataConnector);

        $formParams = $tool->sendContentItemResponse(
            $platform,
            [$item],
            $deepLinkData['return_url'],
            $deepLinkData['data']
        );

        // Output auto-submit form
        $this->outputAutoSubmitForm($deepLinkData['return_url'], $formParams);
    }

    private function outputAutoSubmitForm(string $url, array $params): void
    {
        ?>
        <!DOCTYPE html>
        <html>
        <head><title>Returning...</title></head>
        <body>
            <p>Returning to platform...</p>
            <form id="deep-link-form" action="<?php echo esc_url($url); ?>" method="post">
                <?php foreach ($params as $name => $value): ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
            </form>
            <script>document.getElementById('deep-link-form').submit();</script>
        </body>
        </html>
        <?php
        exit;
    }
}
```

**Step 2: Create deep-link-picker.php template**

```php
<div class="wrap">
    <h1>Select Course</h1>
    <p>Choose a course to add to the external LMS:</p>

    <form method="post" action="">
        <?php wp_nonce_field('netdust_lti_deep_link', 'netdust_lti_deep_link_nonce'); ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th></th>
                    <th>Course</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td>
                            <input type="radio" name="course_id" value="<?php echo esc_attr($course->ID); ?>" required>
                        </td>
                        <td><?php echo esc_html($course->post_title); ?></td>
                        <td><?php echo esc_html($course->post_status); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="submit">
            <input type="submit" class="button-primary" value="Add to Course">
        </p>
    </form>
</div>
```

**Step 3: Register handler in Plugin**

Add to `Plugin::init()`:

```php
if (is_admin()) {
    ntdst_get(LTI\DeepLinkHandler::class);
    // ... existing admin registrations
}
```

**Step 4: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add Deep Linking course picker"
```

---

## Phase 4: Polish

### Task 4.1: Admin Logs Viewer

**Files:**
- Create: `web/app/plugins/netdust-lti/templates/admin/logs.php`
- Modify: `web/app/plugins/netdust-lti/src/Admin/AdminPage.php`

**Step 1: Add logs tab to AdminPage**

Add method to AdminPage:

```php
private function renderLogs(): void
{
    $tab = $_GET['tab'] ?? 'launches';

    $channel = $tab === 'grades' ? 'lti-grade' : 'lti';
    $logs = ntdst_log($channel)->recent(50);

    include dirname(__DIR__, 2) . '/templates/admin/logs.php';
}
```

Update `renderPage()` to handle logs action.

**Step 2: Create logs.php template**

```php
<div class="wrap">
    <h1>LTI Logs</h1>

    <h2 class="nav-tab-wrapper">
        <a href="?page=netdust-lti&action=logs&tab=launches"
           class="nav-tab <?php echo $tab === 'launches' ? 'nav-tab-active' : ''; ?>">
            Launches
        </a>
        <a href="?page=netdust-lti&action=logs&tab=grades"
           class="nav-tab <?php echo $tab === 'grades' ? 'nav-tab-active' : ''; ?>">
            Grade Passbacks
        </a>
    </h2>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Time</th>
                <th>Level</th>
                <th>Message</th>
                <th>Context</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log['created_at']); ?></td>
                    <td><?php echo esc_html($log['level']); ?></td>
                    <td><?php echo esc_html($log['message']); ?></td>
                    <td><pre><?php echo esc_html(json_encode($log['context'], JSON_PRETTY_PRINT)); ?></pre></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "feat(lti): add admin logs viewer"
```

---

### Task 4.2: Documentation

**Files:**
- Create: `web/app/plugins/netdust-lti/README.md`

**Step 1: Write README**

```markdown
# Netdust LTI

LTI 1.3 Tool Provider for LearnDash integration.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- NTDST Core
- LearnDash
- TinCanny (optional)

## Installation

1. Upload to `/wp-content/plugins/netdust-lti/`
2. Run `composer install` in the plugin directory
3. Activate the plugin
4. Go to Settings → Netdust LTI

## Configuration

### Your Tool Endpoints

Configure these in your external LMS:

- **OIDC Login URL:** `https://yoursite.com/lti/login`
- **Launch URL:** `https://yoursite.com/lti/launch`
- **JWKS URL:** `https://yoursite.com/lti/jwks`
- **Deep Link URL:** `https://yoursite.com/lti/deep-link`

### Registering a Platform

1. Go to Settings → Netdust LTI
2. Click "Add Platform"
3. Enter the platform details from your LMS admin

### Grade Passback

Enable grade passback per course:

1. Edit a LearnDash course
2. In the "LTI Grade Passback" metabox, check desired triggers
3. Save the course

## Supported Platforms

Tested with:
- 1EdTech Reference Implementation
- Moodle 4.x
- Canvas LMS

## Troubleshooting

Check logs at Settings → Netdust LTI → Logs
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti
git commit -m "docs(lti): add README with setup instructions"
```

---

## Final Commit

```bash
git add -A
git commit -m "feat(lti): complete netdust-lti plugin implementation"
```

---

**Plan complete and saved to `docs/plans/2025-02-20-netdust-lti-implementation.md`.**

**Two execution options:**

1. **Subagent-Driven (this session)** — I dispatch fresh subagent per task, review between tasks, fast iteration

2. **Parallel Session (separate)** — Open new session with executing-plans, batch execution with checkpoints

Which approach?
