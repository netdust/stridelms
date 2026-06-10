#!/usr/bin/env bash
#
# Stride architecture-invariant checks
#
# Mechanical bypass detection for the convergence points named in
# ARCHITECTURE-INVARIANTS.md. This is the CI-enforced sibling of the
# report-only scripts/audit-drift.sh.
#
# BLOCKING (exit 1, fails CI):
#   INV-1  REST route with no permission_callback — fails open. Zero tolerance.
#
# ADVISORY (reported, never blocks — humans triage):
#   INV-2  Raw wp_ajax_* handler (must hand-roll its own nonce)
#   INV-3  $wpdb outside a repository (+ the four justified exceptions)
#   INV-5  Plugin calling theme helpers (stridence_* / stride_format_* /
#          stride_enrollment_url) — must be empty; flips BLOCKING after Task C2
#   INV-6  Direct LearnDash calls outside the adapter + helper
#
# Usage: bash scripts/check-invariants.sh
#        composer check:invariants
#
# Reference: ARCHITECTURE-INVARIANTS.md (project root)

set -uo pipefail

CORE="web/app/mu-plugins/stride-core"
RED=$'\033[31m'
YELLOW=$'\033[33m'
GREEN=$'\033[32m'
BLUE=$'\033[34m'
DIM=$'\033[2m'
RESET=$'\033[0m'

# Resolve to repo root so the script works from anywhere (CI, hooks, cwd).
cd "$(dirname "$0")/.." || exit 2

FAIL=0

echo "═══════════════════════════════════════════════════════════════"
echo "  Stride architecture-invariant checks — $(date +%Y-%m-%d)"
echo "  Reference: ARCHITECTURE-INVARIANTS.md"
echo "═══════════════════════════════════════════════════════════════"
echo

# ─── INV-1 (BLOCKING): every REST route declares a permission_callback ──────
# A register_rest_route() arg block with no permission_callback fails OPEN —
# WordPress treats a missing callback as publicly accessible. This is the one
# invariant we hard-block on.
echo "${BLUE}▸ INV-1  REST routes must declare permission_callback ${DIM}(BLOCKING)${RESET}"

# Per-file check: a file must have at least as many permission_callback
# declarations as register_rest_route() calls. Fewer = at least one route
# registered without a guard, which WordPress treats as public.
INV1_REPORT=""
while IFS= read -r f; do
  [ -z "$f" ] && continue
  routes=$(grep -c "register_rest_route(" "$f")
  perms=$(grep -c "permission_callback" "$f")
  if [ "$routes" -gt "$perms" ]; then
    INV1_REPORT+="  ${RED}✗ $f${RESET} — $routes route(s), only $perms permission_callback(s)\n"
    FAIL=1
  fi
done < <(grep -rl "register_rest_route" --include="*.php" "$CORE" 2>/dev/null)

if [ -n "$INV1_REPORT" ]; then
  echo -e "$INV1_REPORT"
  echo "  ${DIM}Every register_rest_route() MUST pass permission_callback. A missing one${RESET}"
  echo "  ${DIM}defaults to public. Route to canViewAdmin/canManageAdmin/checkPermission.${RESET}"
else
  echo "  ${GREEN}✓ Every REST route has a permission_callback.${RESET}"
fi
echo

# ─── INV-2 (advisory): raw wp_ajax_* handlers ──────────────────────────────
echo "${BLUE}▸ INV-2  Raw wp_ajax_* handlers ${DIM}(advisory — each must verify its own nonce)${RESET}"
INV2=$(grep -rn "add_action('wp_ajax" --include="*.php" "$CORE" 2>/dev/null)
if [ -n "$INV2" ]; then
  echo "$INV2" | sed "s/^/  ${YELLOW}•${RESET} /"
  echo "  ${DIM}Frontend AJAX should be an ntdst/api_data/* filter (framework verifies the nonce).${RESET}"
  echo "  ${DIM}Raw wp_ajax_* is acceptable only with its own check_ajax_referer + capability check.${RESET}"
else
  echo "  ${GREEN}✓ No raw wp_ajax_* handlers — all frontend AJAX flows through ntdst/api_data.${RESET}"
fi
echo

