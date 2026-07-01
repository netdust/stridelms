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

# Plugin-local Composer deps: some committed plugins ship their own
# composer.json but gitignore vendor/ (netdust-mail requires its
# vendor/autoload.php unconditionally at load; netdust-lti needs its locked
# deps at runtime). No-op locally where vendor/ already exists.
for dir in web/app/plugins/*/; do
  if [ -f "${dir}composer.json" ] && [ ! -f "${dir}vendor/autoload.php" ]; then
    composer install --no-dev --no-progress --prefer-dist --working-dir="$dir"
  fi
done

wp core install --url=http://localhost:8080 --title=StrideCI \
  --admin_user=ciadmin --admin_password=ciadmin \
  --admin_email=ci@example.com --skip-email

# Pretty permalinks. A bare `wp core install` leaves permalink_structure empty
# (plain ?p= URLs); WP_Post_Type::add_rewrite_rules() only calls add_permastruct
# when `is_admin() || permalink_structure`, so without this the front-end REST
# worker never builds the /edities/%vad_edition% permastruct and get_permalink()
# falls back to ?vad_edition=<slug>. Set the structure WITHOUT flushing here —
# the CPT does not exist yet (it boots via the theme below), so a flush now would
# persist rewrite_rules with no `edities` rules. DDEV provisions this for us; raw
# CI install does not. Real-HTTP seam tests (CatalogEndpointTest) assert /edities/.
wp option update permalink_structure '/%postname%/'

# Site language = nl_BE. Stride's UI is Dutch and the acceptance suite asserts
# Dutch strings (e.g. AuthPluginCest expects "Link ongeldig" / "E-mailadres" /
# "inloglink"). A bare `wp core install` defaults to en_US, so plugin/theme .mo
# files (committed, e.g. ntdst-auth/languages/ntdst-auth-nl_BE.mo) are never
# loaded and the English SOURCE strings render → those Cests fail. DDEV runs
# nl_BE, which is why this is green-local / red-CI. Install the core language
# pack (non-fatal if the download is unavailable) then pin the locale so
# load_plugin_textdomain() resolves the nl_BE .mo files.
wp language core install nl_BE || true
wp site switch-language nl_BE || wp option update WPLANG nl_BE

# Plugins BEFORE theme: activating stridence first fatals — the theme boots
# stride-core's feature services (ntdst/features_ready), which hard-depend on
# plugin-provided services (e.g. ntdst-audit's AuditService).
wp plugin activate sfwd-lms netdust-lti netdust-mail ntdst-assistant ntdst-audit ntdst-auth fluent-crm fluent-smtp
wp theme activate stridence

# Hard-flush AFTER the theme is active: stride-core registers vad_edition (and
# its `edities` rewrite) only once the theme boots, so the persisted rewrite_rules
# option must be rebuilt now — the front-end REST worker that renders catalog
# cards reads those persisted rules (is_admin() is false there, so it cannot
# rebuild the permastruct in-memory). Flushing before activation misses the CPT.
wp rewrite flush --hard

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
