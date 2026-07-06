# Demo Persona Seed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the declarative seed matrix with one fully-populated demo student so that logging in at https://stride.ddev.site/mijn-account/ visibly demonstrates EVERY major user-facing dashboard surface (enrollment + pending task, certificate, notifications, quote, trajectory).

**Architecture:** Two new builder capabilities (certificate issuance + audit-derived notifications) plus two new matrix vocabulary shapes (`certificate` on a registration, a `parent trajectory enrollment`), consolidated onto the existing empty-slot user `seed_completed_user`. The notification builder routes through the product's own write path (`AuditService::record()`), and the certificate builder marks LearnDash genuinely complete (`LMSAdapterInterface::markComplete`) after assigning a certificate post — so both surfaces are derived by the real product code, not faked at the read layer.

**Tech Stack:** PHP 8.3, WordPress (Bedrock), LearnDash, NTDST Core, the declarative seed system (`scripts/seed/{matrix,builders,runner}.php`), `ntdst-audit` plugin.

## Global Constraints

- **Dev-only tooling.** The seeder is gated to `WP_ENV ∈ {development, local}` (current DDEV DB is `development`). No production code path changes.
- **Idempotent / re-runnable.** Every builder dedupes by natural key; the runner reconstructs the manifest on each run. New builders MUST be idempotent the same way (pre-check before create). Reseed is `ddev exec wp eval-file scripts/seed.php` — NO unseed required.
- **EUROS in matrix, CENTS in storage.** Prices declared in the matrix are human EUROS; edition/quote storage is canonical cents (the builder ×100 on edition create; quote reads stored cents directly). Do not introduce a third unit.
- **Demo persona login:** `seed_completed_user` / `seed_completed_user@seed.test`, password `seedpass123`, role `subscriber`. All surfaces consolidate onto THIS user.
- **Audit write path is a convergence point (INV-3 sub-invariant for the audit table).** Notifications MUST be created via `NTDST\Audit\AuditService::record()` — never a raw `$wpdb->insert` into `wp_audit_log`. See § Architecture invariants.
- **No new product files.** All changes live under `scripts/seed/` and `scripts/seed-verify.php`. The seed builders are the only place that may call services/repositories/LD directly (they are dev scripts outside `stride-core`).

---

## Work classification

**Class A** (new multi-task change extending the seed matrix + builders + verifier). Stage 0 brainstorm skipped — approach pinned with Stefan (extend the declarative matrix, reseed). Stages firing: Stage 1 (this plan + gates) → Stage 2 (execute) → Stage 3 (run seeder + browser acceptance pass + finish).

## Gate dispositions (which fired, which did not, and why)

| Gate | Fires? | One-line reason |
|---|---|---|
| **1a Threat-modeling** | **NO** | Dev-only seed tooling, env-gated to dev/local; takes NO user input (matrix is a static PHP literal authored by us); writes only internal tables (`wp_audit_log`, `wp_vad_registrations`, postmeta) via the product's own sanitizing write paths; no new AJAX/REST/shortcode/URL/upload/credential surface. Ran the trigger list literally — zero matches. No `## Threat model` embedded. |
| **1b Architecture-invariants** | **YES** | The notification builder touches the **audit-write convergence** and **INV-3** (registration data access); the certificate builder touches **INV-6** (LearnDash boundary). Cited in § Architecture invariants below. |
| **WP plan-requirements (stack override)** | **YES (scoped)** | The seeder is PHP that writes data, so the layering blocks apply — but there are NO new data-flows (no AJAX/REST/form/shortcode). Block 1 (per-flow security) is N/A and stated so; Block 2 (layering) applies to the new builder methods. See § WP requirements. |
| **1c Spec-premise ground-truth** | **DONE** | Read all five premise sources before writing task code. **One premise corrected** (trajectory enrollment shape) — see § Ground-truth findings. |
| **1g Feature-acceptance** | **YES** | The deliverable IS a set of user-facing dashboard surfaces; the only meaningful verification is driving the real dashboard. `## Acceptance flows` matrix embedded. |
| **1f / 1h Review sizing + tier** | **YES** | 5 tasks → 2 review clusters with `── REVIEW GATE ──` markers, both provisional tier **STANDARD** (no 1a surface; dev tooling). See markers inline. |

---

## Ground-truth findings (1c — read the source, corrected the premise)

All five premise sources were read. Confirmed signatures and **one correction**:

1. **`AuditService::record()` — CONFIRMED.**
   `web/app/plugins/ntdst-audit/src/AuditService.php:58`
   ```php
   public function record(string $entityType, int $entityId, string $action,
       ?int $actorId = null, array $context = []): int|WP_Error
   ```
   - Subject lives in `context['user_id']`; a STORED generated column `subject_user_id` is derived from it.
   - After insert, fires `do_action('ntdst/audit/recorded', $action, $entityType, $entityId, $context, $actorId)` → `NotificationService::onAuditRecorded()` busts that user's unread-count transient. Raw `$wpdb->insert` would skip this hook AND `AuditRepository::insert`'s sanitization → stale badge + bypass. **This is why record() is mandatory.**

