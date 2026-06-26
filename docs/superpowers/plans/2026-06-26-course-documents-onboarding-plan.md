# Course Documents & Onboarding Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Dispatch `netdust-agent:implementer` one task at a time.

**Goal:** Give Stride a structured, secure, trackable documents capability on courses/editions/trajectories (the A2 `vad_document` record), plus surface the e-learning/quiz/download progress LearnDash already tracks but Stride never displayed — so an organisation can see, per employee: enrolled · downloaded doc X · started/finished e-learning · quiz results.

**Architecture:** New `vad_document` CPT (one owner each: course|edition|trajectory) reached through a `DocumentRepository` (INV-3). Files live in protected storage (the `CompletionProofStorage` pattern, extracted into a shared `ProtectedUploadStorage` base) and are served only through a new authenticated, enrollment-gated download endpoint registered as an `ntdst/api_data/*` filter (INV-2). The download endpoint is the single tracking choke point, writing a download event. The admin metabox reuses `edition-admin.js`'s `wp.media` picker. The dossier surfaces download events + LearnDash lesson/quiz reads via a thin read service.

**Tech Stack:** PHP 8.3, NTDST Core (DI, `ntdst_data()`, repositories, `ntdst_response()`), WordPress mu-plugin (`stride-core`), LearnDash (`learndash_user_activity` table), Tailwind + Alpine (frontend), `wp.media` (admin picker).

## Global Constraints

