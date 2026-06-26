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

---

## Phase 0 — Shared storage extraction (prerequisite, behavior-preserving)

TBD

---

## Phase 1 — `vad_document` CPT + repository (the data spine)

TBD

---

## Phase 2 — Protected storage + gated download endpoint + tracking

TBD

---

## Phase 3 — Admin documents metabox (course + trajectory)

TBD

---

## Phase 4 — Frontend Materialen section (course + edition merge + trajectory)

TBD

---

## Phase 5 — Dossier progress section (downloads + LD lessons + quiz)

TBD

---

## Sibling-site audits

TBD

---

## Deferred (not this iteration)

TBD

---

## Open questions for Stefan

TBD