2. **Notification subject/actor rule — CONFIRMED & SHARPENED** (`AuditRepository::findBySubjectUser`, `NotificationService::getNotifications`, `NotificationMapper`):
   - The read UNIONs two branches:
     - **Branch 1** (`registration.*`, `attendance.*`, `session.note_updated`): `subject_user_id = STUDENT AND (actor_id IS NULL OR actor_id != STUDENT)`. → record with `context['user_id'] = STUDENT` and `actorId = admin` (NOT the student).
     - **Branch 2** (`completion.*` only): `actor_id = STUDENT AND action LIKE 'completion.%'`. → record `completion.*` with `actorId = STUDENT`.
   - `NotificationMapper` only renders these actions (others → blank title → dropped): `registration.created`, `registration.cancelled`, `attendance.marked_present|absent|excused`, `completion.course_completed|completed|attendance_complete|certificate_issued`, `session.note_updated`. `EXCLUDED_ACTIONS = ['mail.sent']`.
   - `mapCertificateIssued` reads `context['course_title']`, `context['course_id']`, `context['certificate_link']`.

3. **Certificate path — CONFIRMED, with a build requirement found.**
   - `tab-certificaten.php:69` reads `LearnDashHelper::getCertificateLink($course_id, $user_id)`.
   - `LearnDashHelper::getCertificateLink` (`:487`) returns `''` unless `isComplete()` is true (`learndash_course_completed`) **AND** `learndash_get_course_certificate_link` returns a link — which requires a **certificate post assigned to the course** (`_sfwd-courses['sfwd-courses_certificate']`, read by `hasCertificate()` :505).
   - **Probe result: `wp post list --post_type=sfwd-certificates` = 0.** No certificate posts exist. **The builder MUST create a `sfwd-certificates` post and assign it to the demo course**, else the Download-PDF button never appears.
   - Two routes onto the Certificaten tab: (a) edition reg with `RegistrationStatus::Completed` (the demo persona uses this), or (b) an online course (`stride_format` ∈ online/e-learning/webinar) with LD `isComplete`. Route (a) is what we drive.
   - Course completion via `LMSAdapterInterface::markComplete()` → `learndash_process_mark_complete($userId,$courseId)` (`LearnDashService.php:80`). **Note:** `lesson_ld_owns_completion` — if the course has required LD lessons/quizzes, `learndash_process_mark_complete` is the supported "force complete" call and is what we use; the demo course is a simple lessons-only course so this completes cleanly.

4. **Trajectory enrollment shape — PREMISE CORRECTED (this is the load-bearing catch).**
   - The brief assumed `seed_enrolled_user`'s `path: trajectory` registration shows on the Trajecten tab. **It does NOT.**
   - `tab-trajecten.php:35` reads `RegistrationRepository::findTrajectoryEnrollmentsByUser($user_id)` =
     `WHERE user_id = %d AND trajectory_id IS NOT NULL AND edition_id IS NULL AND status != 'cancelled'` (`RegistrationRepository.php:1248-1253`).
   - That is a **PARENT trajectory row** (`trajectory_id` SET, `edition_id` NULL). The matrix's `path: trajectory` registration is an **edition** row (`edition_id` SET, `trajectory_id` NULL) — a child/tagged-edition shape that never appears on the Trajecten tab.
   - **Therefore the demo persona needs a NEW matrix shape: a parent trajectory enrollment.** `RegistrationRepository::create()` accepts this: `create(['user_id'=>X, 'trajectory_id'=>T, 'status'=>'confirmed', 'enrollment_path'=>'trajectory'])` (no `edition_id`) — validated at `:220` ("edition_id OR trajectory_id"), deduped by `findByUserAndTrajectory` (`:243`). The matrix has NO key for this today.

5. **Idempotency / reseed — CONFIRMED.** `runner.php` docblock + each builder pre-check by natural key; registrations dedupe on (user, edition) and (user, trajectory). Reseed is safe and re-runnable with NO unseed. The demo persona's builders must follow the same pre-check-before-create discipline.

**Persona choice: `seed_completed_user`** (recommended over `seed_student1`). Reason: `seed_completed_user` is defined in the users list (`matrix.php:79`) with NOTHING attached — a clean empty slot. Building the demo onto it (a) keeps all demo surfaces on ONE login the demo script can use, (b) avoids perturbing `seed_student1`, which other acceptance/shake-out fixtures and `tests/manual/*` reference for its specific 2-completed-regs/quote/waitlist shape, and (c) makes the seed-verify assertions unambiguous (one named user owns every demo dimension).

---

## Architecture invariants (1b)

The new builder code is dev tooling and lives outside `stride-core`, so the INV audit greps do not scan it — but it MUST still route through the same convergence points the product uses, because that is the whole point of seeding realistic data the product can read back:

- **Audit-write convergence (INV-3 custom-table sub-invariant + the `ntdst/audit/recorded` hook).** `wp_audit_log` is owned by `AuditRepository` behind `AuditService`. The notification builder calls `AuditService::record(...)` — NEVER `$wpdb->insert` into the audit table. This keeps action-slug sanitization, `context` JSON-encoding, the generated `subject_user_id`, and the cache-busting hook all on the product's path. **Bypass signal for review:** any `$wpdb`/`wp_audit_log` reference in the new builder code.
- **INV-3 registration data access.** The parent trajectory enrollment is created via `RegistrationRepository::create()` (already the builder's path), not a raw insert. The existing `buildRegistration` raw-`$wpdb->update` calls for timestamps are the established, accepted builder pattern (`registered_at` not in the allow-list) and are not extended here.
- **INV-6 LearnDash boundary.** Course completion + access use the LD adapter contract semantics: `markComplete` → `learndash_process_mark_complete`. The existing builder already calls `ld_update_course_access()` directly (dev-script latitude, `builders.php:573-578`); the certificate builder MAY reuse that established direct-LD pattern for access, but completion goes through `learndash_process_mark_complete` (the same call `LearnDashService::markComplete` wraps). The certificate POST creation + assignment is pure WP (`wp_insert_post` + `_sfwd-courses` meta) — LD has no service method for "create a certificate," so this is unavoidable and correct.

> These invariants are the convergence target for `/code-review` at Stage 3. A reviewer checks: notification rows created via `record()` (not raw insert); trajectory parent via `create()`; completion via `learndash_process_mark_complete`.

## WP requirements (stack plan-requirements)

### Golden path: none (no matching archetype)
This is seed/dev tooling — no CPT→Repository→Service→Router→frontend slice, no form/AJAX flow, no settings page. It extends an existing declarative data-seeding system whose patterns are already established in `scripts/seed/builders.php`. The relevant "golden path" is **the existing builder conventions** (idempotent pre-check, repository/service calls, EUROS→cents, manifest/covers emission) — every new method matches them.

### WP security requirements (per data-flow)
- **No new user-facing data flow.** N/A across the board — there is no AJAX action, REST route, form post, shortcode attribute, or settings save introduced. Validate: n/a (matrix is a trusted static literal). Sanitize: handled by `AuditRepository::insert` / `RegistrationRepository::create` / WP core. Escape: n/a (no output; the seeder echoes only its own progress text). Authorize: n/a (CLI/dev-gated by `WP_ENV`).

### ntdst-core layering requirements
- [ ] Audit rows created via `AuditService::record()` — no raw `$wpdb` into `wp_audit_log`.
- [ ] Registration (trajectory parent) created via `RegistrationRepository::create()`.
- [ ] LearnDash completion via `learndash_process_mark_complete` (the `markComplete` semantics); no other `learndash_*` reach-around beyond the certificate-assignment meta write + the existing `ld_update_course_access` access pattern.
- [ ] New builder methods are idempotent by natural key (pre-check before create), matching the file's established pattern.

> These blocks + the invariants above are the convergence target for `/code-review` and the `ntdst-drift-reviewer` at Stage 3 — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `scripts/seed/builders.php` | The only file that talks to repositories/services. | **Modify** — add `buildCertificate()`, `buildNotifications()`, `buildTrajectoryEnrollment()`; call them from `buildEditionRegistrations()` / a new demo hook. |
| `scripts/seed/matrix.php` | Declarative feature matrix. | **Modify** — extend the registration sub-array vocabulary (`certificate`, `notifications`); add a `trajectory_enrollments` shape; populate `seed_completed_user`'s demo registrations. |
| `scripts/seed/runner.php` | Orchestrates the matrix. | **Modify (minimal)** — only if trajectory enrollments need a new top-level loop; otherwise unchanged (they ride the registration walk). |
| `scripts/seed-verify.php` | Feature-dimension coverage assertions. | **Modify** — add demo-persona assertions (cert link, ≥1 unread notification, parent trajectory row, quote, pending task) scoped to `seed_completed_user`. |

---

## Acceptance flows (1g) — drive each as `seed_completed_user` in a real browser

Login: https://stride.ddev.site/mijn-account/ as `seed_completed_user@seed.test` / `seedpass123`. Each row is verified by driving the rendered surface (superpowers-chrome / `use_browser` against the running DDEV site), not by unit assertions.

| # | Flow (intended use) | Pass condition (rendered) | Edges (mandatory) |
|---|---|---|---|
| F1 | **Dashboard: active enrollment + pending task** | The dashboard hero / "Acties nodig" shows the demo persona's active edition enrollment AND a pending post-enrollment gated task (session-selection / keuzes / documents). | **Empty:** a brand-new user shows the empty inschrijvingen state (not the demo persona — sanity that the demo data is what populates it). **Wrong-order/re-entry:** completing the task removes it from "Acties nodig" (don't drive the completion in the demo, but confirm the task is genuinely open, not pre-completed). **Boundary:** the pending task's deadline (if any) renders. Concurrent/denied/mid-failure: excluded — read-only dashboard render, single actor. |
| F2 | **Certificaten tab: downloadable certificate** | Certificaten tab shows ≥1 certificate card for the demo persona with a working **Download PDF** link (`has_certificate` true → `getCertificateLink` non-empty). | **Empty:** confirm the empty-state ("Nog geen certificaten") still renders for a user with no completions (regression guard — the new path must not blanket-populate). **Boundary:** completion date renders ("behaald op …"). **Mid-failure:** if `learndash_get_course_certificate_link` returns empty, the card shows "Certificaat wordt gegenereerd…" — assert we get the DOWNLOAD state, not this fallback. Denied/concurrent: excluded. |
| F3 | **Meldingen tab: unread items + badge** | Meldingen tab shows ≥1 unread notification; the dashboard nav shows an unread **badge count ≥1**. | **Empty:** a user with no audit rows shows the empty Meldingen state. **Wrong-order/re-entry:** "Markeer alles als gelezen" clears the badge to 0 and marks items read (drive this — it exercises `markAllRead` + `invalidateCountCache`). **Boundary:** the badge count equals the number of unread items rendered. **Concurrent:** excluded (single actor). **Mid-failure:** a notification whose mapper label is blank is dropped (we only seed mapper-known actions, so this should not occur — assert no blank rows render). |
| F4 | **Offertes tab: a quote** | Offertes tab shows ≥1 quote for the demo persona (e.g. `sent` status) with its number + amount. | **Empty:** empty-state renders for a user with no quotes. **Boundary:** quote total (price + 21% BTW) renders correctly. Denied/concurrent/mid-failure: excluded. |
| F5 | **Trajecten tab: an enrolled trajectory** | Trajecten tab shows ≥1 active trajectory card for the demo persona (progress badge + "Open traject"). | **Empty:** empty-state ("Geen actieve trajecten") renders for a non-enrolled user. **Boundary:** progress % renders (0% acceptable — no child completions required for the demo). **Wrong-order:** confirm the card links to the trajectory dashboard page. **Critical regression:** this card MUST come from a PARENT trajectory row (`trajectory_id` set, `edition_id` null) — verify it actually appears (this is the corrected premise). Denied/concurrent/mid-failure: excluded. |

