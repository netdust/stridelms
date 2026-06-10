#!/usr/bin/env bash
#
# Fresh WordPress install + Stride plugin/theme stack for the integration
# suite. Used by .github/workflows/integration.yml.
#
# Local CI simulation (scratch DB, wp_ prefix — mirrors what CI sees):
#   ddev mysql -uroot -proot -e "DROP DATABASE IF EXISTS stride_ci; CREATE DATABASE stride_ci; GRANT ALL ON stride_ci.* TO 'db'@'%';"
#   ddev exec bash -c 'export DB_NAME=stride_ci DB_PREFIX=wp_; bash scripts/ci/install-wp.sh'
#   ddev exec bash -c 'export DB_NAME=stride_ci DB_PREFIX=wp_; composer test:integration'
#
# Exported DB_* env vars win over .env (Bedrock's Dotenv repository is
# immutable — it never overwrites an already-set environment variable).
set -euo pipefail

WP_URL="${WP_URL:-http://localhost:8080}"

# Plugin-local Composer deps: some committed plugins ship their own
# composer.json but gitignore vendor/ (netdust-mail requires its
# vendor/autoload.php unconditionally at load; netdust-lti needs its locked
# deps at runtime). No-op locally where vendor/ already exists.
for dir in web/app/plugins/*/; do
  if [ -f "${dir}composer.json" ] && [ ! -f "${dir}vendor/autoload.php" ]; then
    composer install --no-dev --no-progress --prefer-dist --working-dir="$dir"
  fi
done

wp core install --url="$WP_URL" --title=StrideCI \
  --admin_user=ciadmin --admin_password=ciadmin \
  --admin_email=ci@example.com --skip-email

# Plugins BEFORE theme: activating stridence first fatals — the theme boots
# stride-core's feature services (ntdst/features_ready), which hard-depend on
# plugin-provided services (e.g. ntdst-audit's AuditService).
wp plugin activate sfwd-lms netdust-lti netdust-mail ntdst-assistant ntdst-audit ntdst-auth fluent-crm fluent-smtp
wp theme activate stridence

# LearnDash defers its user-activity table creation to an admin-screen data
# upgrade; without these tables every course-access call spams "table doesn't
# exist" warnings, which the suite's failOnRisky turns red. Trigger the
# upgrade directly (admin context required for the class to load).
wp --context=admin eval '
Learndash_Admin_Data_Upgrades_User_Activity_DB_Table::add_instance();
$inst = Learndash_Admin_Data_Upgrades::get_instance("Learndash_Admin_Data_Upgrades_User_Activity_DB_Table");
if (!$inst) { fwrite(STDERR, "LearnDash data-upgrade instance missing\n"); exit(1); }
$inst->upgrade_db_tables();
echo "learndash user-activity tables created\n";
'
