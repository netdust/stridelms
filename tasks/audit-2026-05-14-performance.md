# Stride Performance Audit — 2026-05-14

Pre-deep-testing scan by `performance-oracle` agent. Scope: hot paths (admin dashboard, enrollment flow, edition pages, quotes). Excluded: WordPress core, third-party plugins, tests.

**Totals:** 4 High, 5 Medium, 8 Low (informational / already clean).

---

## Status (verified 2026-05-17)

**All HIGH items resolved.**

- ✅ **H1** — Eager DOMPDF render dropped in `QuoteService::createQuote()`; `QuotePDFGenerator::resolveForEmail()` lazy-renders on first email-attachment request. Inline note in the code explains the 300-800ms saving. Async-mail follow-up: verify SMTP send path separately if still synchronous.
- ✅ **H2** — `AdminAPIController::searchUsers()` now primes `update_meta_cache('user', $userIds)` + uses `batchCountUserRegistrations()` (single GROUP BY) instead of per-row count.
- ✅ **H3** — `getUserDetail` quote section uses `BatchQueryHelper::batchGetPostMeta()` (line ~2641).
- ✅ **H4** — `buildCourseTaxonomyJoin` uses `CAST(pm_course.meta_value AS UNSIGNED)` so the indexed bigint side of the join is preserved (line 1175).

Medium + Low items not re-verified — defer to follow-up audit. Code below documents original findings for reference.

---

## HIGH — Fix before launch

### H1 — Enrollment fires synchronous PDF render + admin SMTP send in the user's request thread
- **Files:**
  - `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php:408` (regenerate_pdf dispatch)
  - `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php:77` (admin notify mail)
  - `web/app/plugins/netdust-mail/src/MailService.php:202` (synchronous PHPMailer send, no queue)
- **Class:** Synchronous external call in user-facing flow
- **Impact:** Click "Inschrijven" → `enroll()` → `dispatch('registration/created')` → quote create + DOMPDF render (~300-800 ms) + admin notify SMTP (~200-1500 ms) + customer notify SMTP. **Total user-perceived enrollment latency: 1-3 seconds.** Any SMTP timeout stalls or fails the user's request. PDF eager-render is redundant — `QuotePDFGenerator::resolveForEmail()` already lazy-renders on first attachment request.
- **Fix:** `wp_schedule_single_event(time(), 'stride/quote/regenerate_pdf_async', [$quoteId])` for PDF. Wrap mail dispatches in `wp_schedule_single_event` (within 1-10s window). Drop the eager PDF render in `createQuote`.

### H2 — `searchUsers` N+1 on every admin autocomplete keystroke
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2367-2391`
- **Class:** N+1 (`get_user_meta` + `countUserRegistrations` per result row)
- **Impact:** Each returned user → 1× `get_user_meta('organisation')` + 1× `COUNT(*) FROM registrations`. With 10 results = **20 extra queries per keystroke** on top of the user query. Most frequently-hit admin endpoint.
- **Fix:** After the user query, collect IDs, call `update_meta_cache('user', $ids)` once + one `SELECT user_id, COUNT(*) FROM stride_vad_registrations WHERE user_id IN (...) GROUP BY user_id`. Drops to **3 queries total** regardless of result count.

### H3 — `getUserDetail` quotes section uses unindexed `meta_query OR` + N+1 meta lookups
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2520-2563`
- **Class:** Unindexed OR join + N+1 meta calls
- **Impact:** `meta_query relation => OR` on `billing_email` + `user_id` forces double `LEFT JOIN postmeta` with no covering index. Then 5× `get_post_meta()` per quote × up to 20 quotes = **100 extra meta queries**. The existing `BatchQueryHelper::batchGetPostMeta()` is used in sibling `getQuotes()` but not here.
- **Fix:** Mirror `getQuotes()`: single SELECT with `pm_user`/`pm_email` joins, then one `BatchQueryHelper::batchGetPostMeta($quoteIds, [...])`. Expect drop from ~110 to ~3 queries.