**Acceptance manifest at Stage 3:** each flow → `pass` / `fail` / `not-reachable`, each with a screenshot. No flow is `pass` without the browser driving it.

---

## Tasks

> Tier legend (per `testing-workflow`): seed/dev-tooling tasks are verified by **running the seeder + `seed-verify.php` + the browser acceptance pass**, not bespoke PHPUnit — so logic-bearing builder tasks are **Tier A (verification = a RED-then-GREEN seed-verify assertion)**, and pure matrix-data declarations are **Tier B**. Each task states its verification explicitly.

---

### Task 1: `buildCertificate()` — issue a real, downloadable certificate

**Files:**
- Modify: `scripts/seed/builders.php` (new method + call site)

**Interfaces:**
- Consumes: `LMSAdapterInterface` semantics via `learndash_process_mark_complete($userId,$courseId)`; `EditionService::getCourseId(int): int`; `StrideSeedRunner::SEED_META_KEY`.
- Produces: `buildCertificate(int $courseId, int $userId): ?int` — ensures a `sfwd-certificates` post exists + is assigned to the course, marks the course complete for the user, returns the certificate post id (or null).

**Tier:** A — completion + cert-assignment logic; verified by a seed-verify assertion that `getCertificateLink` is non-empty.

- [ ] **Step 1: Write the method.** It must (a) reuse-or-create ONE shared seed certificate post (idempotent: find by title `'Stride Demo Certificaat'`, post_type `sfwd-certificates`, else `wp_insert_post`), stamp `SEED_META_KEY`; (b) assign it to the course by merging `_sfwd-courses` meta: read the array, set `['sfwd-courses_certificate' => $certId]`, write back (preserve `course_price_type`); (c) grant access then mark complete:
```php
/**
 * Issue a downloadable LearnDash certificate for one user on one course.
 * Idempotent: reuses the single shared seed certificate post; marking a
 * complete course complete again is a no-op in LD. getCertificateLink()
 * returns '' until BOTH a certificate post is assigned AND the course is
 * genuinely complete — so we do both here (premise ground-truth fact 3).
 *
 * @return int|null certificate post id
 */
public function buildCertificate(int $courseId, int $userId): ?int
{
    if (!function_exists('learndash_process_mark_complete')) {
        echo "      ! LearnDash mark-complete unavailable — certificate skipped\n";
        return null;
    }

    // (a) one shared seed certificate post (idempotent by title)
    $certId = $this->findIdByTitle('sfwd-certificates', 'Stride Demo Certificaat');
    if (!$certId) {
        $certId = wp_insert_post([
            'post_title'   => 'Stride Demo Certificaat',
            'post_type'    => 'sfwd-certificates',
            'post_status'  => 'publish',
            'post_content' => '[certificate_title] — behaald op [certificate_completion_date]',
        ]);
        if (is_wp_error($certId)) {
            echo "      ! Certificate post failed: {$certId->get_error_message()}\n";
            return null;
        }
        update_post_meta($certId, StrideSeedRunner::SEED_META_KEY, true);
        echo "      + Certificate post created (ID: {$certId})\n";
    }

    // (b) assign to course (merge, preserve price_type) — read via the LD meta shape
    $ld = get_post_meta($courseId, '_sfwd-courses', true);
    $ld = is_array($ld) ? $ld : [];
    if (($ld['sfwd-courses_certificate'] ?? null) !== $certId) {
        $ld['sfwd-courses_certificate'] = $certId;
        update_post_meta($courseId, '_sfwd-courses', $ld);
    }

    // (c) grant access + genuinely complete (LD owns completion — markComplete semantics)
    if (function_exists('ld_update_course_access')) {
        ld_update_course_access($userId, $courseId, false);
    }
    learndash_process_mark_complete($userId, $courseId);

    echo "      🎓 Certificate issued: course {$courseId} → user {$userId}\n";
    return (int) $certId;
}
```
- [ ] **Step 2: Wire the call site.** In `buildRegistration()`, after the existing LD-access block, when the matrix registration declares `'certificate' => true` AND status is `completed`, resolve the course id and call `buildCertificate()`. Add the returned id to a new manifest bucket `certificates` (extend `$this->created`/merge in runner — see Task 4 for runner manifest key; if simpler, track via covers). Keep it inside the `$userId` guard.
- [ ] **Step 3: Verify (RED→GREEN via seed-verify).** This task's RED proof is the new assertion added in Task 4 failing before this code exists, then passing after a reseed. For THIS task in isolation: after reseed, run in WP-CLI: `wp eval '$u=get_user_by("email","seed_completed_user@seed.test"); $c=ntdst_get(\Stride\Modules\Edition\EditionService::class)->getCourseId(<demoEditionId>); echo \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($c,$u->ID) ? "LINK\n":"EMPTY\n";'` → expect `LINK`.
- [ ] **Step 4: Commit.**
```bash
git add scripts/seed/builders.php
git commit -m "feat(seed): buildCertificate — assign cert post + mark course complete"
```

