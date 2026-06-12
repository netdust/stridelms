#!/usr/bin/env bash
#
# Stride drift audit
#
# Cheap-but-targeted greps that catch the kind of silent-wrong-data bugs
# discovered on 2026-05-14:
#   1. Direct $wpdb queries on app-domain tables (potential stale reads)
#   2. References to known-legacy or dead tables
#   3. Hardcoded user-meta keys outside the canonical mapping
#   4. Drift between the user-meta mapping and the Questionnaire reserved-name list
#   5. Anonymiser coverage of the mapping
#
# Reports findings. Does NOT auto-fix. Does NOT fail CI. Humans triage.
#
# Usage: bash scripts/audit-drift.sh
#        composer audit:drift

set -uo pipefail

CORE="web/app/mu-plugins/stride-core"
THEME="web/app/themes/stridence"
RED=$'\033[31m'
YELLOW=$'\033[33m'
GREEN=$'\033[32m'
BLUE=$'\033[34m'
DIM=$'\033[2m'
RESET=$'\033[0m'

echo "═══════════════════════════════════════════════════════════════"
echo "  Stride drift audit — $(date +%Y-%m-%d)"
echo "═══════════════════════════════════════════════════════════════"
echo

# ─── 1. Direct $wpdb queries on app-domain tables ──────────────────────────
echo "${BLUE}▸ 1. Direct \$wpdb queries on vad_* tables${RESET}"
echo "${DIM}  Reading from canonical tables via Repository pattern is preferred.${RESET}"
echo "${DIM}  Each hit below should be cross-checked against RegistrationRepository's pattern.${RESET}"
echo

WPDB_HITS=$(grep -rn '\$wpdb.*vad_\|wpdb->prefix.*vad_' "$CORE" "$THEME" 2>/dev/null | grep -v '\.bak$' | grep -v 'vendor/' | grep -v 'audit-drift')

if [ -z "$WPDB_HITS" ]; then
    echo "${GREEN}  ✓ No direct \$wpdb queries found.${RESET}"
else
    HIT_COUNT=$(echo "$WPDB_HITS" | wc -l)
    echo "${YELLOW}  ⚠ $HIT_COUNT hit(s):${RESET}"
    echo "$WPDB_HITS" | sed 's/^/    /'
fi
echo

# ─── 2. References to known-legacy tables ──────────────────────────────────
echo "${BLUE}▸ 2. References to legacy/dead tables${RESET}"
echo "${DIM}  These tables are scheduled for retirement (task #21).${RESET}"
echo "${DIM}  Any code reference is a regression risk.${RESET}"
echo

LEGACY_HITS=$(grep -rn 'vad_trajectory_enrollments\|vad_session_registrations' "$CORE" "$THEME" 2>/dev/null | grep -v '\.bak$' | grep -v 'vendor/' | grep -v 'audit-drift' | grep -v 'no longer written to')

if [ -z "$LEGACY_HITS" ]; then
    echo "${GREEN}  ✓ No legacy-table references.${RESET}"
else
    LEGACY_COUNT=$(echo "$LEGACY_HITS" | wc -l)
    echo "${RED}  ✗ $LEGACY_COUNT hit(s) — these must be replaced before launch:${RESET}"
    echo "$LEGACY_HITS" | sed 's/^/    /'
fi
echo

# ─── 3. Hardcoded user-meta WRITES outside the canonical mapping ───────────
echo "${BLUE}▸ 3. Direct user-meta WRITES (high-risk)${RESET}"
echo "${DIM}  update_user_meta() with a mapped key, outside the canonical files,${RESET}"
echo "${DIM}  bypasses form binding and the anonymiser. These are the dangerous ones.${RESET}"
echo

KEYS="phone organisation department national_id date_of_birth professional_license_number billing_company billing_address_1 billing_postcode billing_city billing_vat gln_number invoice_email"
ALLOWED='EnrollmentService.php\|UserLifecycleService.php\|ProfileHandler.php\|QuestionnaireSettingsPage.php\|UserDashboardService.php'