### H4 — Edition agenda taxonomy filter has string→int join when course filter active
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:1123-1156` (`buildCourseTaxonomyJoin`)
- **Class:** Query optimisation — JOIN on `pm_course.meta_value` (varchar) → `tr.object_id` (bigint)
- **Impact:** When admin filters by theme + format + tag simultaneously: 7 joins on `wp_posts × wp_postmeta(date)` base. The course meta_value join is varchar→bigint, killing indexes. ~50 ms at 500 sessions/200 courses; gets worse at scale.
- **Fix:** `CAST(pm_course.meta_value AS UNSIGNED) = tr_theme.object_id` in the join. One-line fix. Or denormalise: store course term IDs as `_ntdst_course_term_ids` meta when edition is saved.

---

## MEDIUM — Watch in production

### M1 — `getActionQueue` LIKE scan on completion_tasks JSON
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:2163-2173`
- **Impact:** `WHERE tasks LIKE '%"completed":false%'` is a full table scan. 5-min transient masks it today; ~30 ms at 5k confirmed regs, ~300 ms at 50k.
- **Fix:** Denormalise `has_incomplete_tasks` tinyint column, set in `CompletionTaskHandler`, index it.

### M2 — Multiple synchronous listeners on `stride/registration/created`
- **Files:** 6 listeners (EnrollmentQuoteHandler, AuditBridge, EditionService, StrideMailBridge admin-notify + customer-trigger, AdminDashboardService transient delete)
- **Impact:** Aggregate enrollment latency. Mail+PDF dominate (see H1); rest is fast.
- **Fix:** Bundled with H1 fix. Mail+PDF go async, audit + capacity + transient stays synchronous (fast + correctness-critical).

### M3 — `EditionService::deleteChildSessions` deletes session-by-session
- **File:** `web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php:318-334`
- **Impact:** 20 sessions × `wp_delete_post()` (~10 ms each) = ~250 ms in admin-initiated edition deletion. Admin-only; not user-facing.
- **Fix:** Bulk `DELETE FROM attendance WHERE edition_id = X` + WP_Query-batched session delete. Or accept it (edition deletion is rare).

### M4 — `getQuotes` search uses double `LIKE` on `wp_users`
- **File:** `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:1435-1446`
- **Impact:** Leading-wildcard `LIKE %term%` against display_name + email, evaluated per quote candidate. ~20 ms at 1k quotes, grows linearly.
- **Fix:** Pre-resolve search to user IDs in one query, then join `pm_user.meta_value IN (ids)`. Or accept — admin search isn't high-traffic.

### M5 — `single-vad_edition.php` makes ~15 queries per public pageview
- **File:** `web/app/themes/stridence/single-vad_edition.php:40-93`
- **Impact:** 8+ service method calls before output. `getRegisteredCount` hits the registrations table on every public pageview (~1 ms each via `idx_edition_status`, but cumulative). No caching.
- **Fix:** Wrap `getRegisteredCount` in a 60-second transient keyed by edition ID. Invalidate from `onRegistrationCreated`/`onRegistrationCancelled` listeners already wired in `EditionService::init()`. Easy win.

---

## LOW — Theoretical or already mitigated

- **L1** `UserDashboardService.php:752 posts_per_page=-1` — bounded by user's own course count. Fine.
- **L2** `EditionService::deleteChildSessions posts_per_page=-1` — bounded by sessions per edition (≤30). Fine.
- **L3** `getStats` — well-batched, ~15 queries regardless of volume. Fine.
- **L4** `getEditions` list + agenda — uses `BatchQueryHelper` thoroughly. ~8 queries per page. Solid.
- **L5** `getUserDetail` registrations section — matches the 13 q / 5 ms benchmark.
- **L6** `getTrajectoriesList` — uses `batchGetPostMeta` + canonical `RegistrationRepository::countByTrajectoryIds`. Recent commit `0f47f48f` verified clean.
- **L7** `getPendingApprovals` — hits `idx_status`. Acceptable; revisit with M1 at scale.
- **L8** **Table indexes** — `stride_vad_registrations` has 7 indexes covering all observed WHERE patterns (`idx_edition_status`, `idx_user_status`, `idx_trajectory_status`). `stride_vad_attendance` has unique key + 3 indexes. **No missing indexes.**

---

## Prioritised action list

1. **H1** — Async PDF + mail in enrollment. Largest user-visible win; biggest risk under SMTP failure. (~1-3 s shaved per enrollment)
2. **H2** — Batch `searchUsers`. Highest-frequency admin endpoint. (20 → 3 queries per keystroke)
3. **H3** — Batch `getUserDetail` quotes section. (~100 → 3 queries on detail open)
4. **H4** — CAST fix on taxonomy join. One-line, defends agenda view at scale.
5. **M5** — 60s transient on `getRegisteredCount`. Cheap, invalidation already wired.
6. **M1** — Denormalise `has_incomplete_tasks` once you cross ~10k confirmed regs.
7. **M2-M4** — Watch only, no action pre-launch.