---

### Task 2: `buildNotifications()` — derive Meldingen via the real audit write path

**Files:**
- Modify: `scripts/seed/builders.php` (new method + call site)

**Interfaces:**
- Consumes: `NTDST\Audit\AuditService::record(string,int,string,?int,array): int|WP_Error`; the admin actor id (`$userMap['seed_admin']`).
- Produces: `buildNotifications(int $userId, int $adminId, array $specs): void` — records audit rows that surface as Meldingen for `$userId`.

**Tier:** A — the actor/subject branch logic is the whole correctness question; verified by a seed-verify assertion that `getNotifications` ≥1.

- [ ] **Step 1: Write the method.** Critical: branch-correct actor/subject (ground-truth fact 2). `registration.*`/`attendance.*`/`session.note_updated` → `context['user_id']=studentId`, `actorId=adminId`. `completion.*` → `actorId=studentId` (its own subject), `context['user_id']` may also be set but the read finds it via branch 2. Idempotent: pre-check by `(action, context.user_id, entity_id)` to avoid duplicate rows on reseed.
```php
/**
 * Record audit rows that surface as dashboard Meldingen for one user.
 * Routes through the PRODUCT write path (AuditService::record), NOT a raw
 * insert — record() fires ntdst/audit/recorded (busts the unread-count
 * transient) and AuditRepository sanitizes the action + encodes context
 * (INV-3 audit-table convergence). Subject lives in context.user_id; for
 * 'completion.*' the subject IS the actor (findBySubjectUser branch 2).
 *
 * Only mapper-known actions render (NotificationMapper) — any other slug
 * yields a blank title and is dropped, so seed ONLY known actions.
 *
 * @param array<int,array{action:string,entity_type:string,entity_id:int,context:array}> $specs
 */
public function buildNotifications(int $userId, int $adminId, array $specs): void
{
    $audit = ntdst_get(\NTDST\Audit\AuditService::class);
    if (!$audit) {
        echo "      ! AuditService unavailable — notifications skipped\n";
        return;
    }

    foreach ($specs as $spec) {
        $action  = $spec['action'];
        $isCompletion = str_starts_with($action, 'completion.');
        // Subject is always the student (context.user_id). Actor: admin for
        // registration/attendance/session rows (so actor != subject, branch 1);
        // the student for completion.* rows (branch 2 keys on actor_id).
        $actorId = $isCompletion ? $userId : $adminId;
        $context = array_merge(['user_id' => $userId], $spec['context'] ?? []);

        // Idempotency: skip if an identical (action, subject, entity) row exists.
        global $wpdb;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}audit_log
             WHERE action = %s AND subject_user_id = %d AND entity_id = %d",
            $action, $userId, $spec['entity_id'],
        ));
        if ($exists > 0) {
            echo "        - Notification '{$action}' exists for user {$userId}\n";
            continue;
        }

        $res = $audit->record($spec['entity_type'], $spec['entity_id'], $action, $actorId, $context);
        if (is_wp_error($res)) {
            echo "        ! Notification '{$action}' failed: {$res->get_error_message()}\n";
            continue;
        }
        echo "        🔔 Notification: {$action} (subject user {$userId})\n";
    }
}
```
> **Implementer note — verify the audit table name** before writing the idempotency query: confirm `AuditTable::getTableName()` resolves to `{$wpdb->prefix}audit_log` (read `web/app/plugins/ntdst-audit/src/AuditTable.php`). If the prefix differs, use `\NTDST\Audit\AuditTable::getTableName()` directly rather than hardcoding. The idempotency read is the ONLY raw `$wpdb` allowed here (a read, for dedupe) — the WRITE is always `record()`.
- [ ] **Step 2: Wire the call site.** Add a demo hook that fires once per demo persona (see Task 3 for where the matrix declares the `notifications` specs). For the demo persona, seed a mix that exercises ≥2 mapper labels, e.g. a `registration.created` (context: `edition_id`) and a `completion.certificate_issued` (context: `course_id`, `course_title`, `certificate_link`) — the latter doubles as the Meldingen counterpart of the certificate. Leave them UNREAD (do not write `_stride_notifications_read`).
- [ ] **Step 3: Verify (RED→GREEN via seed-verify).** Task 4 adds the assertion. In isolation after reseed: `wp eval '$u=get_user_by("email","seed_completed_user@seed.test"); $n=ntdst_get(\Stride\Modules\Notification\NotificationService::class); echo count($n->getNotifications($u->ID))." notifs, ".$n->getUnreadCount($u->ID)." unread\n";'` → expect ≥1 notifs, ≥1 unread.
- [ ] **Step 4: Commit.**
```bash
git add scripts/seed/builders.php
git commit -m "feat(seed): buildNotifications via AuditService::record (subject/actor branch-correct)"
```

