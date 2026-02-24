#!/bin/bash
# wp-browser-setup.sh
# Run inside your DDEV Bedrock project to set up acceptance testing.
# Usage: bash path/to/scripts/wp-browser-setup.sh

set -e

echo "=== Setting up wp-browser for DDEV acceptance testing ==="

DDEV_NAME=$(basename "$(pwd)")
SITE_URL="https://${DDEV_NAME}.ddev.site"
echo "Detected DDEV site URL: ${SITE_URL}"

# 1. Install wp-browser
echo "Installing wp-browser..."
ddev composer require --dev lucatume/wp-browser

# 2. Create Selenium docker-compose
echo "Adding Selenium to DDEV..."
cat > .ddev/docker-compose.selenium.yaml << 'YAML'
services:
  selenium:
    image: selenium/standalone-chrome:latest
    container_name: ddev-${DDEV_SITENAME}-selenium
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
    expose:
      - "4444"
      - "7900"
    environment:
      - SE_NODE_MAX_SESSIONS=3
      - SE_NODE_OVERRIDE_MAX_SESSIONS=true
    shm_size: '2gb'
    external_links:
      - ddev-router:${DDEV_SITENAME}.ddev.site
    networks:
      - default
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:4444/wd/hub/status"]
      interval: 10s
      timeout: 5s
      retries: 5
YAML

# 3. Create directories
echo "Creating test directories..."
mkdir -p tests/acceptance tests/_data tests/_output tests/_support/Helper tests/_envs

# 4. codeception.yml
cat > codeception.yml << 'YML'
namespace: Tests
support_namespace: Support
paths:
  tests: tests
  output: tests/_output
  data: tests/_data
  support: tests/_support
  envs: tests/_envs
actor_suffix: Tester
settings:
  colors: true
  memory_limit: 1024M
extensions:
  enabled:
    - Codeception\Extension\RunFailed
params:
  - tests/.env
YML

# 5. Acceptance suite config
cat > tests/acceptance.suite.yml << 'YML'
actor: AcceptanceTester
modules:
  enabled:
    - WPWebDriver
    - WPDb
    - \Tests\Support\Helper\Acceptance
  config:
    WPWebDriver:
      url: '%WP_URL%'
      adminUsername: '%WP_ADMIN_USERNAME%'
      adminPassword: '%WP_ADMIN_PASSWORD%'
      adminPath: '%WP_ADMIN_PATH%'
      browser: chrome
      host: selenium
      port: 4444
      path: /wd/hub
      window_size: 1920x1080
      capabilities:
        "goog:chromeOptions":
          args:
            - "--headless=new"
            - "--disable-gpu"
            - "--no-sandbox"
            - "--disable-dev-shm-usage"
            - "--ignore-certificate-errors"
    WPDb:
      dsn: 'mysql:host=db;dbname=db'
      user: 'db'
      password: 'db'
      url: '%WP_URL%'
      tablePrefix: 'wp_'
      dump: 'tests/_data/dump.sql'
      populate: false
      cleanup: false
YML

# 6. Test .env
cat > tests/.env << EOF
WP_ROOT_FOLDER=/var/www/html/web/wp
WP_URL=${SITE_URL}
WP_ADMIN_PATH=/wp/wp-admin
WP_ADMIN_USERNAME=admin
WP_ADMIN_PASSWORD=admin
TEST_DB_HOST=db
TEST_DB_NAME=db
TEST_DB_USER=db
TEST_DB_PASSWORD=db
TEST_TABLE_PREFIX=wp_
EOF

# 7. Support classes
cat > tests/_support/Helper/Acceptance.php << 'PHP'
<?php
namespace Tests\Support\Helper;
use Codeception\Module;

class Acceptance extends Module {}
PHP

cat > tests/_support/AcceptanceTester.php << 'PHP'
<?php
namespace Tests\Support;
use Codeception\Actor;

class AcceptanceTester extends Actor
{
    use _generated\AcceptanceTesterActions;
}
PHP

# 8. Smoke test
cat > tests/acceptance/SmokeTestCest.php << 'PHP'
<?php

use Tests\Support\AcceptanceTester;

class SmokeTestCest
{
    public function frontPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the front page loads without errors');
        $I->amOnPage('/');
        $I->seeElement('body');
        $I->dontSee('Fatal error');
    }

    public function adminLoginWorks(AcceptanceTester $I): void
    {
        $I->wantTo('verify admin login works');
        $I->loginAsAdmin();
        $I->amOnAdminPage('index.php');
        $I->see('Dashboard');
    }
}
PHP

# 9. Restart DDEV
echo "Restarting DDEV to add Selenium container..."
ddev restart

# 10. Build and run
echo "Building Codeception actions..."
ddev exec vendor/bin/codecept build

echo ""
echo "=== Running smoke test... ==="
ddev exec vendor/bin/codecept run acceptance SmokeTestCest --steps

echo ""
echo "=== Done! ==="
echo "Edit tests/.env if your admin credentials differ."
echo "Run tests: ddev exec vendor/bin/codecept run acceptance --steps"
