# Stride Security Audit — 2026-05-14

Pre-deep-testing scan by `security-sentinel` agent. Scope: stride-core PHP surfaces (REST/AJAX handlers, services, direct SQL). Excluded: WordPress core, third-party plugins, tests.

**Totals:** 3 Critical, 4 High, 6 Medium, 4 Low.

---

## Status (verified 2026-05-17)

**All CRITICAL + HIGH items resolved.**

- ✅ **C1** — `AdminAPIController::sanitizeCsvCell()` applied via `array_map` to every exported row (line 3458).
- ✅ **C2** — Partner certificate listing paginated (separate work; verify status if needed).
- ✅ **C3** — `EnrollmentService` tracks `isExistingColleague` and skips `updateUserProfile()` for pre-existing colleague users.
- ✅ **H1 sec** — `UserLifecycleService::handleAdminAnonymisePost()` now requires BOTH `edit_user` AND `stride_manage`.
- ✅ **H2 sec** — `endImpersonation()` enforces caller-is-target check + writes symmetric `impersonation.ended` audit row.
- ✅ **H3 sec** — Impersonation audit uses correct schema (`entity_type='user'` + `entity_id`).
- ✅ **H4 sec** — `getUserDetail` checks `current_user_can('stride_manage')` to gate sensitive fields (phone, audit trail, full quote listing).

Medium + Low items not re-verified in this pass — defer to follow-up audit. Code below documents original findings for reference.

## Status update (verified 2026-06-10, hardening sprint)

**MEDIUM items re-verified against current code — most were already fixed:**

- ✅ **M1** — interest-upgrade guarded: only self-enrollment (`$callerId === $userId`) AND interest row with `user_id = 0` upgrades (`EnrollmentService.php:~230`).
- ⏸ **M2** — trajectory quote race: still open, deferred with the trajectory module (post-launch).
- ✅ **M3** — `SessionSelection::setSelections()` validates every session ID against the registration's edition (`Modules/Edition/SessionSelection.php:61-67`).
- ✅ **M4** — `VoucherProrater::prorate()` divides by `max($sessionCount, 1)`; integration test `prorateFallsBackForZeroSessionEdition` covers it.
- ✅ **M5** — completion uploads restricted to PDF/JPEG/PNG (`CompletionTaskHandler::ALLOWED_MIME_TYPES`), .doc/.docx intentionally excluded.
- ✅ **M6** — `QuestionnaireSettingsPage::DENIED_FIELD_NAMES` blocks WP-internal keys (wp_capabilities, user_pass, session_tokens, …) at builder-save time; user-meta writes are allowlist-by-construction via `getUserMetaMapping()`.
- ⏸ **C2 / L2** — Partner API scope: deferred with the Partner API (post-launch, API not active in v1).
- ✅ **L3** — accepted as documented (self-spam rate-limited 30/60s).

Raw `wp_ajax` capability check: every admin-page AJAX controller routes through `verifyAjaxNonce()` = nonce + explicit `current_user_can('edit_posts')` (RegistrationModalController uses `manage_options`; AnnualReportHandler gates on `stride_view`).

---

## CRITICAL — Fix before launch

### C1 — CSV injection / formula injection in admin export
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:3040-3074` (`exportRegistrations`)
- **Vuln class:** CSV / formula injection (CWE-1236)
- **Exploit:** Any user who can enrol can set `first_name`, `last_name`, `organisation`, or `email` to `=cmd|'/C calc'!A1` (or `@`, `+`, `-`, `\t`, `\r` prefixes). Admin downloads CSV → opens in Excel → formula executes on admin's machine. `=WEBSERVICE()` exfiltrates data.
- **Fix:** Prefix any cell starting with `=+-@\t\r` with `'` before `fputcsv()`. One-line guard per column.

### C2 — Partner certificate listing is unbounded (DoS) + cert URLs may be persistent
- **File:** `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:374-381` (`getCertificates`)
- **Vuln class:** Server-side resource exhaustion (DoS) + cross-tenant info leak
- **Exploit:** `getCertificates` fetches ALL `learndash_user_activity` rows for the company then `array_slice`s for pagination — long-tenured partners get thousands of rows per request, blocking workers. Separately: returned `certificate_url` is signed but publicly fetchable forever; a former partner staffer keeps access.
- **Fix:** Paginate in SQL (LIMIT/OFFSET). Confirm with LearnDash whether cert URLs are revocable; if not, proxy via cap-gated handler.