── REVIEW GATE ── (tier: STANDARD — new builder methods touch the audit-write + LD-completion convergence points but introduce NO 1a surface; dev tooling, env-gated, no user input. Reviewer verifies: record() not raw-insert, branch-correct actor/subject, markComplete used, idempotency. `/code-review --effort=medium` + ntdst-drift-reviewer on `scripts/seed/builders.php`. Escalate to FULL only if a finding lands on an actual auth/data-tenancy surface — not expected.)

**Integration gate (cluster 1):** reseed (`ddev exec wp eval-file scripts/seed.php`) completes without errors; the two isolation probes in Task 1 Step 3 and Task 2 Step 3 both return their GREEN result.

---

### Task 3: Demo persona matrix entry — consolidate ALL surfaces onto `seed_completed_user`

**Files:**
- Modify: `scripts/seed/matrix.php`

**Interfaces:**
- Consumes: the new vocabulary keys read by Tasks 1, 2 (`certificate`, `notifications`) + the trajectory-parent shape read by Task 4's builder wiring.
- Produces: matrix data only — no code.

**Tier:** B — pure declarative data (`no unit test: Tier B, declarative matrix data verified by the seed-verify assertions in Task 4 + the browser pass, not a unit test`).

- [ ] **Step 1: Extend the SHAPE docblock** (`matrix.php:36-42`) to document the new registration keys: `certificate => bool` (issue a downloadable cert; requires status `completed`), `notifications => array` (audit specs → Meldingen). Document the new top-level/edition-level trajectory-enrollment shape chosen in Step 3.
- [ ] **Step 2: Give `seed_completed_user` a COMPLETED edition registration with a certificate + a pending-task registration.** Add to an existing completed-status edition (e.g. the past "Motiverende Gespreksvoering" completed edition at `matrix.php:140-161`, OR a dedicated demo edition) a registration:
```php
['user' => 'seed_completed_user', 'status' => 'completed', 'path' => 'individual',
    'attendance' => 'present', 'quote' => 'sent',
    'certificate' => true,                       // NEW → Task 1
    'notifications' => [                          // NEW → Task 2
        ['action' => 'registration.created', 'entity_type' => 'registration',
         'entity_id' => 0 /* builder fills regId */, 'context' => [/* edition_id filled by builder */]],
        ['action' => 'completion.certificate_issued', 'entity_type' => 'course',
         'entity_id' => 0 /* builder fills courseId */, 'context' => [/* course_id/title/link filled by builder */]],
    ],
],
```
   AND a SEPARATE pending registration on an edition with `requires` (e.g. the session-slots edition at `matrix.php:340` or the documents edition) so F1's pending task shows:
```php
['user' => 'seed_completed_user', 'status' => 'pending', 'path' => 'individual', 'init_tasks' => true],
```
   > **Implementer decision point:** `entity_id` and `context` values that depend on the just-created `regId`/`courseId`/`certificate_link` cannot be authored statically in the matrix. Resolve this by having the BUILDER (Task 2 call site) construct the `notifications` specs from the live ids rather than the matrix carrying raw ids — i.e. the matrix `notifications` array declares the ACTIONS to fire (`['registration.created','completion.certificate_issued']`) and the builder fills `entity_id`/`context` from the registration + course it just built. Prefer this; adjust Task 2's call site accordingly and note the divergence in the implementer report.
- [ ] **Step 3: Add a PARENT trajectory enrollment for `seed_completed_user`** (the corrected premise — fact 4). Choose ONE shape and apply it consistently:
   - **Recommended:** a new top-level matrix key `trajectory_enrollments` (a flat list resolved after trajectories are built, since it needs the trajectory id by title):
```php
// new top-level key, returned alongside 'users','courses','trajectories',...
'trajectory_enrollments' => [
    ['user' => 'seed_completed_user', 'trajectory_title' => 'Traject Jeugdgezondheidsspecialist',
        'status' => 'confirmed'],
],
```
     This needs a runner loop (Task 4) AFTER `buildTrajectory` so the title resolves to an id.
- [ ] **Step 4: Verify.** This task has no standalone test; correctness is proven by the Task 4 assertions + the browser pass. Confirm the matrix still parses: `ddev exec wp eval-file scripts/seed.php` runs to completion (Task 5 drives the full pass).
- [ ] **Step 5: Commit.**
```bash
git add scripts/seed/matrix.php
git commit -m "feat(seed): demo persona — consolidate all dashboard surfaces on seed_completed_user"
```

---

### Task 4: Trajectory-enrollment builder + extend `seed-verify.php`