# ─── INV-3 (advisory): $wpdb outside repositories ──────────────────────────
echo "${BLUE}▸ INV-3  \$wpdb outside repositories ${DIM}(advisory — minus 3 justified files)${RESET}"
# Exclude: repositories (the convergence point), the 3 justified service files,
# and *Table.php schema classes (they OWN the CREATE TABLE / dbDelta definition).
INV3=$(grep -rln '\$wpdb->' --include="*.php" "$CORE" 2>/dev/null \
  | grep -vE "Repository\.php|Table\.php" \
  | grep -vE "EditionService|BatchQueryHelper|EditionFilesZipExporter")
if [ -n "$INV3" ]; then
  echo "$INV3" | sed "s/^/  ${YELLOW}•${RESET} /"
  echo "  ${DIM}New \$wpdb against a domain table belongs in the owning repository.${RESET}"
  echo "  ${DIM}Justified exceptions (not flagged): EditionService, BatchQueryHelper, EditionFilesZipExporter.${RESET}"
else
  echo "  ${GREEN}✓ No new \$wpdb callers outside repositories.${RESET}"
fi
echo

# ─── INV-5 (advisory but should be empty): plugin → theme calls ────────────
# Pattern covers ALL procedural helpers the theme defines (sibling-sweep
# 2026-06-10 over themes/stridence/helpers/*.php + functions.php + templates):
#   stridence_*           — theme namespace prefix (incl. template-local helpers)
#   stride_format_*       — stride_format_date / stride_format_money (helpers/formatting.php)
#   stride_enrollment_url — helpers/formatting.php
# Re-run that sweep and extend this pattern whenever a theme helper is added.
#
# KNOWN VIOLATIONS (4): stride_format_date called from NotificationMapper:139
# and StrideMailBridge:223,759,760 (audit finding H-6). ADVISORY until Task C2
# moves stride_format_date into stride-core — then this section flips to
# BLOCKING (set FAIL=1 on hits).
echo "${BLUE}▸ INV-5  Plugin must not call theme helpers ${DIM}(advisory — flips BLOCKING after Task C2)${RESET}"
INV5=$(grep -rn "stride_format_\|stride_enrollment_url\|stridence_" --include="*.php" "$CORE" 2>/dev/null)
if [ -n "$INV5" ]; then
  echo "$INV5" | sed "s/^/  ${YELLOW}•${RESET} /"
  echo "  ${DIM}stride-core must never call theme helpers — inverts the dependency arrow.${RESET}"
  echo "  ${DIM}Move the helper/partial into stride-core and render via ntdst_response()->html().${RESET}"
  echo "  ${DIM}Known 4 stride_format_date sites are scheduled for Task C2; advisory until then.${RESET}"
else
  echo "  ${GREEN}✓ stride-core calls no theme helpers.${RESET}"
fi
echo

# ─── INV-6 (advisory): direct LearnDash calls ──────────────────────────────
echo "${BLUE}▸ INV-6  LearnDash touched only via the adapter/helper ${DIM}(advisory)${RESET}"
# Exclude: the adapter + helper (the convergence point), add_action() hook
# registrations (subscribing to an LD event is not calling LD), and comments.
INV6=$(grep -rn "learndash_\|ld_update_course_access\|sfwd_" --include="*.php" "$CORE" 2>/dev/null \
  | grep -vE "Integrations/LearnDash/(LearnDashService|LearnDashHelper)\.php" \
  | grep -vE "add_action\(|add_filter\(" \
  | grep -vE "^\s*[^:]+:[0-9]+:\s*(\*|//|#)")
if [ -n "$INV6" ]; then
  echo "$INV6" | sed "s/^/  ${YELLOW}•${RESET} /"
  echo "  ${DIM}Mutations go through LMSAdapterInterface; reads through LearnDashHelper.${RESET}"
else
  echo "  ${GREEN}✓ No direct LearnDash calls outside the adapter + helper.${RESET}"
fi
echo

# ─── Summary ───────────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════════════════"
if [ "$FAIL" -ne 0 ]; then
  echo "  ${RED}FAIL — a blocking invariant (INV-1) is violated. See above.${RESET}"
  echo "  Advisory (YELLOW) items are reported but do not fail CI."
  echo "═══════════════════════════════════════════════════════════════"
  exit 1
fi
echo "  ${GREEN}PASS — no blocking invariant violations.${RESET}"
echo "  Review any YELLOW advisory items above."
echo "═══════════════════════════════════════════════════════════════"
exit 0