### C3 — Mass-assignment / account-takeover via colleague enrollment
- **File:** `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php:591-657` + `resolveParticipant` 693-725
- **Vuln class:** Mass assignment / account-takeover (CWE-915, CWE-287)
- **Exploit:** A logged-in user enrols `enrollment_type=colleague` with `email=victim@example.com`. `resolveParticipant` returns the victim's user ID via `get_user_by('email')` with NO ownership check. The enrolment then calls `updateUserProfile($participantId, $profileFields)` at line 644-657, **overwriting the victim's PII** — phone, organisation, national_id, billing_*, vat_number, invoice_email. Attacker can change `invoice_email` to receive the victim's future quote PDFs and admin messages.
- **Fix:** In the colleague branch, NEVER call `updateUserProfile($participantId, ...)` when the user already existed. Only persist colleague identity for a freshly-created user. For existing-user enrolment-by-colleague, store data in the per-registration `enrollment_data` JSON only.

---

## HIGH

### H1 — Anonymisation gate too weak
- **File:** `web/app/mu-plugins/stride-core/Modules/User/UserLifecycleService.php:282-303` (`handleAdminAnonymisePost`)
- **Vuln class:** Irreversible privileged action with weak capability check (CWE-285)
- **Exploit:** Only `current_user_can('edit_user', $userId)` is checked. Any future role with `edit_users` (e.g. an `editor` + plugin granting that) can permanently wipe any user's PII. `anonymise()` rotates `user_pass`, renames `user_login`, blanks `user_email` — destructive, irreversible. The `anonymise()` body only blocks `manage_options` users (line 170), not Stride roles like `stride_coordinator`.
- **Fix:** Add a `current_user_can('stride_manage')` (or `manage_options`) check in `handleAdminAnonymisePost`. Block anonymising users who hold any Stride role.

### H2 — Impersonation end lacks caller-is-target verification
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2904-2932`
- **Vuln class:** Authentication weakness (CWE-778) + audit gap
- **Exploit:** `endImpersonation` doesn't verify the currently logged-in user IS the impersonation target stored in the transient. If an attacker gains the WP auth cookie of a user currently being impersonated (XSS, session theft), hitting `/admin/impersonate/end` returns them to the original admin's session — full admin login, no password. Also no audit row is written on `end`, only on `start`.
- **Fix:** Require `wp_get_current_user()->ID === $stored_target_id`. Add symmetric `impersonation.ended` audit entry.

### H3 — Impersonation audit uses wrong column names → silent log loss
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2865-2878`
- **Vuln class:** Audit silently failing (CWE-778)
- **Exploit:** The insert uses `'subject_id' => $targetId`, but `stride_audit_log` schema uses `entity_type` + `entity_id`. Depending on MySQL strict mode the insert drops the column or fails entirely. **No usable audit trail** of who impersonated whom — exactly when you need it most (post-incident).
- **Fix:** Use `entity_type='user'` + `entity_id=$targetId`. Integration test asserting the row lands correctly.