**Files:**
- Modify: `scripts/seed/builders.php` (`buildTrajectoryEnrollment()`), `scripts/seed/runner.php` (loop), `scripts/seed-verify.php` (assertions)

**Interfaces:**
- Consumes: `RegistrationRepository::create(['user_id','trajectory_id','status','enrollment_path'])`, `RegistrationRepository::findByUserAndTrajectory(int,int)`, `findIdByTitle('vad_trajectory', $title)`.
- Produces: `buildTrajectoryEnrollment(array $spec, array $userMap): ?int`; runner manifest key `trajectory_enrollments`; new seed-verify assertions.

**Tier:** A — the parent-row shape is the corrected premise; verified by a seed-verify assertion that a parent row exists AND the dashboard read returns it.

- [ ] **Step 1: Write `buildTrajectoryEnrollment()`** — idempotent parent trajectory row (`trajectory_id` set, `edition_id` NULL):
```php
/**
 * Create a PARENT trajectory enrollment (trajectory_id set, edition_id NULL)
 * — the shape tab-trajecten.php reads via findTrajectoryEnrollmentsByUser().
 * A path:trajectory EDITION registration does NOT appear there (premise
 * ground-truth fact 4). Idempotent via findByUserAndTrajectory.
 *
 * @return int|null registration id
 */
public function buildTrajectoryEnrollment(array $spec, array $userMap): ?int
{
    $repo   = ntdst_get(RegistrationRepository::class);
    $userId = (int) ($userMap[$spec['user']] ?? 0);
    if (!$userId) {
        echo "  ! Trajectory enrollment: unknown user '{$spec['user']}'\n";
        return null;
    }
    $trajectoryId = $this->findIdByTitle('vad_trajectory', $spec['trajectory_title']);
    if (!$trajectoryId) {
        echo "  ! Trajectory enrollment: trajectory not found '{$spec['trajectory_title']}'\n";
        return null;
    }

    $existing = $repo->findByUserAndTrajectory($userId, $trajectoryId);
    if ($existing) {
        echo "  - Trajectory enrollment {$spec['user']} → {$spec['trajectory_title']} exists (ID: {$existing->id})\n";
        return (int) $existing->id;
    }

    $regId = $repo->create([
        'user_id'         => $userId,
        'trajectory_id'   => $trajectoryId,   // parent: NO edition_id
        'status'          => $spec['status'] ?? 'confirmed',
        'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        'notes'           => 'Seed: demo persona trajectory enrollment',
    ]);
    if (is_wp_error($regId)) {
        echo "  ! Trajectory enrollment failed: {$regId->get_error_message()}\n";
        return null;
    }
    echo "  + Trajectory enrollment: {$spec['user']} → {$spec['trajectory_title']} (ID: {$regId})\n";
    return (int) $regId;
}
```
> **Implementer note:** confirm `findByUserAndTrajectory` exists and its return shape (`RegistrationRepository.php:~718-740` — it queries `WHERE user_id=%d AND trajectory_id=%d AND edition_id IS NULL`). It does, per ground-truth. If the method name differs, use the existing finder.
- [ ] **Step 2: Add the runner loop** in `runner.php::run()`, AFTER the `trajectories` loop (so titles resolve), guarded for the new key:
```php
foreach (($this->matrix['trajectory_enrollments'] ?? []) as $te) {
    $id = $this->builders->buildTrajectoryEnrollment($te, $this->userMap);
    if ($id) {
        $this->created['registrations'][] = $id;          // counts toward manifest
        $this->created['trajectory_enrollments'][] = $id; // dedicated bucket (init it in $created)
    }
}
```
   Add `'trajectory_enrollments' => []` to the `$created` array initializer (`runner.php:18-22`).
- [ ] **Step 3: Add demo-persona assertions to `seed-verify.php`** (after § 3i, before the failures tally). RED-first: these fail before Tasks 1-3 land, pass after. Scope EVERY assertion to `seed_completed_user`:
```php
// ---------------------------------------------------------------------------
// 4. Demo persona (seed_completed_user) — every dashboard surface populated.
//    Drives the SAME reads the dashboard tabs use, so a PASS here means the
//    rendered surface will populate (premise ground-truth: trajectory needs a
//    PARENT row, certificate needs a genuine LD completion + assigned cert).
// ---------------------------------------------------------------------------
$demo = get_user_by('email', 'seed_completed_user@seed.test');
$check('demo persona user exists', $demo instanceof WP_User);
if ($demo instanceof WP_User) {
    $uid = (int) $demo->ID;

    // F3 Meldingen: >=1 notification, >=1 unread (real product read)
    $notif = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
    $all   = $notif->getNotifications($uid);
    $check('demo persona has >=1 notification', count($all) >= 1);
    $check('demo persona has >=1 UNREAD notification', $notif->getUnreadCount($uid) >= 1);

    // F2 Certificaten: >=1 completed edition reg whose course yields a cert link
    $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
    $edSvc   = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    $hasCertLink = false;
    foreach ($regRepo->findByUser($uid) as $r) {
        if (($r->status ?? '') !== 'completed' || empty($r->edition_id)) {
            continue;
        }
        $cid = (int) $edSvc->getCourseId((int) $r->edition_id);
        if ($cid && \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($cid, $uid) !== '') {
            $hasCertLink = true;
            break;
        }
    }
    $check('demo persona has a downloadable certificate link', $hasCertLink);

    // F4 Offertes: >=1 quote tied to the demo persona
    global $wpdb;
    $quoteCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote'
         WHERE pm.meta_key = 'user_id' AND pm.meta_value = %d",
        $uid,
    ));
    $check('demo persona has >=1 quote', $quoteCount >= 1);

    // F5 Trajecten: >=1 PARENT trajectory enrollment (the corrected premise)
    $trajEnrollments = $regRepo->findTrajectoryEnrollmentsByUser($uid);
    $check('demo persona has >=1 parent trajectory enrollment (tab-trajecten read)', count($trajEnrollments) >= 1);

    // F1 Dashboard pending task: >=1 pending reg with open completion_tasks
    $pendingWithTasks = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}vad_registrations
         WHERE user_id = %d AND status = 'pending' AND completion_tasks IS NOT NULL",
        $uid,
    ));
    $check('demo persona has >=1 pending registration with a completion task', $pendingWithTasks >= 1);
}
```
   > **Implementer note:** verify the quote `user_id` meta key + prefix before writing the quote query (QuoteCPT meta_prefix is `''` per seed-verify.php:107 comment — so bare `user_id`). If `findByUser` returns row objects with a different field name than `edition_id`/`status`, adjust to the actual shape (it returns the raw registrations rows).
