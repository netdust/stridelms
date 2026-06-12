# Shake-out Manifest #4 — 2026-05-16

Scope: auth flows (register, magic link, activation, password), GDPR export+erase, admin action queue data correctness, cron + scheduled events.

**Status: PHASE 3 COMPLETE.** 4 bugs found; 3 fixed, 1 deferred per user. Unit 706/706 + Integration 256/256.

| ID | Severity | Status |
|----|----------|--------|
| B4-001a | CRITICAL | ✅ Fixed — full GDPR exporter (registrations + quotes + attendance) registered in UserLifecycleService |
| B4-001b | CRITICAL | ✅ Fixed — erase button now sends admin email + audit log + dispatches stride/gdpr/erasure_requested. No more silent WP privacy flow that only wiped 3 meta keys. |
| B4-002 | IMPORTANT | ⏸ DEFERRED post-launch per user decision (NAT-DoS risk acceptable for v1) |
| B4-003 | MINOR | ✅ Fixed — 21 orphan scheduled actions removed; `scripts/cleanup-orphan-cron.php` for idempotent re-run |
| B4-004 | MINOR | ✅ Fixed — `scripts/cleanup-test-residue.php` extended for vad_quote; 206 residue posts removed (196 quotes + 10 vouchers) |

---

## Bugs found

### CRITICAL

#### B4-001 — User-initiated GDPR erasure leaves Stride data behind

**Symptom:** A user requests account erasure via `/mijn-account/?tab=profiel` (handleGdprErase → wp_create_user_request → confirmation email → WP privacy tools). When the request is processed, only **ntdst-auth's ConsentHelper::eraseUserData** runs, which deletes exactly 3 meta keys:

- `_ntdst_consent`
- `_ntdst_activated`
- `_ntdst_activated_at`

Everything else stays. The user's wp_users row, registrations in `stride_vad_registrations`, quotes in `vad_quote` posts, audit log entries, completion task data, attendance records — all still tagged with their user_id and full PII.

**Real-world impact:** AVG/GDPR compliance gap. VAD as a Belgian non-profit is subject to GDPR right-to-erasure. A "I want my account deleted" request currently looks like it succeeded (3 meta keys gone) while the database keeps the user, their enrollments, their billing data, their audit trail.

**Existing infrastructure:** `Stride\Modules\User\UserLifecycleService::anonymise()` does the right thing — strips wp_users PII, renames user_login, blanks email, strips mapped user_meta. It's only callable from admin (manual row action). **Not hooked to WP's privacy_personal_data_erasers filter.**

**Fix proposal:**
1. Register a Stride privacy eraser via `add_filter('wp_privacy_personal_data_erasers', ...)` in UserLifecycleService.
2. Callback invokes the existing `anonymise()` method.
3. Add a privacy exporter too — currently the export only includes consent + activation, missing the user's enrollment / quote / attendance history.

**Files:**
- `web/app/mu-plugins/stride-core/Modules/User/UserLifecycleService.php` (add hook + exporter callback)
- `web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php` (no change — the wp_create_user_request flow is correct; the missing piece is the eraser registration)

---

### IMPORTANT

#### B4-002 — Login rate limit is IP-scoped, NAT users share the bucket

**Symptom:** `AuthService::loginWithPassword` rate-limits on `login_ip_<IP>` — 5 attempts per 15 minutes per IP. The IP is the **shared NAT** IP for office / school networks.

**Real-world impact:** A VAD member organisation with 50 staff on one office IP. Three users mistype, two log in normally — bucket exhausted. The next legitimate user gets `rate_limited` with no recourse other than wait 15 minutes or use mobile data.

**Status in production:** **acceptable risk for v1** — VAD members log in ad-hoc, not 50-tegelijk. But the failure mode is silent: blocked users see a generic error and have no way to know why. Manifest note for post-launch hardening.

**Fix proposal (post-launch):** combine `login_ip_<IP>` AND `login_email_<email>` — block when either exceeds threshold. Per-user bucket adds a second layer that doesn't suffer from NAT collisions.

**Files:**
- `web/app/plugins/ntdst-auth/src/AuthService.php` line 221-226

---

### MINOR

#### B4-003 — 21 orphan failed scheduled actions for removed hook

**Symptom:** `stride_actionscheduler_actions` has 21 entries with `status='failed'` for hook `stride/quote/lock_approaching_editions`. That hook was dropped from v1 (LAUNCH-CHECKLIST §B "OGM + cron dropped from v1") but the recurring schedule wasn't unscheduled — Action Scheduler keeps trying, finds no listener, marks failed.

**Impact:** zero functional — no data loss, no user-facing error. Just clutter in the admin "Scheduled Actions" UI.

**Fix proposal:** one-shot cleanup script: `wp action-scheduler clean --status=failed --hook=stride/quote/lock_approaching_editions`, plus an `add_action('plugins_loaded', fn() => wp_unschedule_hook('stride/quote/lock_approaching_editions'))` defensive unschedule in stride-core that runs once per deploy.

---

#### B4-004 — Stale quote inventory (205 quotes, 170 from before April)

**Symptom:** 205 `vad_quote` posts in dev DB, 170 dated before 2026-04-01. Action queue surfaces "160 quotes wachten op actie" on admin dashboard — most are dev / test residue.

**Production impact:** none — only dev DB. **But on launch the seed-data inventory plus any acceptance-test runs need a cleanup pass before going live.**

**Fix proposal:** add `vad_quote` filtering to existing `scripts/cleanup-test-residue.php` (currently only handles edition + voucher + session). Delete drafts older than seed-data baseline date.

---

## NOT bugs (investigated, false alarms)

- **Magic link token expiry "missing"** — initial sweep flagged this. I'd set `set_transient(..., 3600)` manually then mutated `created` to 1 hour ago. WP transient TTL hadn't fired yet. In production, `createMagicLinkToken` sets TTL=`expiry * MINUTE_IN_SECONDS` (15 min) so WP transient auto-expires correctly. False alarm.
- **Action queue tabs all return 0 items** — my sweep script read `$data['items']` but the API returns a flat array of items. Real response had 3 items on tab=mine including the pending approval correctly surfaced.
- **AuthService.requestMagicLink** — defensive: same response message for existing + non-existing email. No user enumeration leak.
- **Login wrong-password vs non-existing-email** — same `invalid_credentials` error code + message. No enumeration leak.
- **Unactivated user login attempt** — correctly rejected with `not_activated`.
- **Stride does not register periodic crons of its own** — confirmed via `wp cron event list`. Only one-off `stride/mail/admin_notify_async` per enrollment. By design — Stride is event-driven, not poll-driven. Healthy.

---

## Summary

| Severity | Count | Phase 1 launch blocker? |
|----------|-------|-------------------------|
| CRITICAL | 1 | **Yes** — B4-001 GDPR erase incompleet, AVG compliance gap |
| IMPORTANT | 1 | B4-002 acceptable risk, post-launch hardening |
| MINOR | 2 | No — defer / one-shot cleanup |

**Phase 3 order:**

1. **B4-001** — Register Stride privacy eraser + exporter, hook anonymise() to WP privacy flow
2. **B4-003** — Cleanup orphan scheduled actions + defensive unschedule
3. **B4-002** — Defer post-launch (manifest only, no code change)
4. **B4-004** — Add to existing cleanup script; run before launch