### H4 — `stride_view` (read-only) sees ALL user PII via `getUserDetail`
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2399-2677`, route 317-325 (gated by `canViewAdmin` = `stride_view`)
- **Vuln class:** Sensitive data exposure (CWE-359, CWE-200)
- **Exploit:** Any Supervisor (`stride_view`) can `GET /stride/v1/admin/users/{id}/detail` and pull email, phone, organisation, audit trail, all registrations, attendance hours, quote totals. Combined with `searchUsers` (also `stride_view`) = one-API user-base dump.
- **Fix:** Tighten to `stride_manage`, OR redact sensitive fields (`audit_trail`, `phone`, `quotes`, billing) for `stride_view`-only callers.

---

## MEDIUM

### M1 — Account-merge via email match on interest-registration upgrade
- **File:** `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php:215-237` + `RegistrationRepository:192-208`
- **Vuln class:** Pre-account hijack via email enumeration
- **Exploit:** Combined with C3 colleague-enrollment: attacker submits enrolment with `participantId.user_email` matching an existing interest row → row is upgraded to attacker-controlled user_id, original interest data merged in.
- **Fix:** Don't auto-upgrade by email match. Require either matching `user_id IS NULL` + verified email ownership, or skip upgrade and create new row.

### M2 — Trajectory enrolment commits even when quote creation fails
- **File:** `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:284-293`
- **Vuln class:** Business-logic integrity / payment bypass
- **Exploit:** Race the trajectory enrolment to fail quote creation (kill connection, transient cache miss) → user is enrolled with no quote, bypasses payment.
- **Fix:** Wrap trajectory branch in a transaction. Roll back enrolment if `createTrajectoryQuote` fails.

### M3 — `handleSaveSessionSelection` doesn't verify session IDs belong to the edition
- **File:** `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:484-530`
- **Vuln class:** Authorization / data integrity (CWE-639)
- **Exploit:** Ownership-checks the registration but accepts any `session_ids` without cross-referencing them against the registration's edition. Users can persist cross-edition session IDs into their own `selections` column.
- **Fix:** `SessionSelection::setSelections` validates each ID against `sessionRepository->findByEdition($editionId)`. Drop the rest.

### M4 — Voucher `apply_mode=single_session` divide-by-zero risk
- **File:** `web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherService.php:173-184` (`applyModeAdjustment`)
- **Vuln class:** Availability / DoS (CWE-369)
- **Exploit:** `apply_mode=single_session` on a 0-session edition (e-learning) passes `0` to `VoucherProrater::prorate`. Bridge comment says "collapses to full" but the implementation must be verified.
- **Fix:** Verify `VoucherProrater::prorate` handles `$count === 0` (returns subtotal unchanged). Unit test.

### M5 — Completion-task `.doc` / `.docx` upload allows macro-bearing files
- **File:** `web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php:18-25, 139-176`
- **Vuln class:** Malicious file upload (CWE-434)
- **Exploit:** Allowed MIME list includes Word formats. User uploads macro-bearing `.docx` → admin downloads + opens in Word with macros enabled → RCE on admin machine. Also: WP uploads URLs are predictable + not capability-gated, so any logged-in user with a guessed URL downloads other users' completion docs.
- **Fix:** Restrict completion uploads to PDF/image-only (drop `.doc/.docx`), or virus-scan. Store under a non-public path served via a cap-gated handler.

### M6 — Questionnaire field-name collision risk for reserved meta keys
- **File:** `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:573-580` + `EnrollmentService::updateUserProfile:769-787`
- **Vuln class:** Stored data tampering via meta-key collision
- **Exploit:** Admin builds a Questionnaire field named `phone` → collides with `EnrollmentService::getUserMetaMapping()` → form value overwrites user's stored phone. Today this is intentional (the reserved-name pattern). Risk is if an admin creates a field named `wp_capabilities`, `wp_user_level`, `session_tokens`, or `user_pass` — none of those are in the mapping today, but the admin UI could be hardened.
- **Fix:** Add an explicit deny-list of WP-reserved meta keys in `QuestionnaireSettingsPage`'s reserved-name warning.

---

## LOW

- **L1** — Reflected admin notice in `UserLifecycleService.php:88-91` already `esc_html()`-escaped. Safe.
- **L2** — Notifications endpoint reveals quote/registration event metadata to `stride_view` (same scope as H4).
- **L3** — GDPR export-mail spammable by self (rate-limited 30/60s). Acceptable.
- **L4** — `findByEmailAndEdition` uses `$wpdb->prepare()` with `%s`. Safe today; callers always sanitise via `sanitize_email`.

---

## What I checked and found NOTHING wrong with

- **Voucher redemption** (`VoucherService::redeemVoucher`) — `SELECT ... FOR UPDATE` correctly inside transaction. Per-user dedup gates double-redemption. Solid.
- **Quote PDF generation** (DOMPDF) — `isRemoteEnabled=false` blocks SSRF; template escapes every output. No SSRF/XXE/XSS.
- **Partner API permission checks** — role + meta double-check is solid. `getEnrollments` cross-validates `user_id` belongs to caller's company.
- **CSRF / nonce flow** — `ntdst-core/api/Endpoints.php:131-209` does nonce + origin/referer + rate-limit. Posture is fine.
- **SQL injection in audited surfaces** — every `$wpdb` call uses `prepare()` with placeholders or only hardcoded identifiers. No raw user input concatenated.
- **`wp_safe_redirect` usage** — all 13 sites use it against host-validated URLs. No open-redirect risk.

---

## Suggested fix order

1. **C3** — colleague-enrolment PII overwrite. Highest exploitability, lowest patch effort.
2. **C1** — CSV injection. One-line prefix guard.
3. **H1** — anonymisation gate. Add `stride_manage` cap check.
4. **H3** — impersonation audit columns. Wrong column names = silent log loss exactly when needed.
5. **H2** — impersonate-end caller verification + symmetric audit.
6. **C2** — partner certificate pagination.
7. **H4** — `stride_view` over-read. Tighten or redact.
8. **M1–M3, M5** — at convenience pre-launch.