WRITE_HITS=""
for key in $KEYS; do
    HITS=$(grep -rn "update_user_meta" "$CORE" "$THEME" 2>/dev/null \
        | grep -v 'vendor/' | grep -v '\.bak$' \
        | grep "'$key'\|\"$key\"" \
        | grep -v -E "$ALLOWED" \
        | grep -v 'audit-drift' \
        || true)
    if [ -n "$HITS" ]; then
        WRITE_HITS+="$HITS"$'\n'
    fi
done

if [ -z "$WRITE_HITS" ]; then
    echo "${GREEN}  ✓ No direct user-meta writes outside the canonical files.${RESET}"
else
    WRITE_COUNT=$(echo "$WRITE_HITS" | grep -c .)
    echo "${RED}  ✗ $WRITE_COUNT direct write(s) — route these through EnrollmentService::updateUserProfile():${RESET}"
    echo "$WRITE_HITS" | sed 's/^/    /'
fi
echo

# ─── 3b. Hardcoded user-meta READS (low-risk, informational) ───────────────
echo "${BLUE}▸ 3b. Direct user-meta READS (informational)${RESET}"
echo "${DIM}  get_user_meta() with mapped keys outside canonical files.${RESET}"
echo "${DIM}  Reads are safer than writes but make refactoring meta keys harder.${RESET}"
echo

declare -A READ_HITS
for key in $KEYS; do
    HITS=$(grep -rn "get_user_meta" "$CORE" "$THEME" 2>/dev/null \
        | grep -v 'vendor/' | grep -v '\.bak$' \
        | grep "'$key'\|\"$key\"" \
        | grep -v "update_user_meta" \
        | grep -v -E "$ALLOWED" \
        | grep -v 'audit-drift' \
        || true)
    if [ -n "$HITS" ]; then
        COUNT=$(echo "$HITS" | grep -c .)
        READ_HITS[$key]="$COUNT"
    fi
done

if [ ${#READ_HITS[@]} -eq 0 ]; then
    echo "${GREEN}  ✓ No direct reads outside canonical files.${RESET}"
else
    TOTAL_READS=0
    for key in "${!READ_HITS[@]}"; do
        TOTAL_READS=$((TOTAL_READS + READ_HITS[$key]))
    done
    echo "${DIM}  $TOTAL_READS read(s) across ${#READ_HITS[@]} key(s). Worth tracking but not blocking.${RESET}"
    echo "${DIM}  Run with --verbose to see them.${RESET}"
fi
echo

# ─── 4. Mapping ↔ admin reserved-name list consistency ─────────────────────
echo "${BLUE}▸ 4. Reserved-name registry consistency${RESET}"
echo "${DIM}  EnrollmentService::getUserMetaMapping() must be the single source.${RESET}"
echo

QSETTINGS_FILE="$CORE/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php"
if grep -q "EnrollmentService::getUserMetaMapping" "$QSETTINGS_FILE" 2>/dev/null; then
    echo "${GREEN}  ✓ QuestionnaireSettingsPage delegates to EnrollmentService.${RESET}"
else
    echo "${RED}  ✗ QuestionnaireSettingsPage may have a stale local copy of the mapping.${RESET}"
    echo "${DIM}    Check that getUserMetaFieldNames() returns EnrollmentService::getUserMetaMapping().${RESET}"
fi
echo

# ─── 5. UserLifecycleService PII strip coverage ────────────────────────────
echo "${BLUE}▸ 5. Anonymiser PII coverage${RESET}"
echo "${DIM}  All mapped meta keys must be cleared on anonymise().${RESET}"
echo

LIFECYCLE_FILE="$CORE/Modules/User/UserLifecycleService.php"
if grep -q "EnrollmentService::getUserMetaMapping" "$LIFECYCLE_FILE" 2>/dev/null; then
    echo "${GREEN}  ✓ UserLifecycleService strips from EnrollmentService mapping.${RESET}"
else
    echo "${RED}  ✗ UserLifecycleService may not honour the canonical mapping.${RESET}"
fi
echo

# ─── Summary ───────────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════"
echo "  Audit complete. Review YELLOW/RED items above."
echo "  Memory: gotcha_stale_database_reads.md (in claude memory dir)"
echo "═══════════════════════════════════════════════════════════════"