- All lands in `web/app/mu-plugins/stride-core/` (launch-blocking, NOT a client mu-plugin). Frontend partials land in `web/app/themes/stridence/`.
- All UI text in Dutch (nl_BE); code/identifiers English.
- Data model is **A2** (`vad_document` record). NOT a flat attachment-ID array. The legacy `edition.documents` field is NOT removed this iteration.
- A document has **exactly one owner** (course OR edition OR trajectory). No many-to-many on day one. The "same doc on trajectory + child editions" requirement is a deferred *display rule*, not stored twice.
- INV-3: all `vad_document` data access through `DocumentRepository`; field names live in `DocumentCPT::getFields()` (no central registry). Bare field names, `_ntdst_` prefix applied by the layer.
- INV-2: new frontend AJAX is an `ntdst/api_data/<action>` filter — never a raw `wp_ajax_*` handler. Nonce already verified upstream.
- INV-1: download authorization decided in the handler — logged-in + (enrolled in the owner OR `stride_manage`).
- INV-4: failures return `WP_Error`, logged via `ntdst_log('<channel>')`, never `null`/`false`/swallowed.
- INV-6: LearnDash reached only through `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads). New quiz read = a new `LearnDashHelper` method.
- Build the **CORE iteration only**. The Deferred list at the bottom stays deferred.

---

## Threat model

> Written 2026-06-26 for the course-documents + gated-download + per-employee tracking surface. This feature serves gated files through a new authenticated endpoint, takes uploads on admin screens, decides cross-enrollment access, and writes a per-employee download audit. That is a security-rich surface; this section is the convergence target so `/code-review` and `security-sentinel` verify against named mitigations instead of re-discovering the attack surface each round. It deliberately mirrors the proven `CompletionProofStorage` / `CompletionTaskHandler::resolveProofDownload` threat model (M1–M4) — that pattern already passed `/security-review` — and extends it for the new *enrollment-based* (not registration-ownership-based) authorization anchor.

### What we're defending

1. **The gated document bytes** — `vad_document` attachment files in `uploads/stride-documents/`, e.g. an onboarding handbook or speaker slides marked `visibility = enrolled`. Must not be reachable by a non-enrolled user, anonymous visitor, or another onboarding's learner.
2. **Document existence / filenames** — the attachment row itself. A filename can carry PII or reveal client/onboarding structure (`onboarding-acme-q3-restructure.pdf`); anonymous WP REST `/wp/v2/media` and attachment permalinks must not enumerate it.
3. **The per-employee download audit log** — `user · document · timestamp · registration` rows. Behavioural PII about an employee. Readable only by `stride_manage`/`stride_view` in the right dossier, never cross-leaked.
4. **The server's filesystem** — the download endpoint resolves a file path. A user-controlled string reaching that path (`../../wp-config.php`) is path traversal / arbitrary file read.
5. **LearnDash progress + quiz scores surfaced in the dossier** — reading another employee's quiz score by manipulating the user/registration id is a cross-tenant read.

### Who we're defending against

- **Anonymous visitor** — IN scope. No download, no enumeration via REST/permalink/guessable URL.
- **Authenticated learner enrolled in onboarding A, probing onboarding B's docs** (the spec's named boundary "an employee must not download another onboarding's gated docs") — IN scope. Core cross-enrollment boundary.
- **Authenticated learner iterating document/attachment IDs** (enumeration probe) — IN scope. Denials detail-free.
- **Authenticated learner manipulating dossier user_id / registration_id params** to read another employee's downloads or quiz scores — IN scope.
- **A non-`stride_manage` admin-area user trying to attach/manage documents** — IN scope (capability boundary on metabox save).
- **A `stride_manage` coordinator** — TRUSTED for management + viewing all dossiers (their job). Audit-logged, not defended against.
- **Insider with stolen `stride_manage` credentials** — OUT of scope (acknowledged residual, same as the rest of Stride admin).
- **Web-server deny-rule removed by misconfiguration** — OUT of scope at app layer; mitigated by defense-in-depth (`.htaccess` + `post_status=private` + path-resolution); nginx deny is authoritative (documented in `site.yml`).

### Attacks to defend against

1. **Public-URL leak past the gate.** Frontend renders a link only for enrolled users, but if the file sits at a guessable public `/uploads/YYYY/MM/<file>` URL the gate is cosmetic. (This is exactly why `edition.documents` today is insecure — the metabox renders `wp_get_attachment_url()`.)
2. **Attachment enumeration via WP REST / permalink.** `post_status='inherit'` lets anonymous `/wp/v2/media` list `source_url` + PII filename and the attachment permalink return 200.
3. **Cross-enrollment download.** Learner enrolled in onboarding A passes onboarding B's `document_id` and receives B's gated file.
4. **Path traversal / arbitrary file read.** A user string (`../../wp-config.php`, absolute path, symlink) reaches the served path.
5. **Document-ID iteration probe.** Learner increments `document_id` to map which onboardings/documents exist via response/timing differences.
6. **Unauthorized document management.** A logged-in user without `stride_manage` (or a CSRF-tricked editor) posts a documents-metabox save and attaches/re-points documents, or points `owner_id` at a post they shouldn't touch.
7. **Stored XSS via document metadata.** `title`/`description`/`category` are admin-entered free text rendered on the learner card and dossier; an unescaped sink executes script.
8. **Malicious file upload.** An admin (or privilege-escalated user) uploads `.svg`/`.html`/`.phtml` that runs if ever served inline or from a web-exec path.
9. **Content-type sniffing on download.** Wrong/empty `Content-Type` + no `nosniff` lets a browser sniff `.svg`/`.html` as active content in the site origin.
10. **Cross-employee audit/quiz read in the dossier.** Downloads/quiz scores read by a user_id/registration_id param not re-scoped to the caller's reach.
11. **Download-event write as tracking-spoof / log-flood.** Learner replays the download call to inflate "downloaded X" or floods the event table.
12. **Tracking-anchor confusion (registration vs. enrollment).** The proof pattern's anchor is "this registration owns this attachment." Course docs are owned by a *course/edition/trajectory* and gated by *enrollment*, so a wrong derivation either logs against the wrong registration (audit corruption) or treats "any registration of mine" as access to "any doc" (over-grant).

### Mitigations required

1. **Protected storage, no public URL.** `vad_document` files route into `uploads/stride-documents/` via the extracted `ProtectedUploadStorage` base (same `upload_dir` filter + `.htaccess` deny + `index.php` as `CompletionProofStorage`). nginx `location ^~ /app/uploads/stride-documents/ { deny all; }` is the authoritative deny (add to `site.yml` notes); `.htaccess` is defense-in-depth. The frontend NEVER renders `wp_get_attachment_url()` for a `vad_document` — only the gated endpoint URL (`stride_download_document`). *(Edition legacy `edition.documents` keep public URLs unless adopted — Open question Q1.)*
2. **`post_status = private` on every `vad_document` attachment.** Stamped at attach time by the reused `ProtectedUploadStorage::markProtected()`. Hides it from anonymous `/wp/v2/media`, permalink, sitemaps; `get_attached_file()` + metabox query are status-independent.
3. **Enrollment-gated authorization in the handler (INV-1).** `DocumentDownloadHandler::resolveDownload(int $documentId)` accepts a **document_id only** (never attachment_id or path from the client). It loads the `vad_document` via `DocumentRepository`, reads `owner_type`/`owner_id`, and grants only if `current_user_can('stride_manage')` OR the user is enrolled in that owner (Task 2.3 names the predicate per owner_type: course → active registration on an edition of that course OR LD `isEnrolled`; edition → `RegistrationRepository` active registration for (user, edition); trajectory → `RegistrationRepository::existsForTrajectory`/`TrajectorySelection`). No client string ever reaches a path.
4. **Path-resolution guard (M3, reused verbatim).** Served path = `get_attached_file($document->attachment_id)` (server-side, from the resolved doc); `ProtectedUploadStorage::isProtectedPath($path)` (realpath + `str_starts_with` protected dir) must be true or deny. Defeats `..`/symlinks.
5. **Detail-free denials.** Every denial returns the same `WP_Error('forbidden', __('Geen toegang.', 'stride'))` shape (mirrors `resolveProofDownload`). Probes logged via `ntdst_log('documents')->warning()` with requester id, not surfaced.
6. **Capability gate on management (INV-1).** Metabox renders only when the caller can edit the owner post (`current_user_can('edit_post', $ownerId)` for course/trajectory edit; confirmed in Task 3.1); the save handler re-checks the cap + verifies the metabox nonce before writing (admin-page save path, NOT `api_data`). `owner_id` validated as a post the caller may edit.
7. **Output escaping at every sink.** `title`/`description`/`category` via `esc_html` (text), `esc_attr` (attributes); download URL via `esc_url`. No raw `echo $var`. Values sanitized on save (`sanitize_text_field` title/category, `sanitize_textarea_field` description, `absint` attachment_id/owner_id).
8. **Upload type allow-list + non-executable dir.** `wp.media` picker `library.type`-filtered to docs/images (reuse `edition-admin.js` list). The save handler validates each `attachment_id` is a real attachment with an allowed MIME and **rejects `image/svg+xml`, `text/html`, PHP** before stamping protected. Protected dir does not execute PHP (inherited from proof dir). SVG rejected, not sanitized, for v1.
9. **Forced-download headers (M4, reused).** Served via `ntdst_response()->download($contents, $filename, $mime)` → stored validated `Content-Type` + `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`. Never inline.
10. **Dossier reads scoped to the queried registration; audit reads to `stride_view`+.** Dossier download/quiz section renders only inside `AdminUserService::getUserDetail` (already `canViewAdmin`/`stride_view`-gated) and reads downloads/quiz **for the registrations of the user being viewed** — the dossier subject's user_id, not a re-purposed free param. Download-log read goes through `DocumentDownloadLogRepository::findForRegistration($resolvedRegistrationId)`. The learner's own secondary view reads only `get_current_user_id()`'s rows.
11. **Bounded download-event writes.** Written server-side inside the authenticated download choke point only. Dedup: collapse repeats per (user, document, registration) within a rolling window (store first_at + last_at + count, upsert) so replay/refresh does not flood. Exact shape in Task 2.5.
12. **Enrollment-derived registration anchor.** The handler derives the registration to log against from the SAME (user, owner) enrollment lookup that authorized the download (Mitigation 3) — so the logged registration is provably owned by the user AND enrolled in this doc's owner. Authorization and logged anchor are one derivation, not two; no "a registration I own → any doc" over-grant path exists.

### Out of scope (explicit deferrals)

- **SVG sanitization** — SVGs rejected on upload for v1, not sanitized-and-allowed.
- **Edition legacy `edition.documents` migration to gated storage** — deferred (spec); legacy edition docs keep public URLs unless Q1 says otherwise. Gated endpoint applies to `vad_document` only.
- **Web-server deny-rule drift monitoring** — nginx rule authoritative + operational; app-layer defense-in-depth is the residual.
- **Rate-limiting authorized downloads** beyond the dedup write — a flood of *authorized* downloads is accepted residual.
- **Virus/malware scanning of uploads** — out of scope; type allow-list + non-exec dir + forced-download is the v1 posture.
- **`public` (brochure) visibility** — enum reserves the value; no `public` path built this iteration (only `enrolled`).

### How to use this section

- **Controller pre-flight:** before dispatching Phase 2 (storage + download) and Phase 3 (metabox), confirm task code carries Mitigations 1–9, 11–12. Never dispatch Phase 2 without Mitigations 3, 4, 5, 12 in the handler task.
- **`/code-review` / `security-sentinel`:** invoke with "Verify the diff against `## Threat model` mitigations 1–12; report each in-place / missing / out-of-scope per deferrals. A finding on a 1a surface (download endpoint, storage, dossier audit read) promotes that cluster to FULL tier."
- **`/evaluate` retros:** any unimplemented mitigation is a plan-correction defect, not a new discovery.
- **Downstream (Deferred layers):** when trajectory→child mirroring or `public` visibility is built, extend this model (`public` doc skips Mitigation 3's enrollment check — add deliberately, not by default).

---

## Architecture invariants touched

Read `/home/ntdst/Sites/stride/ARCHITECTURE-INVARIANTS.md`. This feature touches these convergence points; each task routes THROUGH the named point, never around it.

| Invariant | Convergence point | How this feature touches it |
|---|---|---|
| **INV-1 Authorization** | per-surface (`AdminAPIController::canView/canManageAdmin`; per-handler for admin-page AJAX) | **New authorization surface**: the gated download. Decided in `DocumentDownloadHandler::resolveDownload()` (logged-in + enrolled-in-owner OR `stride_manage`). Metabox save re-checks `edit_post` cap + nonce. Dossier read stays behind `getUserDetail`'s existing `canViewAdmin`. **Recommendation:** add a named row to INV-1 for "may this user download this gated document" (Task 0.1) — there is no existing convergence point for *enrollment-gated file access*, and this is the second such surface (after proofs), so it earns a name. |
| **INV-2 Frontend AJAX nonce** | `ntdst-core/api/Endpoints.php` (framework) | Download registers as `add_filter('ntdst/api_data/stride_download_document', …, 10, 2)`. Nonce verified upstream; handler MUST NOT re-verify. No raw `wp_ajax_*`. |
| **INV-3 Data access via repository** | `AbstractRepository` → `ntdst_data()`; custom tables owned 1:1 by a repository | `vad_document` CPT data through new `DocumentRepository extends AbstractRepository`. Field names in `DocumentCPT::getFields()` (no central registry). The download-event log is a small custom table owned 1:1 by `DocumentDownloadLogRepository` (the ONLY `$wpdb` caller for it, `$wpdb->prepare` throughout) — mirrors `RegistrationRepository`/`AttendanceRepository`. Data API vocabulary: `title` not `post_title`. |
| **INV-4 WP_Error + ntdst_log** | `RepositoryInterface` contract; `ntdst_log('<channel>')` | All failure paths return `WP_Error`; new `documents` log channel. Download handler returns `WP_Error` (the `api_data` path surfaces it). No `null`/`false`/swallow. |
| **INV-5 Rendering via loader; plugin never calls theme** | `NTDST_Template_Loader` / `ntdst_response()->html()` | The shared documents-render partial lives in `stride-core/templates/` (plugin-owned, used across course/edition surfaces) — NOT in the theme — because stride-core must not depend on theme helpers. Theme templates `the_content`-equivalent include it via the loader. Escaping at sinks per the sub-invariant. |
| **INV-6 LearnDash boundary** | `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads) | Dossier quiz/lesson reads add a NEW `LearnDashHelper::getQuizAttempts()` (reads `learndash_user_activity`) + reuse `getLessons`/`getProgress`. No `learndash_*` call escapes the helper. |

INV-7 (status) and INV-8 (VAT) are NOT touched — no offering-status gate decision and no money math in this feature. Note explicitly so reviewers don't expect them.

**These blocks are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out.** Reviewers verify the diff against the named golden-path slice + the four pillars + the nine drift categories + the invariants above — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## WP security requirements (per data-flow)

Per `netdust-wp:wp-security` (four pillars: validate / sanitize / escape / authorize). One line per user-facing data flow this feature introduces.

- [ ] **Frontend AJAX `ntdst/api_data/stride_download_document`** (the gated download): **authorize** = inside-handler INV-1 (logged-in + enrolled-in-owner OR `stride_manage`, Mitigation 3); **validate** = `absint($params['document_id'])`, document loaded server-side, attachment_id/path NEVER from client; **sanitize** = n/a (no stored write of client input beyond the bounded event row, which stores server-derived ids only); **escape** = file served via `ntdst_response()->download()` with forced-download + nosniff headers (Mitigation 9); nonce already verified upstream by the framework (INV-2 — do NOT re-verify).
- [ ] **Admin-page metabox save** (course + trajectory documents): **authorize** = `current_user_can('edit_post', $ownerId)` + `check_admin_referer`/nonce verify (Mitigation 6); **validate** = `absint` on attachment_id/owner_id, MIME allow-list rejecting svg/html/php (Mitigation 8), `owner_type` against the enum; **sanitize** = `sanitize_text_field` (title/category), `sanitize_textarea_field` (description), `absint` (order); **escape** = on re-render, `esc_html`/`esc_attr` for the doc fields, `esc_url` for any link (Mitigation 7).
- [ ] **Frontend Materialen render** (course/edition/trajectory): **authorize** = enrolled-gate decides whether download links render (display gate; the storage layer is the real gate); **validate** = n/a (read-only); **sanitize** = n/a; **escape** = `esc_html`(title/description/category/size), `esc_url`(download endpoint URL), `esc_attr` (attributes) — Mitigation 7.
- [ ] **Dossier progress read** (admin): **authorize** = inherits `AdminUserService::getUserDetail` `canViewAdmin` (`stride_view`); **validate** = reads scoped to the viewed user's registrations only, no re-purposed param (Mitigation 10); **sanitize** = n/a; **escape** = `esc_html` on doc titles + quiz/lesson values rendered in the dossier client.
- [ ] **Learner's own progress view** (secondary): **authorize** = `get_current_user_id()` only — never a user_id param; **escape** = as the dossier.

Every flow accounts for all four pillars; where one is n/a it says so. A missing pillar here is the bug, pre-shipped.

---

## ntdst-core layering requirements

The nine drift categories (`ntdst-drift-reviewer`). Only the rows that apply are kept.

- [ ] **Data access via Repository** — `vad_document` through `DocumentRepository`; the event-log table through `DocumentDownloadLogRepository` (its sole `$wpdb` caller, `$wpdb->prepare`). No `ntdst_data()`/`$wpdb` for these outside their repos. The quiz read is the ONE new raw LD-table read and lives in `LearnDashHelper` (the sanctioned LD boundary, like the existing `getLastActivityDate`), not in a service.
- [ ] **No pure pass-through Service methods** — `DocumentService` only exists if it adds validation/transform/events (the attach-validate-stamp flow, the merge/group-by-category logic). A method that is `return $this->repository->X()` is drift — callers use the repo directly.
- [ ] **No raw `wp_ajax_*`** — the download is an `ntdst/api_data/*` filter (INV-2). The metabox save uses the existing edition-admin-controller save pattern (`save_post` hook + nonce), which is the accepted admin-page pattern, not a raw frontend ajax handler.
- [ ] **No `ob_start()+include` rendering** — the shared documents partial renders via `ntdst_response()->html()` / the template loader.
- [ ] **No swallowed `WP_Error`** — download + repository failures return/bubble `WP_Error`, logged via `ntdst_log('documents')`.
- [ ] **Data API vocabulary** — `title`/`content`/`excerpt`, bare field names; `_ntdst_` applied by the layer; field names only in `DocumentCPT::getFields()`.
- [ ] **No hardcoded meta prefix** — use `$this->repository->getMetaPrefix()`; never literal `_ntdst_document_*`.
- [ ] **Correct module layering** — CPT/Repository/Service under `Modules/Document/`; the download handler under `Handlers/` (thin-handler pattern, `ntdst_get` inside methods); the storage base under `Infrastructure/`; the dossier read in the sanctioned `Admin/` read-model layer.
- [ ] **Service lifecycle / DI** — `DocumentService`, `DocumentCPT` registration, and the download handler registered in `plugin-config.php` per `NTDST_Service_Meta` (metadata()+priority), mirroring existing services.

**Per-task acceptance line (every module-touching task):** drift pre-check clean — `/drift-reviewer <touched path>` returns no findings (the nine categories) and the per-flow security line above is satisfied in the diff.

---

## Golden path: content-type feature (CPT → Repository → Service → admin/frontend) + form-data-flow (the download write)

- [ ] Built to `golden-paths/content-type-feature.md` (the `vad_document` CPT/repository/service/admin/frontend spine) and `golden-paths/form-data-flow.md` (the download endpoint as a gated read-with-side-effect write). Read both before task breakdown.
- [ ] Deviations from the slice (each named + justified):
      - **Frontend is not a public router/single-template** — documents render as a *section embedded in existing* course/edition/trajectory pages via a shared plugin-owned partial, not a new `parse_request` route. Justified: docs have no standalone URL; they belong to their owner's page.
      - **The "write" (download event) is a side-effect of an authenticated read, not a form POST** — it rides the gated-download `api_data` filter, the same shape `CompletionTaskHandler::handleDownloadProof` already uses. Justified: the spec mandates the download endpoint be the single tracking choke point.
      - **A small custom table for the event log** rather than CPT/post-meta — justified: high-volume append-mostly audit data, the same reason `wp_vad_registrations`/`wp_vad_attendance` are custom tables; owned 1:1 by its repository per INV-3.
      - **Storage primitive shared via extraction**, not re-implemented — `ProtectedUploadStorage` base extracted from `CompletionProofStorage` (see Phase 0 / Sibling-site audit). Justified: DRY + the proof pattern already passed security review.

---

## Acceptance flows

Per `netdust-agent:feature-acceptance`. Each row's Edges column is mandatory (the six edge classes: empty/zero · denied actor · wrong-order/re-entry · concurrent/double · boundary value · mid-flow failure). Driven at shake-out through the real browser (Playwright/`use_browser` against the running DDEV site) for UI flows and un-mocked HTTP for the download endpoint.

| # | Flow (intended use) | Actor | Faithful layer | Edges (all six) |
|---|---|---|---|---|
| A | **Admin adds + organizes docs on a course** — opens course edit, Documenten metabox, adds 3 docs via media picker, sets title/description/category, drag-reorders, removes one, saves; reload shows persisted order/metadata | `stride_manage` / course editor | Browser (wp-admin) + DocumentRepository assertion | **empty:** course with 0 docs shows the flat-list empty affordance; **denied:** a `subscriber`/non-`edit_post` user gets no metabox + a forged save is rejected (cap+nonce); **wrong-order:** save before selecting any file persists nothing, no error row; **concurrent:** two admins editing the same course's docs — last-write-wins on the `documents` set, no fatal; **boundary:** crossing 5 docs flips flat-list → grouped-by-category collapse (the ">5" UX rule); **mid-flow:** media upload fails / attachment deleted between pick and save → that id dropped with a notice, others persist |
| B | **Learner views + downloads docs on a course page** — enrolled learner opens the course/onboarding page, sees the Materialen section grouped by category, clicks download, receives the file | enrolled learner | Browser + un-mocked download HTTP | **empty:** course with no docs → Materialen section hidden or "geen materialen" empty state; **denied:** (b1) anonymous visitor sees no download links AND a direct hit on the endpoint/guessable URL is denied; (b2) learner enrolled in onboarding B requests onboarding A's `document_id` → `Geen toegang` (cross-enrollment, Mitigation 3); **wrong-order:** logged-out user clicking a cached link → login redirect / deny; **concurrent:** same user double-clicks download → file served, at most one collapsed event row (Mitigation 11); **boundary:** a 0-byte / very large file still streams via `download()`; a deleted attachment → `Bestand niet beschikbaar`; **mid-flow:** file gone from disk after the doc row exists → detail-free deny + server log, no stack trace to client |
| C | **Learner on an edition page sees course + edition docs merged** — enrolled learner opens an edition page, sees one coherent Materialen list combining the parent course's durable docs and that edition's own docs, visually grouped | enrolled learner | Browser | **empty:** edition with neither course nor edition docs → empty state; **denied:** non-enrolled user sees the section structure but links are gated (storage denies the bytes); **wrong-order:** edition whose course was just deleted → renders edition docs only, no fatal; **concurrent:** admin adds a course doc while learner views → next load reflects it (no stale cache leak across users); **boundary:** a doc that is BOTH on the course and (legacy) on `edition.documents` is not shown twice (dedup by attachment_id in the merge); **mid-flow:** course-doc query errors → edition docs still render (partial degradation, logged) |
| D | **Admin sees download events + LD lesson/quiz progress in the dossier** — admin opens a user's dossier, the onboarding/progress section shows per enrollment: enrolled · documents downloaded (which/when) · e-learning lesson completion · quiz results | `stride_view`+ admin | Browser (admin user-detail) + API | **empty:** enrollment with no downloads + course with no LD lessons/quiz → "nog geen activiteit" per sub-row, not blank; **denied:** a `stride_view`-less user can't reach `getUserDetail` (existing gate); the section never reads a user_id other than the dossier subject (Mitigation 10); **wrong-order:** dossier for a user with a cancelled registration → still shows historical downloads, labelled; **concurrent:** a download happening while the dossier loads → next refresh shows it; **boundary:** a course with a quiz never attempted → quiz row shows "niet afgelegd", a 0% score renders (falsy-zero not dropped); **mid-flow:** `learndash_user_activity` read fails / LD inactive → lessons+quiz degrade to "onbeschikbaar", downloads still render |
| E | **Admin adds docs on a trajectory** — same metabox on the trajectory edit screen, owner_type=trajectory; learner sees them on the trajectory Materialen tab | `stride_manage` + enrolled learner | Browser | **empty:** trajectory with 0 docs → existing tab empty state; **denied:** non-enrolled-in-trajectory user's download denied (`existsForTrajectory`); **wrong-order:** doc added to a trajectory with no child editions yet → still shows on trajectory tab; **concurrent:** two admins → last-write-wins; **boundary:** >5 trajectory docs → grouped view; **mid-flow:** trajectory deleted with docs still attached → orphan docs are not served (owner resolve fails → deny) |

A flow with no edges is incomplete — none here are. At shake-out, `feature-acceptance` drives each row + edge and emits a pass/fail/not-reachable manifest; no UI flow is `pass` without a browser driving it.

---

## File structure

**New files (stride-core):**
- `Infrastructure/ProtectedUploadStorage.php` — abstract base extracted from `CompletionProofStorage`: protected-dir create + `.htaccess`/`index.php` deny, `uploadDirFilter`, `isProtectedPath`, `markProtected` (private status + meta). Subclasses set `DIR_NAME` + their meta keys.
- `Modules/Document/DocumentCPT.php` — `vad_document` registration; `getFields()` is the schema source of truth (INV-3).
- `Modules/Document/DocumentRepository.php` — `extends AbstractRepository`; `findByOwner(string $type, int $id)`, `findByOwners()` for the merge, ordering.
- `Modules/Document/DocumentService.php` — attach-validate-stamp-protect, MIME allow-list, group-by-category/merge logic, the enrollment predicate per owner_type (consumed by the download handler).
- `Modules/Document/DocumentStorage.php` — `extends ProtectedUploadStorage`; `DIR_NAME='stride-documents'`, doc-specific meta.
- `Modules/Document/DocumentDownloadLogRepository.php` — sole `$wpdb` owner of the event table; `recordDownload()`, `findForRegistration()`, schema migrate (mirrors `RegistrationTable`).
- `Modules/Document/DocumentDownloadLogTable.php` — `dbDelta` schema + versioned migrate.
- `Handlers/DocumentDownloadHandler.php` — thin handler; `add_filter('ntdst/api_data/stride_download_document')`; `resolveDownload()` (authz + path guard + anchor) separated from byte-serving for testability.
- `Modules/Document/Admin/DocumentsMetabox.php` — the scaling documents metabox (flat ≤5 / grouped >5), rendered on course + trajectory edit screens; save handler.
- `templates/documents/documents-section.php` — shared plugin-owned render partial (course/edition/trajectory), grouped-by-category cards (INV-5; plugin-owned, not theme).
- `assets/js/admin/documents-metabox.js` — picker + drag-reorder (factored from `edition-admin.js`'s document logic).
- Tests under `tests/Unit/Modules/Document/`, `tests/Unit/Handlers/`, `tests/Integration/`.

**Modified files:**
- `Modules/Enrollment/CompletionProofStorage.php` — refactor to `extends ProtectedUploadStorage` (behavior-preserving; its proof-specific anchor + migration stay).
- `Integrations/LearnDash/LearnDashHelper.php` — add `getQuizAttempts(int $courseId, ?int $userId): array` (reads `learndash_user_activity`, `activity_type='quiz'`, score from `activity_meta`).
- `Admin/AdminUserService.php` — `getUserDetail()` adds the per-registration progress block (downloads + lessons + quiz).
- `plugin-config.php` — register `DocumentCPT`/`DocumentService`/`DocumentDownloadHandler`/`DocumentsMetabox` + `DocumentStorage` migrate hook.
- `web/app/themes/stridence/single-vad_edition.php` + course template + `templates/trajectory/tab-materialen.php` — include the shared documents section (the merge on the edition page).
- `tasks/` admin JS asset enqueue for the metabox.
- `site.yml` — add the `stride-documents` nginx deny rule note.


## Phase 0 — Shared storage extraction (prerequisite, behavior-preserving)

> Extract the storage primitive from `CompletionProofStorage` into `Infrastructure/ProtectedUploadStorage` so the new document storage and the existing proof storage share one audited implementation (DRY; the proof pattern already passed `/security-review`). Behavior-preserving: the proof suite stays green.

### Task 0.1 — Add the INV-1 convergence-point name for gated-file access (doc-only)

**Files:**
- Modify: `ARCHITECTURE-INVARIANTS.md` (INV-1 table + a short note)

This is a documentation task (no code) — it names the new authorization surface so `/code-review`/`/shakeout` can flag bypasses. There is no existing convergence point for "enrollment-gated file access"; proofs and now documents are two instances, so it earns a named row: *"Gated file download → the handler's `resolve*Download()` method decides logged-in + (owner/enrolled OR `stride_manage`); the served path comes only from `get_attached_file()` of a server-resolved row and must pass `ProtectedUploadStorage::isProtectedPath()`."*

- [ ] **Step 1:** Add the row to INV-1's surface table and one sentence under "The rule". `[Tier B]` no unit test: Tier B, documentation only.
- [ ] **Step 2:** Commit.

```bash
git add ARCHITECTURE-INVARIANTS.md
git commit -m "docs(invariants): name the gated-file-download authorization convergence point"
```

### Task 0.2 — Extract `ProtectedUploadStorage` base; reparent `CompletionProofStorage`  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Infrastructure/ProtectedUploadStorage.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/CompletionProofStorage.php`
- Test: `tests/Unit/Infrastructure/ProtectedUploadStorageTest.php`

**Interfaces:**
- Produces: `abstract class ProtectedUploadStorage` with `abstract protected static function dirName(): string;`, and concrete static `getProtectedDir(): string`, `ensureProtectedDir(): true|WP_Error`, `uploadDirFilter(array): array`, `isProtectedPath(string): bool`, `markProtected(int $attachmentId, array $meta = []): void` (sets `post_status=private` + caller-supplied meta).
- Consumes (by `CompletionProofStorage`): the base methods; it keeps `META_REGISTRATION`/`META_PROTECTED`, its `migrate()`, and overrides `dirName()` → `'stride-proofs'`.

**Unit test:** the base's `isProtectedPath()` returns true only for a path under its `dirName()` dir and false for `..`/symlink/outside; `markProtected()` sets `private` status. The denial path (path outside the dir) MUST assert false. RED-first.

- [ ] **Step 1:** Write `ProtectedUploadStorageTest` asserting `isProtectedPath` false for `/etc/passwd` and a `../` escape, true for an in-dir file (use a tmp dir double). Run → FAIL.
- [ ] **Step 2:** Create the base by lifting `getProtectedDir`/`ensureProtectedDir`/`uploadDirFilter`/`isProtectedPath`/`markProtected` from `CompletionProofStorage`, parameterised on `dirName()`.
- [ ] **Step 3:** Reparent `CompletionProofStorage extends ProtectedUploadStorage`; `dirName()` returns `self::DIR_NAME`; keep `markProtected($id,$regId)` as a thin wrapper calling `parent::markProtected($id, [self::META_REGISTRATION=>$regId, self::META_PROTECTED=>1])`; keep `migrate()`/`protectAttachment()` proof-specific.
- [ ] **Step 4:** Run the FULL existing proof suite (`tests/Unit/...Proof*`, `tests/Integration/...Proof*`) → all PASS (behavior-preserving).
- [ ] **Step 5:** `/drift-reviewer` on both files clean. Commit.

**Integration gate (Phase 0):** the existing completion-proof upload→protect→download integration test passes unchanged against the reparented class. `── REVIEW GATE ── (tier: FULL — refactors a security-critical storage primitive that gates PII proof files; a regression re-opens M1–M3)`

---

## Phase 1 — `vad_document` CPT + repository (the data spine)

### Task 1.1 — `DocumentCPT` registration + `getFields()`  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/DocumentCPT.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register CPT on init)
- Test: `tests/Integration/DocumentCPTTest.php`

**Interfaces:**
- Produces: `DocumentCPT::POST_TYPE = 'vad_document'`; `getFields()` returning the A2 schema: `description`(textarea), `category`(text), `attachment_id`(int), `owner_type`(text enum course|edition|trajectory), `owner_id`(int), `order`(int), `visibility`(text, default `enrolled`). `title` is the WP post title (not a meta field). Registered `public=false`, `show_ui=false` (managed via owner metaboxes, no standalone admin list needed for v1), `supports=['title']`, `auto_metabox=false`, `meta_prefix='_ntdst_'`.

**Unit/Integration test:** after register, creating a `vad_document` via `DocumentRepository::create(['title'=>..,'owner_type'=>'course','owner_id'=>5,'attachment_id'=>9])` round-trips through `getField()` with the bare keys (Data API vocabulary). Assert `owner_type` reads back exactly (enum integrity). RED-first.

- [ ] **Step 1:** Write the integration test (register + create + read-back). Run → FAIL (class missing).
- [ ] **Step 2:** Create `DocumentCPT` mirroring `EditionCPT`'s shape; `getFields()` as above.
- [ ] **Step 3:** Register it in `plugin-config.php` init path. Run → PASS.
- [ ] **Step 4:** `/drift-reviewer` clean (vocabulary, no hardcoded prefix). Commit.

### Task 1.2 — `DocumentRepository` (findByOwner / findByOwners / ordering)  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/DocumentRepository.php`
- Test: `tests/Integration/DocumentRepositoryTest.php`

**Interfaces:**
- Consumes: `AbstractRepository` (`find/create/update/delete/getField/all`), `DocumentCPT::POST_TYPE`.
- Produces: `findByOwner(string $ownerType, int $ownerId): array` (ordered by `order` then title); `findByOwners(array $pairs): array` where `$pairs = [['type'=>'course','id'=>5],['type'=>'edition','id'=>9]]` — the multi-owner read the edition-page merge needs; `findByAttachment(int $attachmentId): ?array` (dedup/lookup).

**Unit test:** `findByOwner('course',5)` returns only that course's docs, ordered by `order`; a doc owned by edition 9 is excluded. `findByOwners` returns the union for the merge. Boundary: empty owner → `[]`. RED-first.

- [ ] **Step 1:** Test seeding 3 course docs + 1 edition doc; assert `findByOwner` scoping + order, `findByOwners` union, empty → `[]`. Run → FAIL.
- [ ] **Step 2:** Implement using `$this->model()->where('owner_type',..)->where('owner_id',..)->withMeta()->get()` (the VoucherRepository pattern). Implement ordering in PHP or via the data layer's order.
- [ ] **Step 3:** Run → PASS. `/drift-reviewer` clean (repository-only, no `$wpdb`). Commit.

**Integration gate (Phase 1):** a `vad_document` can be created, scoped-read by owner, and merged across owners through the repository only — zero `$wpdb`/`ntdst_data()` outside it. `── REVIEW GATE ── (tier: FULL — new CPT + data-access path; the owner-scoping read is the substrate every later authorization decision trusts)`

---

## Phase 2 — Protected storage + gated download endpoint + tracking

> The security core. Two review clusters: storage+endpoint, then the event-log write. Both FULL tier (1a surfaces).

### Task 2.1 — `DocumentStorage extends ProtectedUploadStorage`  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/DocumentStorage.php`
- Modify: `plugin-config.php` (init-time `ensureProtectedDir()` + migrate hook), `site.yml` (nginx deny note)
- Test: `tests/Unit/Modules/Document/DocumentStorageTest.php`

**Interfaces:**
- Produces: `DocumentStorage::DIR_NAME='stride-documents'`; `markProtected(int $attachmentId): void` → `parent::markProtected($id, ['_stride_protected_document'=>1])`; `isProtectedPath()` inherited.

**Unit test:** `getProtectedDir()` ends in `/stride-documents`; `isProtectedPath` denies an out-of-dir path (Mitigation 4). Denial path asserted. RED-first.

- [ ] Steps: RED test → implement subclass → ensure dir created at init → add nginx deny line to `site.yml` (Mitigation 1) → PASS → commit.

### Task 2.2 — `DocumentService::attachDocument()` validate + protect (MIME allow-list)  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/DocumentService.php`
- Test: `tests/Unit/Modules/Document/DocumentServiceTest.php`

**Interfaces:**
- Produces: `attachDocument(array $input): WP_Post|WP_Error` — validates `owner_type` enum, `owner_id` is an editable post, `attachment_id` is a real attachment whose MIME is in the allow-list (reject `image/svg+xml`,`text/html`,`application/x-php`, anything not pdf/office/image) → on pass: stamps `DocumentStorage::markProtected()`, routes the file into the protected dir, `create()`s the `vad_document`. Also `mimeAllowList(): array` (shared with the picker JS list).

**Unit test:** an SVG/`text/html`/php attachment → `WP_Error` (Mitigation 8, the DENIAL path); a valid PDF → created + marked protected. Bad `owner_type` → `WP_Error`. RED-first; the rejection assertions are the contract.

- [ ] Steps: RED tests (reject svg/html/php + accept pdf + bad owner_type) → implement with MIME allow-list + `markProtected` → PASS → `/drift-reviewer` clean → commit.

### Task 2.3 — `DocumentService::userMayAccess()` — the enrollment predicate per owner_type  `[Tier A]`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Document/DocumentService.php`
- Test: `tests/Unit/Modules/Document/DocumentAccessTest.php`

**Interfaces:**
- Produces: `userMayAccess(int $userId, array $document): array{allowed: bool, registration_id: ?int}` — the SINGLE place that decides enrollment access AND derives the registration to log against (Mitigation 12). Per owner_type:
  - `course`: find the user's active registration on any edition of that course via `RegistrationRepository` (+ `EditionService::getEditionsForCourse`/`getCourseId`); fallback to `LearnDashHelper::isEnrolled($courseId,$userId)` for pure-LD courses (registration_id null → log against null, allowed true).
  - `edition`: `RegistrationRepository` active registration for (user, edition_id) — the existing `hasActiveRegistration`-equivalent finder (confirm exact method at impl; `findByUser`+status filter exists).
  - `trajectory`: `RegistrationRepository::existsForTrajectory($userId,$trajectoryId)` (the method `pattern_trajectory_edition_parity` added).
- `stride_manage` short-circuits allowed=true (registration_id null) — handled in the handler, not here, so this method is the pure enrollment question.

**Unit test:** enrolled-in-edition-9 user → allowed for a doc owned by edition 9, denied for one owned by edition 12 (cross-enrollment, Mitigation 3 + attack 3). Course-owned doc resolves through the user's edition registration. Both the allow AND the cross-enrollment DENY asserted. RED-first.

- [ ] Steps: RED (allow own-owner, deny cross-owner for each owner_type) → implement using the repository finders → PASS → commit.

**`── REVIEW GATE ── (tier: FULL — Tasks 2.1–2.3: protected storage + the enrollment authorization predicate; a wrong predicate is the cross-enrollment download attack 3, the core boundary the spec names)`**

### Task 2.4 — `DocumentDownloadHandler` — gated download endpoint  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/DocumentDownloadHandler.php`
- Modify: `plugin-config.php` (register handler)
- Test: `tests/Integration/DocumentDownloadHandlerTest.php`

**Interfaces:**
- Consumes: `DocumentRepository`, `DocumentService::userMayAccess()`, `DocumentStorage::isProtectedPath()`, `DocumentDownloadLogRepository::recordDownload()` (Task 2.5), `ntdst_response()->download()`.
- Produces: `add_filter('ntdst/api_data/stride_download_document', [$this,'handleDownload'], 10, 2)`; `resolveDownload(int $documentId): array{path,filename,mime,registration_id}|WP_Error` (authz + path guard, testable, mirrors `resolveProofDownload`); `handleDownload()` calls resolve, records the event (Task 2.5), then `download()` (exits).

**Integration test:** the convergence of Mitigations 3,4,5,9,12 — (a) anonymous → `not_logged_in`; (b) enrolled user, own doc → served + one event row; (c) cross-enrollment user → `forbidden` (same detail-free shape); (d) non-existent/`owner gone` document_id → `forbidden`; (e) attachment path outside protected dir → `forbidden`; (f) `stride_manage` → served regardless of enrollment. RED-first; (c)+(e) are the load-bearing denials.

- [ ] **Step 1:** Write the 6-case integration test. Run → FAIL.
- [ ] **Step 2:** Implement `resolveDownload` (load doc → `userMayAccess` or `stride_manage` → `get_attached_file` → `isProtectedPath` → detail-free `forbidden` on every failure, logged via `ntdst_log('documents')`).
- [ ] **Step 3:** Implement `handleDownload` (resolve → record event → `ntdst_response()->download()`).
- [ ] **Step 4:** Register in `plugin-config.php`. Run → PASS.
- [ ] **Step 5:** `/drift-reviewer` clean (api_data filter not raw wp_ajax; no re-verified nonce). Commit.

### Task 2.5 — Download event log: table + repository + bounded write  `[Tier A]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/DocumentDownloadLogTable.php`, `DocumentDownloadLogRepository.php`
- Modify: `plugin-config.php` (migrate hook)
- Test: `tests/Integration/DocumentDownloadLogRepositoryTest.php`

**Interfaces:**
- Produces: table `wp_vad_document_downloads` (id, user_id, document_id, registration_id NULL, first_at, last_at, count, UNIQUE(user_id,document_id,registration_id)); `DocumentDownloadLogRepository::recordDownload(int $userId,int $documentId,?int $registrationId): void` (upsert: insert or bump count+last_at — Mitigation 11); `findForRegistration(int $registrationId): array`; `findForUser(int $userId): array` (the learner's own view). Sole `$wpdb` owner of the table, `$wpdb->prepare` throughout; versioned `migrate()` mirroring `RegistrationTable`.

**Unit/Integration test:** two `recordDownload` for the same (user,doc,reg) → ONE row, count=2, last_at advanced (Mitigation 11, the concurrent/double edge). `findForRegistration` returns only that registration's rows (Mitigation 10 scoping). RED-first.

- [ ] **Step 1:** Test: double record → one row count=2; cross-registration isolation. Run → FAIL.
- [ ] **Step 2:** Implement table (dbDelta) + repository upsert via `$wpdb->prepare` (`ON DUPLICATE KEY UPDATE`). Wire migrate.
- [ ] **Step 3:** Run → PASS. `/drift-reviewer` clean (table owned 1:1). Commit.

**Integration gate (Phase 2):** an enrolled user downloads → bytes stream + exactly one collapsed event row; a cross-enrollment user is denied detail-free + no row; anonymous denied. `── REVIEW GATE ── (tier: FULL — Tasks 2.4–2.5: the download endpoint is the new authorization surface AND the tracking choke point + a new custom table; promote on any 1a finding)`

---

## Phase 3 — Admin documents metabox (course + trajectory)

### Task 3.1 — Confirm caps + render the scaling metabox (flat ≤5 / grouped >5)  `[Tier B]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Document/Admin/DocumentsMetabox.php`
- Modify: `plugin-config.php` (register; `add_meta_box` on `sfwd-courses` + `vad_trajectory`)
- Test: covered by acceptance flow A/E (browser) — `[Tier B]` no unit test: Tier B, presentational metabox render; the persistence contract is Task 3.2.

**Interfaces:**
- Consumes: `DocumentRepository::findByOwner()`, `DocumentService::mimeAllowList()`.
- Produces: `render(WP_Post $post)` — resolves owner_type from `$post->post_type` (`sfwd-courses`→course, `vad_trajectory`→trajectory); renders flat list when ≤5 docs else grouped-by-category with collapse (the one UI, boundary at 5 per spec). Each row: title/description/category inputs, type·size, drag handle, remove. Picker button + hidden field (mirrors `renderDocumentenTab`). Renders only when `current_user_can('edit_post',$post->ID)` (Mitigation 6).

- [ ] Steps: implement render (reuse `renderDocumentenTab` markup, add metadata inputs + category grouping) → register metabox on both post types → manual/browser check both screens render → commit.

### Task 3.2 — Metabox save handler (cap + nonce + sanitize + MIME)  `[Tier A]`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Document/Admin/DocumentsMetabox.php`
- Test: `tests/Integration/DocumentsMetaboxSaveTest.php`

**Interfaces:**
- Produces: `save(int $postId)` on `save_post` — guards `wp_verify_nonce`/`check_admin_referer` + `current_user_can('edit_post',$postId)` (Mitigation 6); for each posted row sanitizes (`sanitize_text_field` title/category, `sanitize_textarea_field` description, `absint`), delegates to `DocumentService::attachDocument()` (which enforces the MIME allow-list), updates metadata/order on existing docs, and removes deleted ones. Diff-based: reconcile posted set vs `findByOwner`.

**Unit/Integration test:** a save without a valid nonce → no writes (Mitigation 6 DENY); a save with an svg attachment id → that row rejected, others persist (Mitigation 8 via the service); metadata + order round-trip. RED-first; the nonce-fail and svg-reject denials are the contract.

- [ ] Steps: RED (no-nonce → no write; svg → rejected; happy path persists+orders) → implement save with cap+nonce+sanitize+delegate+reconcile → PASS → `/drift-reviewer` clean → commit.

### Task 3.3 — Metabox JS (picker + drag-reorder)  `[Tier B]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/js/admin/documents-metabox.js`
- Modify: metabox enqueue
- Test: acceptance flow A (browser drag-reorder + >5 grouping) — `[Tier B]` no unit test: Tier B, admin JS glue over `wp.media`; verified in the browser at shake-out.

**Interfaces:**
- Consumes: `wp.media` with the type-filtered `library` (the exact list from `edition-admin.js:935`, sourced from `DocumentService::mimeAllowList()` echoed to JS); the hidden-field sync pattern (`edition-admin.js:916-979`).
- Produces: add/remove rows, drag-reorder writing `order`, the ≤5↔>5 grouping toggle.

- [ ] Steps: factor the document logic out of `edition-admin.js` into the new file generalised over a container selector → add drag-reorder (sortable) + category grouping → enqueue on the metabox screens → browser check → commit.

**Integration gate (Phase 3):** an admin adds/orders/removes docs on a course AND a trajectory, crossing the 5-doc boundary into grouped view; a non-editor cannot save. `── REVIEW GATE ── (tier: STANDARD — admin UI + save; the security-bearing save guard rides Task 3.2's FULL-reviewed service, so the cluster itself is STANDARD unless review finds a cap/nonce gap, which promotes it to FULL)`

---

## Phase 4 — Frontend Materialen section (course + edition merge + trajectory)

### Task 4.1 — Shared plugin-owned documents-section partial  `[Tier B]`

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/documents/documents-section.php`
- Test: acceptance flows B/C/E (browser) — `[Tier B]` no unit test: Tier B, presentational partial; the gate logic it calls is unit-tested in 4.2.

**Interfaces:**
- Consumes: an array of document view-models (passed in by the caller) + a `can_download` flag per the gate; renders grouped-by-category cards (icon, `esc_html` title/description, type·size, download button → `esc_url` of the `stride_download_document` endpoint URL). Lives in stride-core (INV-5: plugin never calls the theme); rendered via `ntdst_response()->html()`.
- Produces: one coherent grouped list; empty state when no docs.

- [ ] Steps: build the partial with escaping at every sink (Mitigation 7) → register the template path → render-smoke in browser → commit.

### Task 4.2 — Document view-model + merge/dedup + download-URL builder  `[Tier A]`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Document/DocumentService.php`
- Test: `tests/Unit/Modules/Document/DocumentViewModelTest.php`

**Interfaces:**
- Produces: `getDocumentsForOwners(array $pairs, ?int $userId): array` — calls `DocumentRepository::findByOwners`, builds per-doc view-models (title, description, category, filesize/type from `get_attached_file`, `download_url` = the gated endpoint URL with `document_id`, NOT `wp_get_attachment_url` — Mitigation 1), **dedups by attachment_id** (the edition page's course-vs-edition overlap, flow C boundary), groups by category. The edition-page merge passes `[['course',$courseId],['edition',$editionId]]`; legacy `edition.documents` ids are folded in as a separate group with their public URLs (per Q1 default — flagged).

**Unit test:** merge of a course doc + edition doc → both present, grouped; a doc on BOTH owners appears once (dedup by attachment_id — flow C boundary); `download_url` points at the gated endpoint, never the public attachment URL (Mitigation 1 assertion). RED-first.

- [ ] Steps: RED (merge union + dedup + url-is-gated) → implement → PASS → `/drift-reviewer` clean → commit.

### Task 4.3 — Wire the section into course / edition / trajectory templates  `[Tier B]`

**Files:**
- Modify: `web/app/themes/stridence/single-vad_edition.php`, the course template, `web/app/themes/stridence/templates/trajectory/tab-materialen.php`
- Test: acceptance flows B/C/E (browser)

**Interfaces:**
- Consumes: `DocumentService::getDocumentsForOwners()` + the shared partial via the loader. The enrolled-gate (`hasActiveRegistration`/`existsForTrajectory`) decides `can_download` for link rendering (the storage layer is the real gate).

- [ ] Steps: edition template passes course+edition pair (the merge); course template passes course pair; trajectory tab passes trajectory pair (additive to existing LD materials) → browser-verify the merge shows one list, no double-render → commit.

**Integration gate (Phase 4):** an enrolled learner sees a grouped Materialen list on course + edition (merged, deduped) + trajectory pages; a non-enrolled user's links are gated and the bytes are denied. `── REVIEW GATE ── (tier: STANDARD — frontend render; no 1a surface here, the byte-gate is Phase 2; STANDARD = 2 finders + feature-acceptance browser pass)`

---

## Phase 5 — Dossier progress section (downloads + LD lessons + quiz)

### Task 5.1 — `LearnDashHelper::getQuizAttempts()` (the confirmed gap)  `[Tier A]`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`
- Test: `tests/Unit/Integrations/LearnDash/LearnDashQuizTest.php` (or integration if the activity table is needed)

> **Ground-truth note:** `LearnDashHelper` today has NO quiz-score read (only `getLessons`/`getProgress`/`isComplete`). The spec's "quiz results … exists in LD, needs reading + display" is a real gap. Quiz data lives in `wp_learndash_user_activity` (`activity_type='quiz'`, score in `activity_meta` / `_sfwd-quizzes` user meta) — confirmed by the existing `getLastActivityDate` and `PartnerAPIController` reads of that table. This task adds the read at the sanctioned LD boundary (INV-6), not in a service.

**Interfaces:**
- Produces: `getQuizAttempts(int $courseId, ?int $userId = null): array<array{quiz_id:int,title:string,score:?int,percentage:?int,passed:?bool,completed_at:?int}>` — reads `learndash_user_activity` joined to its `_activity_meta` (or `learndash_get_user_quiz_attempts`/`get_user_meta($u,'_sfwd-quizzes')` whichever the live LD version exposes — confirm at impl via context7/LD source). Returns `[]` when LD inactive (guard like every other method).

**Unit test:** falsy-zero NOT dropped — a 0% / 0-score attempt still appears (flow D boundary, the classic "missing-denial/falsy-zero" trap); LD-inactive → `[]`. RED-first.

- [ ] **Step 1:** Confirm the exact LD read path (context7 `learndash` docs or grep the LD plugin for `get_user_quiz` / `activity_meta` quiz score). Write the RED test (0-score appears; inactive → []).
- [ ] **Step 2:** Implement reading the activity table (mirror `getLastActivityDate`'s prepared query) + meta for score/percentage/passed.
- [ ] **Step 3:** Run → PASS. `/drift-reviewer` clean (LD read stays in the helper). Commit.

### Task 5.2 — Dossier progress block in `getUserDetail`  `[Tier A]`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminUserService.php`
- Test: `tests/Integration/DossierProgressTest.php`

**Interfaces:**
- Consumes: `DocumentDownloadLogRepository::findForRegistration()`, `LearnDashHelper::getLessons()/getProgress()/getQuizAttempts()`, `EditionService::getCourseId()`.
- Produces: each registration in the `getUserDetail` response gains `progress => {downloads:[{document,first_at,last_at,count}], lessons:[{title,completed}], lesson_pct:int, quiz:[...]}` — downloads read by the resolved registration id (Mitigation 10, NOT a free param); lessons/quiz read for the registration's course.

**Integration test:** dossier for user U shows U's downloads for registration R, and does NOT include another user's downloads even if their rows share a document (Mitigation 10 cross-employee DENY); a 0% quiz renders (flow D boundary); LD-inactive degrades to empty lessons/quiz without fatal (flow D mid-flow). RED-first; the cross-employee isolation is the load-bearing denial.

- [ ] **Step 1:** RED test (U sees only U's rows; cross-user isolation; 0-score renders; LD-off degrades). Run → FAIL.
- [ ] **Step 2:** Implement the per-registration progress assembly in `getUserDetail`.
- [ ] **Step 3:** Run → PASS. `/drift-reviewer` clean (read-model service, scoped reads). Commit.

### Task 5.3 — Dossier client render of the progress block  `[Tier B]`

**Files:**
- Modify: the admin user-detail template/JS that renders `getUserDetail` registrations
- Test: acceptance flow D (browser)

`[Tier B]` no unit test: Tier B, presentational; escaping (`esc_html`/Alpine `x-text`) at the sink, verified in the browser at shake-out.

- [ ] Steps: add the onboarding/progress section to the dossier registration rows (downloads list + lesson bar + quiz results), escaped → browser-verify against a seeded enrolled user with downloads + LD progress → commit.

**Integration gate (Phase 5):** a dossier shows enrolled · downloaded-doc · lesson-completion · quiz-score for the right employee only; another employee's data never leaks; 0-scores and LD-off degrade gracefully. `── REVIEW GATE ── (tier: FULL — the dossier surfaces per-employee behavioural PII + a cross-employee read boundary (Mitigation 10) + a new LD-table read; cross-tenant leak risk = FULL)`

---

## Sibling-site audits

Cross-cutting concerns where a change must be swept across every site that shares the pattern (1e):

### Audit S-1 — The `owner_type` enum (`course | edition | trajectory`)
A TS-union-style value used in `DocumentCPT::getFields()`, `DocumentRepository::findByOwner/findByOwners`, `DocumentService::userMayAccess` (the per-type predicate), `DocumentsMetabox::render` (post-type→owner_type), and the view-model merge. **Sweep:** every `switch`/match on `owner_type` must handle all three arms; adding a 4th owner later (or `public` visibility) must update every site. Grep `owner_type` across `Modules/Document/` — each read site must be exhaustive or explicitly default-deny (an unknown owner_type → deny access, not allow).

### Audit S-2 — The MIME allow-list (`DocumentService::mimeAllowList()`)
One source of truth consumed by (a) the save-handler validation (Mitigation 8), (b) the `wp.media` picker `library.type` JS (echoed from PHP, not hardcoded a second time). **Sweep:** the picker list and the server reject-list must be the SAME list — a file the picker offers but the server rejects (or vice-versa) is the drift. Do NOT copy `edition-admin.js:935`'s literal array; source it from `mimeAllowList()`.

### Audit S-3 — The enrollment access-gate, reused across surfaces
`DocumentService::userMayAccess()` is THE convergence point for "may this user get this doc." It is called by the download handler (byte gate) AND informs the frontend `can_download` flag (link gate). **Sweep:** no surface decides doc access by re-deriving enrollment itself (the INV-6b-style trap). Frontend link-gating may use a cheaper enrolled-check for *display*, but the byte gate MUST go through `userMayAccess` — grep for any `hasActiveRegistration`/`existsForTrajectory` call that gates *document bytes* outside the handler.

### Audit S-4 — The gated download-URL (never the public attachment URL)
The `download_url` for a `vad_document` is always the `stride_download_document` endpoint, never `wp_get_attachment_url()`. **Sweep:** grep `wp_get_attachment_url` / `wp_get_attachment_image` across the new code + the templates that render docs — none may point at a `vad_document`'s attachment (Mitigation 1). The legacy `edition.documents` public URLs are the one allowed exception (Q1), and only until adopted.

---

## Deferred (not this iteration)

Per the spec "Deferred" — do NOT build:
- Trajectory → child-edition document mirroring (display rule).
- "Turn a document into e-learning with a quiz" (doc-paired knowledge check).
- Migrating `edition.documents` onto `vad_document` for a single model (additive later; Q1 decides interim).
- `public` document visibility (lead-facing brochures) — the enum reserves the value; no `public` byte-path is built (extending the threat model is required when it is).
- A standalone `vad_document` admin list/CPT UI (`show_ui=false` for v1; managed via owner metaboxes only).

---

## Open questions for Stefan (resolve before/at dispatch)

1. **Legacy `edition.documents` download path.** The new gated endpoint applies to `vad_document` only. Today's `edition.documents` attachments are rendered via PUBLIC `wp_get_attachment_url()` in the metabox + dashboard — i.e. they are NOT gated. Options: (a) **default (this plan):** leave legacy edition docs on public URLs, surface them in the frontend merge as-is, gate only `vad_document`; (b) route legacy edition-doc downloads through the gated endpoint too (a small adapter — they have no `vad_document` row, so the handler would need an edition-attachment branch). The spec defers full migration but the *security* exposure of public edition docs may not be acceptable for an onboarding client. **Recommend (b)-lite if any edition doc is sensitive; (a) if edition docs are non-sensitive slides.** Blocks Task 4.2's legacy-fold + Mitigation 1's exception.
2. **Quiz score storage in the live LD version.** Task 5.1 must confirm the exact read path (`learndash_user_activity.activity_meta` vs `_sfwd-quizzes` user meta vs `learndash_get_user_quiz_attempts()`) against the LD version on the site. Not blocking the plan, but the implementer must verify via context7/LD source at Task 5.1 Step 1 — flagged so it isn't assumed.
3. **`stride_view`-only admins and the download audit (privacy).** The dossier download log is per-employee behavioural data. Confirm `stride_view` (supervisor) seeing every employee's download timestamps is intended, or whether the audit block should require `stride_manage`. Default: follows the existing dossier gate (`stride_view`). Affects Mitigation 10's cap choice.