- [ ] **Step 4: Verify (RED→GREEN).** Before this branch's builders run, these assertions FAIL. Reseed, then `ddev exec wp eval-file scripts/seed-verify.php` → all § 4 assertions PASS, host-side `echo $?` = 0.
- [ ] **Step 5: Commit.**
```bash
git add scripts/seed/builders.php scripts/seed/runner.php scripts/seed-verify.php
git commit -m "feat(seed): parent trajectory enrollment + demo-persona coverage assertions"
```

---

### Task 5: Reseed + browser acceptance pass

**Files:** none (verification task).

**Tier:** B (`no unit test: Tier B, this is the integration/acceptance drive, not a code change`).

- [ ] **Step 1: Full reseed.** `ddev exec wp eval-file scripts/seed.php` — completes without PHP errors; summary prints.
- [ ] **Step 2: Run the verifier.** `ddev exec wp eval-file scripts/seed-verify.php`; check HOST-side `$?` (per the script's own note — `ddev exec bash -c '...; echo $?'` lies). Expect `ALL DIMENSIONS COVERED` and exit 0, including all § 4 demo-persona assertions.
- [ ] **Step 3: Drive the `## Acceptance flows` matrix** (F1–F5) in a real browser as `seed_completed_user@seed.test` / `seedpass123` at https://stride.ddev.site/mijn-account/. Use superpowers-chrome / `use_browser`. Capture a screenshot per flow. Emit the pass/fail/not-reachable manifest. Drive the F3 "mark all read → badge 0" edge.
- [ ] **Step 4 (idempotency guard): reseed AGAIN** and re-run the verifier — confirm no duplicate certificates/notifications/trajectory rows (counts stable), proving the new builders' pre-checks hold.
- [ ] **Step 5: Commit** any verifier/matrix fixups surfaced by the drive (if none, skip).

── REVIEW GATE ── (tier: STANDARD — matrix data + verifier + the acceptance drive; no 1a surface. Spec-close panel = `reviewer` + `invariant-auditor` (verify the audit-write + LD-completion + INV-3 trajectory convergence held) + the feature-acceptance browser manifest. No security-sentinel.)

**Integration gate (cluster 2):** `seed-verify.php` exits 0 with all § 4 assertions GREEN; the F1–F5 acceptance manifest is all `pass`; the double-reseed shows stable row counts.

---

## Self-review (against the spec)

- **Spec coverage:** F1 enrollment+pending task → Task 3 (pending reg) + Task 4 assertion. F2 certificate → Task 1 + assertion. F3 notifications → Task 2 + assertion. F4 quote → Task 3 (`'quote'=>'sent'`, existing builder) + assertion. F5 trajectory → Task 4 (parent row) + assertion. All five must-show surfaces have a building task AND a verifying assertion AND an acceptance-flow row.
- **Premise correction is load-bearing and surfaced:** the trajectory parent-row shape (fact 4) is the one thing that would have silently failed the demo had the brief's assumption been taken at face value. It drives Task 4's existence.
- **Certificate build requirement surfaced:** the zero-certificate-posts probe means Task 1 MUST create + assign a cert post, not just mark complete — without this, F2 fails silently (button never renders).
- **Idempotency:** every new builder pre-checks before create; Task 5 Step 4 proves it with a double reseed.
- **No placeholders:** every code step shows real code with confirmed signatures; the two implementer decision points (notification entity_id/context resolution; trajectory-enrollment shape) are explicitly flagged with a recommended resolution, not left as TBD.

## Execution Handoff

Plan complete and saved to `docs/plans/2026-06-30-demo-persona-seed.md`. Recommended: **Subagent-Driven** — one implementer per task, two review clusters as marked. Cluster 1 (Tasks 1-2, the builders) reviewed before Cluster 2 (Tasks 3-5, the data + verification).
