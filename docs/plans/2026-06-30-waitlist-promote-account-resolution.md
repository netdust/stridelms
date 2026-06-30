# Waitlist Promote — Account Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Dispatch one `netdust-agent:implementer` per task.

> ## ✅ COMPLETE — shipped + merged to local `main` 2026-06-30 (tip `07a24c08`)
> All 8 tasks implemented, reviewed, and verified. **Unit 1150/0, integration PromoteFromWaitlist 13/13, PHPStan clean.** Gates passed: GATE 1 & 2 FULL (security-sentinel + `/security-review` clean, 0 drift), GATE 3–6 STANDARD, test-effectiveness 8/8 dangerous paths covered + 1 blind-spot fixed, live browser shake-out F0–F3 all `pass` (form renders the offer/invoice field set; promote creates account + maps billing meta + sends one mail; collision links without overwriting + zero mail to existing). Design pivots during execution: (1) field names = `getUserMetaMapping()` input-keys not `billing_*`; (2) welcome mail = SUPPRESS the existing seeded confirm-trigger on collision, not add a sender; (3) offer/invoice fields are NATIVE to the form (Task 8), not questionnaire-builder data. Anonymous-lead promote path = grid bulk-select → "Promoveer" (dossier button serves accounted rows; user-accepted). Follow-ups (non-blocking): dedup the 3 `bulkApi` JS copies; wire/disable the other dossier SMART_ACTION buttons; reconsider `vat_number` required on the public form (conversion).
>
> ## HANDOVER — read first (session-specific context not derivable from this file)
> **Status: ✅ COMPLETE (was: APPROVED + freshness-reviewed, in execution).** Stage 1 (gated plan) complete; Class-B freshness review ground-truthed all premises and applied two plan-corrections: **BLOCKER-1** — waitlist field names must be `getUserMetaMapping()` INPUT-KEYS (`company`/`address`/`postal_code`/`city`/`vat_number`/…), NOT `billing_*` meta keys, or they silently never map; **BLOCKER-2** — the dossier single-promote button was presentational (no handler/route); user chose to WIRE it → new **Task 7 / GATE 5** (STANDARD). Begin at Stage 2 / GATE 1, Task 1. (DRIFT-3 note folded into Task 4: the welcome mail is a NEW `add_action` handler, guard against double-send vs the existing `confirmed` trigger template.)
>
> **Entry path:** Invoke `netdust-agent:harnessed-development` (Class A, executing an existing written plan = Class B freshness review first), then run Stage 1.5 gate-check, then execute GATE 1 → 2 → 3 → 4 in order, HALTing at each `── REVIEW GATE ──` marker. GATE 1 & 2 are **FULL tier** → `security-sentinel` + `/security-review` mandatory.
>
> **Decisions locked (do not re-ask):**
> - Field scope = **(b)** invoice block + organisation/department (see RESOLVED section). No RRN/DOB/license on the public form.
> - `complete_account` task = **removed** (upfront-capture model makes it unnecessary).
> - Both promote surfaces (grid bulk + dossier single) covered via the shared `promoteFromWaitlist` domain method.
> - New account = **active immediately**; welcome/login mail auto-sent **only for newly-created accounts** (never to a matched existing account — M-COLLISION-SAFE / attack 6 & 10).
> - `resolveParticipant()` colleague-enroll collision-unsafe debt = **deferred** (tracked as INV-9 known bypass; do NOT pull into scope).
>
> **Environment gotchas (will waste time if unknown):**
> - **NEVER run the integration suite against the dev/demo DB** — it loads real `wp-load.php` and a table-wide DELETE on `vad_registrations` wipes live enrollments. Gated behind `STRIDE_TEST_DB_DISPOSABLE=1`; use only on a throwaway DB. (See memory `gotcha_integration_suite_wipes_dev_db`.) For non-destructive live verification, use a read-only `wp eval-file` script.
> - **DDEV containers can't see `/tmp/claude-1000/...`** (outside the project mount) — put temp verification scripts inside the project tree (e.g. `./.verify-*.php`) and `rm` after.
> - Tests run via `ddev exec vendor/bin/phpunit --testsuite Unit`; PHPStan via `ddev exec composer lint:stan`; Pint via `ddev exec vendor/bin/pint --test`.
>
> **Related prior work (separate, already shipped):** the anonymous-lead grid blank-name fix (commit `72dcc36e` on `main`) — same "anonymous leads weren't fully handled across admin surfaces" theme, but a distinct fix. This feature is the deeper resolution. See memory `bug_anon_lead_grid_blank_name`.
>
> **Still-open separate item (NOT this plan):** anonymous leads aren't findable by the grid's name-search (`q` only LIKEs `wp_users` columns). Out of scope here; would need a `JSON_EXTRACT(enrollment_data,...)` search.

> **REVISED 2026-06-30** — fundamental design decision: *"collect everything upfront at the waitlist form."* The public waitlist form now captures the FULL personal + invoice/billing field set an offer needs (anonymous, no login). On promote, find-or-create resolves the account FROM that captured data, so there is **no downstream `complete_account` completion task and no profile-completion nudge** — they are removed. The find-or-create-at-promote core, the threat model, INV-9, the anon-branch / event-payload / welcome-mail work, and the test-gap closure all STAY. What changed: the `complete_account` Option-A/Option-B nudge work is deleted; new scope is added on the waitlist form (collect the fields) + the promote path now maps the captured billing/personal data onto a NEW user's usermeta (never onto a pre-existing account).

**Goal:** Collect the FULL offer/invoice information at the public waitlist form upfront (anonymous, no login), so that on admin promote the system resolves a real WP account by email — finding an existing one, or creating one FROM the already-captured full info and mapping that info onto the new user's profile meta — re-links the registration, runs the existing confirm path against the real user, and sends exactly one welcome/login email. Because everything needed for the account + offer/invoice was captured upfront, nothing is missing at promote time and there is NO downstream profile-completion task. Both the grid-bulk and dossier-single promote surfaces are covered through the single shared domain method.

**Architecture (two halves):**
- **Upfront capture (NEW scope).** The public waitlist form is ALREADY questionnaire-stage-driven: `templates/forms/waitlist.php` renders `QuestionnaireRepository::getGroupsForStage($edition, 'waitlist')` as `extra_fields`, `QuestionnaireHandler::handleSubmitWaitlist` sanitizes them (`sanitizeExtraFields`), validates via `QuestionnaireValidator::validate(..., 'waitlist')`, and persists them to `enrollment_data.waitlist.data.*` via `RegistrationRepository::wrapStage`. We extend the `waitlist` stage to collect the personal + invoice/billing field set an offer needs — **REUSING** the existing field-group components (`forms/fields/field-group`), the reserved-name auto-mapping (field names that match `EnrollmentService::getUserMetaMapping()`), the `QuestionnaireValidator`, and the `wrapStage` envelope. No bespoke form components, no new endpoint.
- **Resolve at promote (CORE, mostly unchanged from v1).** All promote traffic (grid `stride_bulk_promote_waitlist`, dossier `stride_promote_waitlist`) routes through `EnrollmentService::promoteFromWaitlist($id)` → `confirmCore()`. When `user_id` is empty: read name/email + the captured billing/personal data from `enrollment_data.waitlist.data`, resolve (find-or-create) a real user, **on the NEW-account branch map the captured meta onto the new user's usermeta** (mirroring the enroll path's `extra_fields → getUserMetaMapping → updateUserProfile`), **never onto a pre-existing account** (mirroring the enroll path's `$isExistingColleague` no-overwrite guard at EnrollmentService.php:973–980), re-link the row, then let the unchanged confirm path run against the real `user_id`. The confirm event payload is enriched with name/email so the welcome mail has a recipient.

**Tech Stack:** PHP 8.3, NTDST Core (DI, Repository, Data API), WordPress (`wp_create_user`, `wp_new_user_notification`, `get_user_by`, `update_user_meta`), the Questionnaire field-group / `QuestionnaireValidator` system, LearnDash via `LMSAdapterInterface`, netdust-mail (`ndmail_send` + `stride/registration/confirmed` trigger), PHPUnit (Unit + Integration suites, DDEV).

## Global Constraints

- All UI/admin/email-facing strings in **Dutch (nl_BE)**; code/identifiers in English. (`stride` text domain.)
- PHP `declare(strict_types=1)`; return `T|WP_Error` at every layer (INV-4). Failure paths return `WP_Error`, logged via `ntdst_log('enrollment')` or bubbled — never `null`/`false`/swallowed.
- All registration-table access through `RegistrationRepository` (INV-3) — no new `$wpdb` outside it.
- LearnDash mutations only through `LMSAdapterInterface` (INV-6) — the bug fix is "grant against a real user," NOT a new LD call.
- The two promote surfaces share ONE domain code path (`promoteFromWaitlist`) — fix the domain method, do not branch per surface (`lesson_pure_passthrough_is_drift`).
- **Account creation maps the upfront-captured personal + billing fields onto the NEW user's usermeta, reusing `EnrollmentService::getUserMetaMapping()` / `updateUserProfile()`** — and writes NOTHING onto a pre-existing account (M-NO-OVERWRITE). Only the reserved-name field set is mapped; non-reserved answers stay per-registration in `enrollment_data`.
- Waitlist-form field collection REUSES the existing questionnaire field-group + validator system; no bespoke field components.
- Tests run under DDEV: `ddev exec vendor/bin/phpunit --testsuite Unit` and `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist`.

---

## RESOLVED (field scope) — user confirmed 2026-06-30: **(b) invoice block + organisation/department**

The waitlist `waitlist`-stage questionnaire groups collect (using the **`getUserMetaMapping()` INPUT-KEYS** as field names — these are the unprefixed business names, NOT the `billing_*` meta keys): `company, address, postal_code, city, vat_number, invoice_email, gln_number` (invoice) + `organisation, department`. These map onto the new user's usermeta as `billing_company, billing_address_1, billing_postcode, billing_city, billing_vat, invoice_email, gln_number, organisation, department` respectively. **Sensitive PII (`national_id`/RRN, `date_of_birth`, `professional_license_number`) is NOT collected on the public waitlist form — deferred to enrollment-time.** Task 5 configures exactly this set; Task 3's round-trip test (case a) asserts these input-key fields map onto the new user's `billing_*`/`organisation`/`department` usermeta via `getUserMetaMapping()`.

> **PLAN-CORRECTION (2026-06-30, freshness review):** The field NAMES must be the `getUserMetaMapping()` INPUT-KEYS (`company`, `address`, `postal_code`, `city`, `vat_number`, `invoice_email`, `gln_number`, `organisation`, `department`), NOT the `billing_*` meta keys. `updateUserProfile()` reads `$data[$inputKey]` keyed by the input-key (`EnrollmentService.php:1145–1148`); a field literally named `billing_vat` is NOT a mapping input-key and would silently stay a per-registration answer, never reaching usermeta. The round-trip test (M-ROUNDTRIP) asserts the MAPPED meta keys (`billing_*`) equal the submitted input-key field values.

<details><summary>Original open question (resolved above)</summary>

The waitlist form will collect the personal + invoice field set. **Exactly which fields?** Two readings:
- **(a) The COMPLETE offer/enrollment field set** — every personal + billing field the enrollment form's step-personal + billing steps collect: `organisation, department, phone, national_id, date_of_birth, professional_license_number` (personal) + `company, address, postal_code, city, vat_number, invoice_email, gln_number` (billing, named by INPUT-KEY) — i.e. the full `getUserMetaMapping()` input-key set.
- **(b) A SUBSET sufficient to make an offer/invoice** — likely the billing block + organisation, but NOT the sensitive personal identifiers (`national_id`/RRN, `date_of_birth`, `professional_license_number`) that a not-yet-enrolled anonymous lead may be reluctant to give a public form (and which are PII the anonymiser tracks — see `pattern_questionnaire_reserved_fields`).

**Recommendation: (b) — the invoice block + organisation, deferring the sensitive personal identifiers to enrollment-time**, because (i) collecting RRN/DOB on a public "join the waitlist" form is a higher privacy bar and a likely abandonment point, and (ii) those identifiers aren't needed to *make an offer*. The plan is written so the field LIST is configuration (questionnaire `waitlist`-stage groups) — Task 1 only needs the answer to know which reserved names to expect; the structure is identical either way. **Please confirm (a) full set, or (b) invoice + organisation.** Tasks below assume (b) and note where (a) changes a row.

</details>

---

## Golden path: form / AJAX / write-flow (deviations must be named and justified)

- [x] Built to `netdust-wp:ntdst-patterns` → `golden-paths/form-data-flow.md` spine (entry-point authz → sanitize → validate → domain service → repository write → event) — read before task breakdown.
- [x] Deviations from the slice (each named + justified):
  - **The public-form half REUSES an existing entry point, not a new one.** `stride_submit_waitlist` already registers on the `ntdst/api_data` registry (framework nonce + Origin/Referer, INV-2) with `nopriv` (anonymous join is intended). We add FIELDS to the `waitlist` questionnaire stage + ensure `handleSubmitWaitlist` sanitizes/persists them — we do NOT add a route. The sanitize+validate+persist spine already exists (`sanitizeExtraFields` → `QuestionnaireValidator` → `wrapStage` → `create/update`); the new fields ride it.
  - **The promote half adds no entry point.** Both promote surfaces already register on `ntdst/api_data/stride_bulk_promote_waitlist` with the `M2 current_user_can('stride_manage')` gate in `BulkRegistrationHandler::denyIfNotManager()`. We change only the **domain method** the handler calls.
  - **The promote-time untrusted input is data-at-rest, not request input.** The name/email/billing we consume at promote was captured by the *public, unauthenticated* waitlist form at submit time and stored in `enrollment_data.waitlist.data`. To the promote flow it is stored data we must NOT trust as already-clean (re-validate the email at promote time — see M-EMAIL-VALIDATE; the field values were sanitized at submit by `sanitizeExtraFields`/`QuestionnaireValidator`, and `updateUserProfile` re-`sanitize_text_field`s on write).
  - **Account creation is a write to `wp_users` + usermeta, not a CPT/registration write** — `wp_create_user` + `update_user_meta`, wrapped by domain methods. Justified: there is no Stride "user" repository; `wp_users`/usermeta is WP-core territory, and the meta mapping is the existing `getUserMetaMapping()`/`updateUserProfile()` convergence.

---

## Threat model

> **Context.** This feature makes an admin action (`stride_manage`-gated promote) *create or attach a WordPress user account* — **now with personal + invoice/billing usermeta** — using data that originated from an **unauthenticated public waitlist form**, then grant a course and email an externally-supplied address, in **bulk**. The richer captured data sharpens one mitigation: the existing-account branch must NOT overwrite a real user's billing meta with a stranger's lead values. Written 2026-06-30, REVISED same day for the upfront-capture model. This section is the convergence target: `/code-review` + `security-sentinel` verify against the numbered mitigations, not free-form.

### What we're defending

1. **Existing WP user accounts AND their stored billing/personal meta** — a real account whose email a stranger typed into the public form must not silently receive a course grant, a "welcome/login" email, **or have its `billing_*` / personal usermeta overwritten** by the lead's (possibly attacker-chosen) values. (NEW emphasis under the upfront model: billing meta is now in play.)
2. **The `stride_manage` capability boundary** — only a coordinator/administrator may promote, therefore only they may trigger account creation this way. No `nopriv` path, no lower role, may reach `promoteFromWaitlist`.
3. **Outbound email reputation + the welcome/login link** — exactly one welcome email per genuinely-new lead, to a validated recipient. A login/password-set link must never be sent to a *pre-existing* account on this path.
4. **The registration row's `user_id` ↔ account binding** — once re-linked, the row's `user_id` is load-bearing (completion-task auth, `getPendingForUser(WHERE user_id=%d)`, the user dashboard scoping). A wrong binding leaks one lead's dossier — now including captured billing data — to another account.
5. **The captured invoice/personal data integrity (NEW).** The billing/personal fields captured upfront drive the offer/invoice. They must round-trip intact from `handleSubmitWaitlist` → `enrollment_data.waitlist.data.*` → (new-account) usermeta, sanitized, with no field-name drift that silently drops a value.
6. **Availability of the bulk action** — one admin click resolves N accounts (+ writes N×meta) + sends N emails. A malformed batch, a row that throws mid-loop, or a slow mailer must not abort the whole batch.

### Who we're defending against

- **A1 — Unauthenticated public-form abuser (IN scope).** Submits the public waitlist form for any edition with a victim's name+email **and arbitrary billing data** (or garbage/malformed values). The payload sits in `enrollment_data` until an admin promotes it.
- **A2 — A `stride_view`-only / non-manager admin (IN scope).** Must not be able to promote.
- **A3 — The promoting coordinator (PARTIALLY in scope).** Trusted to promote; protected from *unknowingly* attaching a stranger's lead (or its billing data) to a real account. Not defended against a malicious coordinator.
- **A4 — A WP administrator (OUT of scope).** Full trust.
- **A5 — Network MITM on the welcome email (OUT of scope).**

### Attacks to defend against

1. **A1 → unsolicited enrollment / account griefing via email collision.** A1 submits the public form using a *real existing user's* email. On promote, naive find-or-create resolves to that account, grants them a course, and emails a "welcome/login" mail for an enrollment they never made.
2. **A1 → account-enumeration oracle.** New-account-created vs. linked-to-existing behaviour reveals to the coordinator whether an email already has an account. Low severity (admin-only observer).
3. **A1 → stored-XSS / header injection via captured fields.** The `name` and now the `billing_*` text fields from the public form render later on admin dossier surfaces, the offer/quote, and the welcome email. Unsanitized storage or raw re-emit is an injection sink.
4. **A1 → malformed/missing email DoS-of-row.** A lead with absent/empty/invalid `email` must fail *that row* gracefully (into `failed[]`) — no junk user, no batch abort, no `wp_mail` to an empty recipient.
5. **A2 → privilege escalation to account creation.** A non-manager reaching `promoteFromWaitlist` gains an account-creation + course-grant + outbound-mail primitive.
6. **A1/A3 → welcome-link sent to a pre-existing account (credential-path leak).** `wp_new_user_notification(...,'both')` (password-set link) to an already-existing account hands a reset link to whoever controls that mailbox for an unsolicited event.
7. **A3 → double grant / re-link / re-map of an already-confirmed or already-accounted row.** Promoting twice, or a row with a `user_id`, must not create a second account, re-grant, re-send, or re-write meta.
8. **A1 → mid-flow half-state.** `wp_create_user` (NOT rollback-able) succeeds + the capacity transaction rolls back → must not leave a row "promoted with no user" nor "user created but row silently lost."
9. **Concurrent double-promote (capacity race) after the account step.** The `FOR UPDATE` capacity guard must still hold after the account-resolution step is inserted before the transaction.
10. **A1 → billing-meta poisoning of an existing account (NEW — the sharpest under the upfront model).** A1 submits the public form with a *real existing user's* email and **attacker-chosen billing data** (a different VAT number, address, invoice_email). If promote's account-creation maps the lead's `billing_*` onto the resolved user's usermeta, it **overwrites the real user's invoice details** — corrupting their next offer/invoice, or redirecting `invoice_email`. This is a data-integrity + potential invoice-fraud vector, and it exists ONLY because we now carry billing data. The existing-account branch must write NOTHING to that account.

### Mitigations required

1. **M-COLLISION-SAFE — reuse never sends credentials, never escalates.** `resolveLeadAccount()` returns `['user_id'=>int, 'was_existing'=>bool]`: **(a) found existing** → return ID, `was_existing=true`, NO `wp_new_user_notification`; **(b) created new** → `was_existing=false`, minimal active account, welcome mail allowed. Code-checkable: the existing-user branch returns before any notification call.
2. **M-NO-OVERWRITE — attaching to an existing account writes NOTHING to that account: not name, not roles, AND NOT billing/personal usermeta (NEW).** On `was_existing===true` the promote path sets ONLY the registration row's `user_id`. It does NOT `wp_update_user`, does NOT `update_user_meta`, does NOT call `updateUserProfile`. The lead's captured billing data stays per-registration in `enrollment_data` (available to the offer for *that registration*) but never mutates the existing user's profile. **This directly mirrors the enroll path's existing `$isExistingColleague` guard (EnrollmentService.php:973–980), which already folds profile fields back to per-registration-only for an existing colleague.** Closes attack 10. Code-checkable: grep the `was_existing` branch for any `update_user_meta`/`wp_update_user`/`updateUserProfile` — must be empty.
3. **M-META-MAP — new-account billing/personal meta is mapped via the existing convergence (NEW).** On `was_existing===false`, map the captured `enrollment_data.waitlist.data.*` reserved-name fields onto the new user via `EnrollmentService::updateUserProfile($newUserId, $capturedData)` (which re-`sanitize_text_field`s and uses `getUserMetaMapping()`). Do NOT hand-roll a second meta-mapping. Code-checkable: the new-account meta write goes through `updateUserProfile`, keyed by `getUserMetaMapping()`, not ad-hoc `update_user_meta` calls.
4. **M-NAME-SANITIZE — name + text fields sanitized in, escaped at every sink.** `name`/`billing_*` were sanitized at submit (`sanitizeExtraFields`/`QuestionnaireValidator`); re-sanitized on write by `updateUserProfile`/`wp_update_user`. The welcome-mail + dossier + offer escape at output (existing `esc_html`/`ndmail` templates). No raw field reaches an HTML/header sink. Code-checkable.
5. **M-EMAIL-VALIDATE — re-validate the stored email at promote time.** `$email = sanitize_email((string)($data['email'] ?? ''));` then `if (!is_email($email)) return new WP_Error('lead_no_email', ...)` BEFORE any user creation. Code-checkable: guard precedes `wp_create_user`.
6. **M-CAP-GATE — capability enforced once, at the handler, before the loop.** Unchanged: `denyIfNotManager()` returns `WP_Error` before `runBulk()` (INV-1, M2). No new entry point (INV-2 grep stays empty). Tested (A2).
7. **M-NEW-USER-MAIL-ONLY — credential/welcome email sent ONLY on the new-account branch, to the validated address, exactly once.** Keyed on `was_existing===false`. Sent via the enriched `stride/registration/confirmed` trigger (not a second `wp_new_user_notification`). Code-checkable: one mail-producing path on success; existing-branch has none.
8. **M-IDEMPOTENT — account step is a no-op when `user_id` is already set; status guard blocks re-promote.** Resolve+map run ONLY inside `if ((int)$registration->user_id === 0)`. A non-`waitlist` status is already rejected `invalid_status`. Code-checkable.
9. **M-SEQUENCE — account + meta-map + re-link BEFORE the capacity transaction; the transaction only flips status.** Order: (1) status/edition guards, (2) IF anon: validate email → resolve account → (new-account only) map meta → re-link `user_id` (committed standalone), (3) re-`find()` the row, (4) capacity `FOR UPDATE` transaction flips status, (5) `confirmCore` grants + fires enriched event + welcome mail. A step-4 rollback leaves the row `waitlist` but carrying a `user_id` (benign, idempotent retry via M-IDEMPOTENT). No created user is ever left with zero registrations. Code-checkable: `wp_create_user`/`updateUserProfile` are NOT inside `START TRANSACTION … COMMIT`.
10. **M-PER-ROW — every failure is per-row, into `failed[]`, never an aborted batch.** Unchanged bulk runner. `promoteFromWaitlist` returns `WP_Error` (never throws) on each failure class; malformed lead / create failure / capacity rejection land in `failed[]` with distinct codes.
11. **M-RACE-HOLDS — the capacity `FOR UPDATE` guard is unchanged and still wraps only the status flip.** The pre-transaction account+meta step does not widen the transaction; the existing race test passes unchanged.
12. **M-ROUNDTRIP — the captured field keys round-trip without drift (NEW).** The field NAMES `handleSubmitWaitlist` persists into `enrollment_data.waitlist.data.*` are the SAME INPUT-KEYS `getUserMetaMapping()` reads at promote. A new waitlist field whose name does not match a mapping input-key stays a per-registration answer (correct) — but a field INTENDED to map (e.g. the VAT number) must use the exact mapping input-key `vat_number` (NOT `billing_vat`, which is the META key and would never map). Code-checkable + a Tier-A round-trip test: submit a waitlist row with input-key-named billing fields (`company`, `vat_number`, …) → promote new account → assert the new user's `billing_*` usermeta (`billing_company`, `billing_vat`, …) equals the submitted values.

### Out of scope (explicit deferrals)

- **DNS/identity proof that the lead email belongs to the submitter.** Coordinator is trusted to recognize a real lead. Residual risk bounded by M-COLLISION-SAFE / M-NO-OVERWRITE (no credentials, no meta-overwrite on an existing account). A future UI confirm ("email matches an existing account — link?") is noted, not built.
- **Account-enumeration oracle (attack 2).** Admin-only observable; not defended. Low severity.
- **Rate-limiting bulk account creation beyond the existing `MAX_BATCH` cap.**
- **Retroactive adoption of anonymous rows on self-registration/login.** No `user_register`/`wp_login` claim hook (confirmed none exists); resolution happens at promote only.
- **The colleague-enroll `resolveParticipant()` collision-unsafe debt.** User confirmed DEFER. It sends `wp_new_user_notification(...,'both')` to every resolved user incl. pre-existing — tracked as the INV-9 known bypass, NOT fixed here. (Its sibling no-overwrite guard for *meta* already exists at :973–980; the *mail* unsafety is the deferred part.)

### How to use this section

- Controller pre-flight: verify M-COLLISION-SAFE, **M-NO-OVERWRITE (incl. billing meta)**, M-META-MAP, M-EMAIL-VALIDATE, M-NEW-USER-MAIL-ONLY, M-SEQUENCE, M-ROUNDTRIP are present in the plan-supplied code before dispatching the account-resolution + meta-map task.
- `/code-review` + `security-sentinel`: "Verify the diff against mitigations M1–M12. Report each in-place / missing / out-of-scope per the deferrals." FULL-tier surface (admin-triggered account creation + billing-meta write from attacker-influenceable data) — `/security-review` fires.
- `/evaluate` retros: any unimplemented mitigation is a plan-correction defect.

---

## WP security requirements (per data-flow)

> Pillars defined in `netdust-wp:wp-security`. One line per flow; n/a stated explicitly.

- [x] **Public `stride_submit_waitlist` (`handleSubmitWaitlist`, nopriv) — NEW fields.** authorize: anonymous by design (nopriv); framework nonce + Origin/Referer already gate it (INV-2). validate: `QuestionnaireValidator::validate($extraFields, $editionId, 'waitlist')` enforces the stage schema (required/type) — the new billing fields ride this. sanitize: `sanitizeExtraFields()` already sanitizes each `extra_fields` value before persist; confirm it covers the new field types. escape: n/a at submit (no output); escaped at every later sink (dossier/offer/mail). write: persisted via `wrapStage` → `RegistrationRepository::create/update` (INV-3).
- [x] **Domain `promoteFromWaitlist($id)` (via the gated bulk handlers).** authorize: `current_user_can('stride_manage')` upstream (INV-1); no second entry/nonce (INV-2). validate: `is_email()` on the lead email + status/edition guards. sanitize: `sanitize_email` on the email; `updateUserProfile` re-`sanitize_text_field`s the mapped meta. escape: n/a (no output).
- [x] **`resolveLeadAccount($email, $name)` → `wp_create_user` / `get_user_by`.** authorize: n/a (internal). validate: `is_email()` precondition. sanitize: `sanitize_email`, `sanitize_text_field`, `sanitize_user`. escape: n/a. Credentials: `wp_generate_password(16,true,true)`; never logged/returned.
- [x] **New-account meta map → `updateUserProfile($newUserId, $capturedData)`.** authorize: n/a (internal, new-account branch only). validate: only `getUserMetaMapping()` keys mapped; gated on `was_existing===false`. sanitize: `updateUserProfile` `sanitize_text_field`s each value. escape: n/a.
- [x] **`RegistrationRepository::attachUserToWaitlistRow($id, $userId)`.** authorize: n/a (internal). validate: caller guarantees `$userId>0`. sanitize: `%d` bind. write: `$wpdb->update` with format specifiers (INV-3).
- [x] **Enriched `stride/registration/confirmed` payload.** authorize: n/a. validate: name/email added only when present+valid. sanitize: name sanitized at resolve. escape: mail template escapes — n/a here.

---

## ntdst-core layering requirements

> Same nine categories `ntdst-drift-reviewer` checks. Canonical defs in `netdust-wp:ntdst-architecture`. Only applicable rows kept.

- [x] Registration-table access through `RegistrationRepository` — `attachUserToWaitlistRow` lives in the repo; no new `$wpdb` in `EnrollmentService` (INV-3).
- [x] No pure pass-through Service method — `resolveLeadAccount` adds validate + find-or-create + no-credential-on-existing; the promote anon branch adds resolve + conditional meta-map. Neither is a pass-through.
- [x] No raw `wp_ajax_*` handler — both halves reuse existing `ntdst/api_data/*` filters (INV-2).
- [x] No swallowed `WP_Error` — new failure paths return `WP_Error`, logged on `enrollment` or bubbled (INV-4).
- [x] LearnDash grant stays inside `confirmCore` via `LMSAdapterInterface` — fix is "grant against a real user_id" (INV-6).
- [x] Meta write reuses `getUserMetaMapping()`/`updateUserProfile()` — no hardcoded `_ntdst_`/`billing_*` meta keys outside the existing mapping (INV-3 sub-rule).
- [x] Correct module layering — domain in `EnrollmentService`, table write in `RegistrationRepository`, form in `QuestionnaireHandler`/theme template, mail in `StrideMailBridge`. No logic added to thin handlers.
- [x] No new service class — existing services + the questionnaire stage config edited in place.

> **The convergence contract.** These blocks + the `## Threat model` are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named golden-path slice + pillars + categories + numbered mitigations — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Architecture invariants touched

> Per `ARCHITECTURE-INVARIANTS.md` (project root). Touches existing convergence points AND introduces one.

- **INV-1 (Authorization).** Promote stays gated by `current_user_can('stride_manage')`. The public waitlist submit stays `nopriv` by design (anonymous join). No new cap. ✔
- **INV-3 (Data access).** The `user_id` re-link is a `RegistrationRepository` write; the meta map reuses `getUserMetaMapping()`/`updateUserProfile`; the form persist reuses `wrapStage`/`create`. No new raw `$wpdb`, no hardcoded meta keys. ✔
- **INV-4 (Errors).** New failure classes (`lead_no_email`, `account_create_failed`) are `WP_Error`, logged on `enrollment`. ✔
- **INV-6 (LearnDash).** Orphan-grant fixed by a real `user_id`, not an adapter change. ✔
- **INV-7 (Effective status).** Terminal-edition gate stays ahead of the account step. ✔
- **NEW — INV-9 (Anonymous-lead → account resolution is decided in ONE place).** **Convergence point:** `EnrollmentService::resolveLeadAccount(string $email, string $name): array{user_id:int, was_existing:bool}|WP_Error`. **The rule:** any path that turns an anonymous pre-account registration (`user_id` NULL/0 from the public interest/waitlist forms) into a real account does so through this method — find-or-create-by-email, sanitize+validate, no-credentials-on-existing. **Meta-on-create reuses `getUserMetaMapping()`/`updateUserProfile()`; meta is NEVER written to a found existing account** (the same `$isExistingColleague` rule the enroll path already encodes at :973–980 — the two paths must not diverge on this). The pre-existing `upgradeFromInterest()` self-enroll path is the **sibling** that must not drift. **Known bypass (tracked debt, user-confirmed deferred):** `resolveParticipant()` (colleague-enroll) still does inline find-or-create with `wp_new_user_notification(...,'both')` to every resolved user incl. pre-existing — the collision-unsafe *mail* pattern INV-9 exists to prevent spreading. Task 1 deliberately creates `resolveLeadAccount` as the *safe* sibling rather than reusing `resolveParticipant`. **Audit move:** `grep -rn "wp_create_user\|wp_new_user_notification" web/app/mu-plugins/stride-core` — every hit inside `resolveLeadAccount` or the tracked `resolveParticipant` bypass; a third site is a finding.

> **Doc update task:** Task 6 appends INV-9 to `ARCHITECTURE-INVARIANTS.md`.

---

## Tension resolutions (decided in this plan)

### Tension #1 — Where account-creation lives → **new `resolveLeadAccount()`, NOT extend `resolveParticipant()`.**
`resolveParticipant()` calls `wp_new_user_notification($id, null, 'both')` for *every* resolved user incl. a pre-existing one — the collision-unsafe credential leak (M-COLLISION-SAFE / attack 6). We add a separate, collision-safe `resolveLeadAccount()` returning `{user_id, was_existing}` that sends NO mail itself, and establish the INV-9 convergence point. `resolveParticipant` is left untouched (INV-9 known bypass, user-confirmed deferred).

### ~~Tension #2 — the `complete_account` nudge~~ → **REMOVED by the upfront-capture decision.**
The 2026-06-30 design decision is "collect everything upfront at the waitlist form." Because the personal + invoice data is captured at submit and mapped onto the new account at promote, **nothing is missing at promote time** — there is no downstream profile to complete. **Both the Option-A formal `complete_account` task type AND the Option-B dashboard nudge are deleted**, along with any `completion_tasks` type-set edits and any profile-nudge dashboard work. Course access was always going to be active-immediately; now there is also nothing to nudge. (Superseded; retained here only as the record of why no nudge exists.)

### Tension #2 (NEW) — Upfront field capture: REUSE the questionnaire `waitlist` stage, don't build a form.
Ground-truthed: `templates/forms/waitlist.php` already renders `getGroupsForStage($edition,'waitlist')` and `handleSubmitWaitlist` already sanitizes→validates→persists `extra_fields` into `enrollment_data.waitlist.data.*`. So "collect full info upfront" is **questionnaire stage configuration + confirming the persist/round-trip**, not new form plumbing. The reserved-name auto-mapping (`pattern_questionnaire_reserved_fields`) means a waitlist field named `billing_vat` (etc.) is already a recognized mapping key. **Decision: add the billing/personal field set to the `waitlist` stage groups; the form template + handler need no structural change beyond confirming `sanitizeExtraFields` covers the field types and the round-trip is intact (M-ROUNDTRIP test).** The only open input is the field LIST (see OPEN QUESTION).

### Tension #3 — Transactionality → **M-SEQUENCE** (account + meta-map + relink BEFORE the capacity transaction; transaction flips status only; half-state benign-idempotent).

### Tension #4 — Mail (exactly one, valid recipient) → **enrich `stride/registration/confirmed` with `name`+`email`; welcome mail via the existing trigger, gated on `was_existing===false`.**
`confirmCore` fires the event with only `[registration_id, user_id, edition_id]`; for `user_id=0` the trigger resolves NO recipient. The sibling `sendUserStageMail` (StrideMailBridge:131–160) already handles the dual shape (logged-in vs anonymous name/email-in-payload) and the `interest_registered`/`waitlisted` events already carry name+email. The `confirmed` event is the only stage-mail that doesn't. Fix: add name/email to the confirmed payload when account-resolved; gate the welcome send on `was_existing===false` (no spam to a pre-existing account). One email, valid recipient, no double-send.

---

## Sibling-site audit

> Cross-cutting concern: the anonymous-lead → account paths AND the existing-account no-overwrite rule must not drift. Audit at plan-time AND at shake-out.

- [x] **`resolveParticipant()` (colleague-enroll) vs new `resolveLeadAccount()`.** Both find-or-create-by-email. `resolveParticipant` mails every user incl. pre-existing (collision-unsafe). `resolveLeadAccount` must NOT — safe sibling + INV-9 home. Reviewer confirms the divergence is intended (deferred debt), not an accidental copy.
- [x] **The enroll path's `$isExistingColleague` no-overwrite guard (EnrollmentService.php:973–980) vs the new promote `was_existing` meta guard.** BOTH must encode the same rule: *do not write profile/billing meta onto a pre-existing account; keep the values per-registration.* The promote path mirrors this for the waitlist lead. Reviewer confirms the two guards agree and neither is missing the billing-meta case (attack 10 / M-NO-OVERWRITE).
- [x] **`upgradeFromInterest()` vs new `attachUserToWaitlistRow()`.** `upgradeFromInterest` resets `registered_at` + forces status/path. The waitlist re-link must PRESERVE `registered_at` and NOT change status (the transaction owns the flip). Hence a separate minimal `attachUserToWaitlistRow($id,$userId)` setting only `user_id`. Reviewer confirms no `registered_at`/status reset.
- [x] **`interest_registered`/`waitlisted` events (carry name+email for anon) vs `confirmed` event (doesn't).** Task 3's enrichment makes the confirmed dispatch a sibling of the existing anon-aware stage events. Reviewer confirms the payload shape matches what `sendUserStageMail` expects (`name`, `email`, `user_id`, `edition_id`).
- [x] **JSON path `enrollment_data.waitlist.data.{name,email,billing_*}`.** The promote extractor must read the SAME path `handleSubmitWaitlist`/`wrapStage` writes and `findAnonymousForEmailAndEdition` reads (`$.waitlist.data.email`). Reviewer greps the write site (`wrapStage`), the email-find site, and the promote read site all agree on the path + the reserved field names (M-ROUNDTRIP).

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` | `resolveLeadAccount()` (new, INV-9 home), anon branch in `promoteFromWaitlist()` (resolve → new-account meta-map via `updateUserProfile` → re-link), enriched `confirmCore` event | Modify |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` | `attachUserToWaitlistRow($id, $userId)` (new, minimal user_id-only write) | Modify |
| `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php` | confirmed-event handler consumes enriched `name/email`; gate welcome send on `was_existing` | Modify |
| **Waitlist questionnaire `waitlist`-stage field config** — admin questionnaire builder data, NOT code (the field groups for the `waitlist` stage); verify the field set persists + round-trips. `templates/forms/waitlist.php` + `QuestionnaireHandler::handleSubmitWaitlist` confirmed to need NO structural change (they already render/sanitize/persist any configured group). | Configure + verify (Task 5) | Configure/verify |
| `ARCHITECTURE-INVARIANTS.md` | Append INV-9 | Modify |
| `tests/Unit/Modules/Enrollment/ResolveLeadAccountTest.php` | Tier-A unit tests (new/existing/malformed/collision-safe) | Create |
| `tests/Integration/PromoteFromWaitlistTest.php` | Anon-lead promote cases (new account + meta-map round-trip, reuse + NO meta-overwrite, malformed, half-state) | Modify |
| `tests/Unit/Handlers/BulkRegistrationHandlerTest.php` | Anon-row bulk promote + per-row malformed isolation + denial | Modify |

---

## Tasks

> Test-tier per `testing-workflow`. Account-resolution + collision/denial + the new billing-meta no-overwrite + the round-trip are **Tier A, RED-first**. Repo write is seam-tested. Form-field capture is a config + Tier-A round-trip assertion (the round-trip is the bug-catching part).

### ── REVIEW GATE 1 ── (tier: FULL — creates WP accounts + writes billing usermeta from attacker-influenceable data + re-links the auth-bearing user_id; the core 1a surface incl. attack 10) — Tasks 1–3

### Task 1: `resolveLeadAccount()` — collision-safe find-or-create (INV-9 home)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`
- Test: `tests/Unit/Modules/Enrollment/ResolveLeadAccountTest.php` (create)

**Interfaces:**
- Produces: `private resolveLeadAccount(string $email, string $name): array|WP_Error` returning `['user_id'=>int, 'was_existing'=>bool]`. (Note: this method resolves the ACCOUNT only — meta-mapping happens in the promote branch, Task 3, so the no-overwrite decision is made where `was_existing` is consumed.)

**Tier: A** — find-or-create + collision-safe credential decision + email validation. RED-first.
**Test contract:** (a) unknown email → creates active user, `was_existing=false`, name set; (b) **existing email → returns that ID, `was_existing=true`, NO `wp_new_user_notification`** (denial-of-credential — M-COLLISION-SAFE/M6); (c) malformed/empty email → `WP_Error('lead_no_email')`, no user created (M-EMAIL-VALIDATE).

- [x] **Step 1: Write failing tests** (stub `wp_create_user`/`get_user_by`/`wp_new_user_notification`; assert the notification stub is called 0× on the existing-user + email-validate branches).
- [x] **Step 2: Run — expect FAIL** (`method not found`). `ddev exec vendor/bin/phpunit --filter ResolveLeadAccount --testsuite Unit`
- [x] **Step 3: Implement** `resolveLeadAccount`: `sanitize_email` → `is_email` guard (`WP_Error('lead_no_email', __('De wachtlijst-aanmelding heeft geen geldig e-mailadres.', 'stride'))`) → `get_user_by('email')` → if found return `['user_id'=>$u->ID,'was_existing'=>true]` (NO notification, NO meta write) → else unique `sanitize_user` username, `wp_generate_password`, `wp_create_user` (on `WP_Error` return `WP_Error('account_create_failed', ...)` logged on `enrollment`), `wp_update_user` first/last/display from `sanitize_text_field($name)`, return `['user_id'=>$id,'was_existing'=>false]`. **No mail, no billing meta here.**
- [x] **Step 4: Run — expect PASS** (full Unit suite green).
- [x] **Step 5: Commit** `feat(enrollment): collision-safe resolveLeadAccount (INV-9)`.

`Risk this test does NOT cover: the real wp_create_user duplicate-username race + the billing-meta no-overwrite (that lives in Task 3) — deferred to /integration (Task 3).`

### Task 2: `RegistrationRepository::attachUserToWaitlistRow()` — minimal user_id re-link

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php`
- Test: behavioral contract asserted in Task 3 integration (needs the real table).

**Interfaces:**
- Produces: `public attachUserToWaitlistRow(int $registrationId, int $userId): bool` — `$wpdb->update` setting ONLY `user_id` (`%d`), `clearCache()`, `emitRowEvent('row_updated', ...)`. Does NOT touch `status`, `registered_at`, `enrollment_path`, `enrollment_data`.

**Tier: A (contract)** — the preserve-`status`/`registered_at` guard is the sibling-drift risk; asserted at integration in Task 3.
**Test contract (in Task 3):** after attach, `status` still `waitlist`, `registered_at` unchanged, `user_id` set.

- [x] **Step 1:** Add the method (mirror `updateCompletionTasks` shape: `$wpdb->update($this->table(), ['user_id'=>$userId], ['id'=>$registrationId], ['%d'], ['%d'])`, `clearCache()`, `emitRowEvent`).
- [x] **Step 2: Commit** `feat(enrollment): attachUserToWaitlistRow repo write`.

`no unit test: Tier B for the isolated write (pure $wpdb->update wrapper, no branching); its behavioral contract (preserves status/registered_at) is Tier A and asserted in Task 3. Risk deferred to /integration Task 3.`

### Task 3: Anon branch in `promoteFromWaitlist()` — resolve → new-account meta-map → re-link (+ integration: the sequence, the round-trip, the no-overwrite)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`
- Test: `tests/Integration/PromoteFromWaitlistTest.php`

**Interfaces:**
- Consumes: `resolveLeadAccount()` (T1), `attachUserToWaitlistRow()` (T2), the existing `updateUserProfile()` + `getUserMetaMapping()`.
- Produces: `promoteFromWaitlist` handles `user_id === 0` rows incl. billing-meta mapping on the new-account branch.

**Tier: A** — the full sequence (M-SEQUENCE) + the new billing-meta no-overwrite (M-NO-OVERWRITE / attack 10) + the round-trip (M-ROUNDTRIP). RED-first integration.
**Test contract:**
- (a) anon row w/ valid `enrollment_data.waitlist.data.{name,email, company, vat_number, …}` (input-key-named fields), free seat → creates user, **the new user's `billing_*` usermeta (`billing_company`, `billing_vat`, …) equals the submitted input-key values (round-trip — M-ROUNDTRIP)**, re-links `user_id`, status `confirmed`, `registration/confirmed` fires once with a non-zero `user_id`;
- (b) **collision: email matches an existing user who already has DIFFERENT `billing_vat`/`invoice_email` usermeta** → row linked to that existing ID, **existing user's billing meta UNCHANGED** (M-NO-OVERWRITE / attack 10), no second user, no welcome mail;
- (c) missing email → `WP_Error('lead_no_email')`, row stays `waitlist`, no user;
- (d) half-state: anon row on a FULL edition → `capacity_full`, row stays `waitlist` carrying the created `user_id` (benign-idempotent), retry creates no duplicate user.

- [x] **Step 1: Write failing tests** (a)–(d). For (b), pre-create a user with set `billing_vat`/`invoice_email`, seed an anon waitlist row with that email + DIFFERENT billing values, promote, assert the existing user's meta is untouched.
- [x] **Step 2: Run — expect FAIL.** `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlist`
- [x] **Step 3: Implement** the anon branch AFTER the status/terminal guards and BEFORE `START TRANSACTION`: read `$d = $registration->enrollment_data['waitlist']['data'] ?? []`; `$resolved = $this->resolveLeadAccount($d['email'] ?? '', $d['name'] ?? '')`; `if is_wp_error return it`; **`if (!$resolved['was_existing']) { $this->updateUserProfile($resolved['user_id'], $d); }`** (maps reserved-name fields, sanitized, via the existing convergence — M-META-MAP; the existing-account branch writes NOTHING — M-NO-OVERWRITE); `$this->registrations->attachUserToWaitlistRow($registrationId, $resolved['user_id']); $registration = $this->registrations->find($registrationId);` stash `was_existing` for `confirmCore`. Transaction + `confirmCore` unchanged except they read a real `user_id`.
- [x] **Step 4: Run — expect PASS** (+ existing 4 tests green: race/full/invalid/terminal → M-RACE-HOLDS).
- [x] **Step 5: Commit** `feat(enrollment): resolve account + map captured billing meta on anonymous waitlist promote`.

**Integration gate:** anon promote creates+maps-meta+links+grants+confirms end-to-end; collision links without overwriting existing meta; accounted-promote unchanged.

### ── REVIEW GATE 2 ── (tier: FULL — mail to externally-supplied address + event-payload change consumed by 6 listeners; the credential-leak guard) — Task 4

### Task 4: Enrich `confirmed` event + gate welcome mail on `was_existing` (Tension #4)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` (`confirmCore` payload)
- Modify: `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php` (consume name/email; condition welcome send)

**Interfaces:**
- Consumes: `was_existing` (as `was_new_account` = `!was_existing`) threaded from Task 3 into `confirmCore`.
- Produces: `stride/registration/confirmed` payload carries `['was_new_account'=>bool]` (additive — existing listeners ignore unknown keys).

> **MECHANISM CORRECTED 2026-06-30 (ground-truthed by the controller before dispatch — supersedes the original "add a new add_action sender" approach, which was WRONG):**
> The `stride/registration/confirmed` event is ALREADY bound to a SEEDED, active netdust-mail template `stride-enrollment-confirmed` (`StrideMailBridge::seedTemplates()` :625-638, trigger registered :416). netdust-mail's `activateTriggers()` (`MailService.php:176-191`) auto-`add_action`s every triggered template and resolves the recipient from the event's `user_id` (`MailService.php:236-242`: `$to = $options['to'] ?? get_userdata($context['user_id'])->user_email`). **Therefore:**
> - **New-account promote (was_new_account=true):** the new user has a real `user_id`+email → the EXISTING trigger ALREADY sends the confirmation/welcome mail. Nothing to ADD. ✓
> - **Existing-account collision (was_new_account=false):** the row links to the real user → the EXISTING trigger ALSO fires → an UNSOLICITED confirm mail to a stranger's account. **THIS is the M-NEW-USER-MAIL-ONLY / attack-6 gap — and the job is to SUPPRESS it, not to add a sender.**
> - Adding a second `add_action` sender (the original plan) would DOUBLE-SEND for new accounts (trigger + new handler). Do NOT do that.
>
> **The correct, minimal mechanism:** suppress the `confirmed`-trigger mail for the collision case. Implement by having the promote path, on the `was_existing===true` anon branch, NOT let the seeded `stride-enrollment-confirmed` template fire — the lowest-risk way is a guard in `StrideMailBridge` keyed on `was_new_account`. Concretely: register an `add_action('stride/registration/confirmed', …, priority < the trigger's 10)` that, when the payload carries `was_new_account===false` AND the row came via the anon-promote path, short-circuits the seeded template send for that dispatch (e.g. via a per-dispatch `ndmail`-suppression filter the netdust-mail send checks, or by gating the seeded template's activation). **The implementer MUST ground-truth netdust-mail's available suppression seam** (`ndmail_before_send`/a `pre_send` filter, template-active check, or an explicit `to`-override to empty) and pick the one that suppresses ONLY this dispatch without disabling the template globally or affecting normal (logged-in user) confirms. If no clean per-dispatch suppression seam exists, the fallback is: do NOT dispatch `stride/registration/confirmed` for the `was_existing` anon-collision case at all (move the dispatch inside `if ($wasNewAccount || !$cameFromAnonPromote)`), accepting that other `confirmed` listeners also won't fire for that one collision row — document the trade-off. **Decide based on what the code actually offers; this is the load-bearing design call of Task 4.**

**Tier: A** — recipient-resolution + the no-credential-to-existing-account guard (M-NEW-USER-MAIL-ONLY/M6/M7). RED-first.
**Test contract:** (a) new-account promote → confirmation/welcome mail once to the NEW user's email (recipient present, not empty); (b) **existing-account collision promote → NO confirm/credential mail to the existing account** (denial — M-COLLISION-SAFE/attack 6); (c) accounted normal confirm (a logged-in user's own enrollment) → unchanged, mail still sent. **The bug-catching case is (b): exactly ZERO mail to the pre-existing account.**

- [x] **Step 1: Ground-truth netdust-mail's per-dispatch suppression seam** (read `MailService::send` + `activateTriggers` + any `ndmail_*` filters) and pick the minimal mechanism per the corrected note above.
- [x] **Step 2: Write failing integration tests** (a)/(b)/(c) — mail-capture via Mailpit or a `wp_mail`/`ndmail` stub that counts sends per recipient. (b) must assert 0 sends to the existing user's email. Run — expect FAIL.
- [x] **Step 3: Implement** the suppression keyed on `was_new_account===false` for the collision case; thread `was_new_account` into the `confirmed` payload from `confirmCore`. Do NOT add a competing sender for the new-account case (the trigger already covers it).
- [x] **Step 4: Run — expect PASS** (full suite + existing mail tests green; the normal logged-in confirm still mails).
- [x] **Step 5: Commit** `feat(mail): suppress confirmation mail to pre-existing account on waitlist-promote collision`.

**Integration gate:** Mailpit shows exactly one confirmation mail for a new-account promote, ZERO for an existing-account collision promote, and the normal logged-in-user confirm is unchanged (still one).

### ── REVIEW GATE 3 ── (tier: STANDARD — handler-level deny + batch isolation; no new 1a primitive beyond GATE 1) — Task 4b

### Task 4b: Bulk-handler coverage — anon row + denial + per-row isolation (close the test gap)

**Files:**
- Modify: `tests/Unit/Handlers/BulkRegistrationHandlerTest.php`

**Tier: A** — the capability-deny path (M-CAP-GATE/M6) and per-row malformed isolation (M-PER-ROW/M10) are security/robustness predicates. RED-first.
**Test contract:** (a) `stride_view`-only actor → `handleBulkPromoteWaitlist` returns the deny `WP_Error` before any row runs (A2); (b) a batch mixing one valid anon row + one malformed-email anon row → valid in `succeeded[]`, malformed in `failed[]` (`lead_no_email`), batch not aborted; (c) mixed accountless + already-accounted waitlist rows → both promote, only the accountless one creates a user.

- [x] **Step 1: Write failing tests** (mock `promoteFromWaitlist` per-row outcomes; assert `{succeeded, failed, summary}` + the deny short-circuit).
- [x] **Step 2: FAIL → implement → PASS.**
- [x] **Step 3: Commit** `test(enrollment): anonymous + denied + per-row bulk promote coverage`.

**Integration gate:** bulk promote of a mixed selection produces the correct per-row report; deny blocks non-managers.

### ── REVIEW GATE 4 ── (tier: STANDARD — public-form field capture (new public input surface → wp-security) + invariants doc) — Tasks 5–6

> **DESIGN PIVOT 2026-06-30 (user decision, supersedes Task 5's questionnaire-config approach):** The offer/invoice fields are a DEFAULT part of the waitlist FORM, rendered as NATIVE inputs in `templates/forms/waitlist.php` — **NOT** entered through the questionnaire builder. Reason: questionnaire groups have no code default, so the form shipped as naam/email until manually configured per edition — the feature was invisible. New **Task 8** renders the field set natively (bound to `form.extra_fields.<input-key>`, same payload → same `sanitizeExtraFields` → same `wrapStage` persist → same promote-time `updateUserProfile` map, so the Task-3 round-trip is unchanged). The questionnaire `waitlist` stage REMAINS available for EXTRA custom questions on top. Task 5 below is retained as the record that the pipeline accepts these field names; its "configure the groups" step is SUPERSEDED by Task 8's native rendering.

### Task 5: ~~Add the personal/invoice field set to the `waitlist` questionnaire stage~~ → SUPERSEDED by Task 8 (native form fields) + verify round-trip

**Files:**
- ~~Configure: the `waitlist`-stage field groups (questionnaire builder data).~~ SUPERSEDED — fields are native to the form (Task 8), not questionnaire config.
- Verify (no structural change expected): `web/app/themes/stridence/templates/forms/waitlist.php`, `Modules/Questionnaire/QuestionnaireHandler.php::handleSubmitWaitlist` + `sanitizeExtraFields`.
- Test: round-trip assertion (folded into Task 3's integration test as case (a); this task confirms the *configured* fields are what (a) exercises).

**Tier: A (round-trip)** — the bug-catching part is "the configured field names match `getUserMetaMapping()` so they map on promote" (M-ROUNDTRIP / M-Q12). The form rendering itself is Tier B (reused components).
**Field set (RESOLVED = (b)):** billing block named by INPUT-KEY (`company, address, postal_code, city, vat_number, invoice_email, gln_number`) + `organisation, department`. These auto-map to `billing_company, billing_address_1, billing_postcode, billing_city, billing_vat, invoice_email, gln_number, organisation, department` usermeta on the new account. **Do NOT name the fields `billing_*`** — that is the META key, not the mapping INPUT-KEY, and would never map (freshness-review BLOCKER-1).

- [x] **Step 1 (RUNTIME CONFIG — admin data, not code):** Configure the `waitlist`-stage groups to include the field set, using the exact reserved INPUT-KEYS from `getUserMetaMapping()` as field names (`company, address, postal_code, city, vat_number, invoice_email, gln_number, organisation, department`), NOT the `billing_*` meta keys. **This is questionnaire-builder data entered per edition via `QuestionnaireSettingsPage`, not a code change — there is no code-level default/seed for the field set. Action for the operator: add these fields to the `Wachtlijst` stage on the relevant edition(s) in the admin.** The reserved-name auto-map (`QuestionnaireSettingsPage.php:304-306`, keyed on `sanitize_key($field['name'])` vs `getUserMetaMapping()`) recognizes these names and surfaces the "persists to profile" notice.
- [x] **Step 2: `sanitizeExtraFields` covers the field types — VERIFIED, no code edit needed.** `QuestionnaireHandler::sanitizeExtraFields` (:244-254) runs `sanitize_key` on names + `sanitize_text_field` on every string value; all of `company/address/postal_code/city/vat_number/invoice_email/gln_number/organisation/department` are text/email strings → fully covered. `QuestionnaireValidator::validate(..., 'waitlist')` accepts the stage. No structural template/handler change; Step-2's conditional code edit was NOT triggered.
- [x] **Step 3: Round-trip proof — GREEN.** Task 3 integration case (a) `promotesAnonRowCreatesUserMapsBillingMetaAndConfirms` submits `company/vat_number/invoice_email/organisation` (input-keys) and asserts the new user's `billing_company/billing_vat/invoice_email/organisation` usermeta equals the submitted values. Verified green (1 test, 11 assertions) by the controller at GATE 4.
- [x] **Step 4: No code commit — Task 5 is config + verification only.** The bug-catching assertion lives in Task 3's committed integration test (b6356986); the form render reuses existing components; the field-set entry is runtime admin data. Nothing to commit here.

`no unit test for the form render: Tier B, reused field-group component + validator. The load-bearing assertion is the Task 3 round-trip (M-ROUNDTRIP) — GREEN. Task 5 produced no code diff: sanitize coverage was already present (verified), and the field-set is runtime questionnaire-builder data, not code.`

### Task 6: Append INV-9 to ARCHITECTURE-INVARIANTS.md

**Files:**
- Modify: `ARCHITECTURE-INVARIANTS.md`

**Tier: B** — documentation. `no unit test: Tier B, doc-only.`

- [x] **Step 1:** Add the INV-9 section (text in "Architecture invariants touched") + a Quick-reference row. Include the meta-on-create-only rule, the `$isExistingColleague` sibling reference, the `resolveParticipant` deferred-bypass note, and the audit-move grep.
- [x] **Step 2: Commit** `docs(invariants): INV-9 anonymous-lead → account resolution`.

### ── REVIEW GATE 5 ── (tier: STANDARD — frontend wiring of an existing presentational button to the already-gated bulk action; no new entry point, no new primitive) — Task 7

### Task 7: Wire the dossier "Promoveer van wachtlijst" button to the bulk promote action (BLOCKER-2)

> Added by the freshness review + user scope decision (2026-06-30): the dossier `stride_promote_waitlist` SMART_ACTION button is presentational (rendered at `templates/admin/dashboard/dossier.php:367–373` with no `@click`; no backend route exists). The promote domain method (`promoteFromWaitlist`) is surface-agnostic and already complete after Task 3 — this task only connects the existing button to the existing, capability-gated bulk endpoint with a single id.

**Files:**
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard/dossier.php` (add `@click` to the promote action button) and/or `web/app/mu-plugins/stride-core/assets/js/admin/dossier.js` (the `SMART_ACTIONS` / dispatch path).

**Interfaces:**
- Consumes: the existing `ntdst/api_data/stride_bulk_promote_waitlist` endpoint (M-CAP-GATE already enforces `stride_manage`); dispatches with `ids:[<this registration id>]`. **No new entry point, no new nonce (INV-2), no new capability (INV-1).** The single-row promote reuses the bulk runner's per-row report.

**Tier: B (glue/wiring)** — connecting an existing button to an existing gated endpoint; the domain behavior is already integration-tested in Task 3. The load-bearing assertion is the F1 browser flow at shake-out, not a unit test.

- [x] **Step 1:** Give the dossier promote button a click handler that calls the bulk API with `ids:[id]` (mirror `grid.js:145` `stride_bulk_promote_waitlist` dispatch), shows the per-row result, refreshes the dossier. Confirm the capability gate still applies (no new path around `denyIfNotManager`).
- [x] **Step 2: Confirm** a `stride_view`-only actor's dossier either hides the button or the gated endpoint rejects (the endpoint gate is the security boundary; the button is presentational).
- [x] **Step 3: Commit** `feat(admin): wire dossier waitlist-promote button to the gated bulk action`.

`no unit test: Tier B, frontend glue to an already-tested + already-gated endpoint. Risk (the per-row report rendering + the deny path) → F1/F2 shake-out browser pass + the Task 4b handler deny test.`

### ── REVIEW GATE 6 ── (tier: STANDARD — new public-form input fields, but riding the existing sanitize/persist pipeline + a small server-side required-check) — Task 8

### Task 8: Native offer/invoice fields on the waitlist form by default (user decision — NOT via questionnaire)

> The deliverable that makes the feature VISIBLE. The waitlist form (`templates/forms/waitlist.php`) shipped as naam/email because the questionnaire stage has no code default. Per the user's decision, the offer/invoice fields are NATIVE to the form, not questionnaire-builder data.

**Files:**
- Modify: `web/app/themes/stridence/templates/forms/waitlist.php` (native fields bound to `form.extra_fields.<input-key>` + Alpine `init()` seeding).
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireHandler.php` (`handleSubmitWaitlist` — server-side required-check for the native offer essentials, since `QuestionnaireValidator` only validates group-declared fields).
- Test: unit test for the handler's native-required validation.

**Interfaces:** native inputs use the exact `getUserMetaMapping()` INPUT-KEYS (`company, address, postal_code, city, vat_number, invoice_email, gln_number, organisation, department`). Same `extra_fields` payload → same `sanitizeExtraFields` → same `wrapStage` persist → same promote-time `updateUserProfile` map. The Task-3 round-trip (`promotesAnonRowCreatesUserMapsBillingMetaAndConfirms`) is unchanged — fields now come from the template, not a questionnaire group.

**Tier: A** for the handler required-validation (RED-first: missing-required + invalid-`invoice_email` denial); **Tier B** for the template render (F0 browser flow at shake-out is the load-bearing render check).

- [x] **Step 1:** Render the 9 native fields in `waitlist.php` with Dutch labels under a "Gegevens voor offerte/facturatie" subheading; `company`/`vat_number`/`invoice_email` required (client), rest optional; init the keys in Alpine `init()`.
- [x] **Step 2:** Add the server-side required-check + `is_email(invoice_email)` to `handleSubmitWaitlist` after the questionnaire validate call, returning the existing `WP_Error('validation_error', …)` shape (Dutch). RED-first unit test (missing `company` → error; invalid email → error; all present → persist).
- [x] **Step 3:** Confirm the round-trip integration filter stays green (data path unchanged).
- [x] **Step 4: Commit** `feat(waitlist): native offer/invoice fields on the waitlist form by default`.

**Acceptance:** F0 (anonymous upfront capture) at shake-out now drives a form that ACTUALLY shows + collects the offer/invoice fields; the validation-reject edge (blank required) is the load-bearing F0 edge.

---

## Acceptance flows

> Authored at plan-time (feature-acceptance situation A). Each row's Edges column mandatory (six classes, or why excluded). Driven through the real browser + the real bulk API at shake-out.

| # | Flow | Happy path | Edges (empty/zero · denied actor · wrong-order/re-entry · concurrent/double · boundary · mid-flow failure) |
|---|---|---|---|
| F0 | **Anonymous upfront capture (the new form scope)** | A not-logged-in visitor opens a full edition's waitlist form, fills name+email+the invoice/personal field set, submits → anonymous row stored (`user_id` NULL), all fields in `enrollment_data.waitlist.data.*` | **empty/zero:** required field blank → `QuestionnaireValidator` rejects, clear error, nothing stored. **denied:** n/a (public/nopriv by design). **wrong-order/re-entry:** same email resubmits → upsert onto the same anon row (`findAnonymousForEmailAndEdition`), not a duplicate. **concurrent/double:** double-submit → one row (upsert). **boundary:** max-length / special chars in billing fields → sanitized, stored intact. **mid-flow:** DB write fails → `update_failed` WP_Error, user sees retry message, no partial row. |
| F1 | **Single dossier promote of an accountless lead (with upfront data)** — *requires Task 7 wiring (the dossier button is presentational until then — freshness-review BLOCKER-2)* | Coordinator opens a waitlist dossier with `user_id=0`, clicks "Promoveer van wachtlijst" → button dispatches `stride_bulk_promote_waitlist` with `ids:[id]` → new active account created, **its billing/personal usermeta populated from the captured data**, row confirmed, course granted, one welcome mail (Mailpit). Offer/invoice is makeable immediately (all data present). | **empty/zero:** lead with missing/empty `email` → row not promoted, clear error, no junk user. **denied:** `stride_view`-only admin → deny. **wrong-order/re-entry:** already-confirmed row → `invalid_status`, no second account/grant/mail/meta. **concurrent/double:** double-click → idempotent (status guard). **boundary:** edition w/ exactly 1 free seat → fills it; 2nd promote → `capacity_full`. **mid-flow:** edition fills between resolve and transaction → `capacity_full`; created account + mapped meta stay linked to the still-waitlist row (benign), retry creates no duplicate. |
| F2 | **Grid bulk promote, mixed selection** | Select N waitlist rows (some accountless, some accounted), bulk "Promoveer" → `{succeeded, failed}`; accountless rows get new accounts + mapped meta, accounted rows promote normally, one mail per *new* account | **empty/zero:** selected row w/ malformed email → that row `failed[]` (`lead_no_email`), others succeed. **denied:** non-manager → whole action denied before loop. **wrong-order:** a selected already-confirmed row → that row `failed` (invalid_status), rest proceed. **concurrent/double:** two admins bulk-promote overlapping selections on a near-full edition → `FOR UPDATE` prevents oversell; loser's overlapping rows `failed` (`capacity_full`). **boundary:** selection > `MAX_BATCH` → batch-cap (existing). **mid-flow:** mailer slow/failing for one row → mail failure does not fail the promote (grant + meta already done); logged, not a row failure. |
| F3 | **Email-collision reuse — NO billing-meta overwrite (the sharpest)** | Accountless lead whose email matches an existing real user → promote links the row to that existing account, grants the course, **leaves the existing user's billing/personal usermeta UNTOUCHED**, sends NO credential/welcome mail | **empty/zero:** n/a (email present). **denied:** as F1. **wrong-order:** existing user already enrolled in this edition → existing `isEnrolled` short-circuit. **concurrent/double:** two leads sharing one existing email → both link to that account, no duplicates, no meta-write, no double-mail. **boundary:** lead's billing data DIFFERS from the existing user's → existing user's meta wins (lead's stays per-registration in `enrollment_data`, available to *that* offer only). **mid-flow:** existing-user lookup succeeds but grant fails → logged warning (existing behaviour), row confirmed, no mail, no meta-write. |

> **F4 (first-login profile nudge) is DELETED** — the upfront-capture model removes the nudge. No "complete your profile" flow exists; the data is present at promote.
>
> **Verification at shake-out (situation B):** drive F0–F3 through `superpowers-chrome`/Playwright against running DDEV + assert Mailpit recipients + assert usermeta via the un-mocked layer. No UI flow `pass` without a browser. **F3 (collision + no-overwrite) and F1-mid-flow (half-state) are load-bearing — drive them explicitly RED-first.** F0 is a new public input surface — drive the validation-reject edge.

---

## Review clusters & tiers (1f / 1h)

| Gate | Tasks | Size | Tier | Why |
|---|---|---|---|---|
| GATE 1 | 1–3 | 3 | **FULL** | Creates WP accounts + writes billing usermeta from attacker-influenceable data + re-links the auth-bearing `user_id`; the core 1a surface incl. attack 10 (billing-meta poisoning). `security-sentinel` mandatory; `/security-review` fires. |
| GATE 2 | 4 | 1 | **FULL** | Outbound email to an externally-supplied address + payload change across 6 listeners; the credential-leak guard (M6/M7) lives here. |
| GATE 3 | 4b | 1 | **STANDARD** | Handler-level deny + per-row batch isolation; verifies M6/M10 against GATE 1's surface, no new 1a primitive. 2 finders + the per-row test. |
| GATE 4 | 5–6 | 2 | **STANDARD** | Public-form field capture (a new public input surface → wp-security validate/sanitize pillars, but reusing the existing validated/sanitized questionnaire pipeline — no new primitive) + invariants doc. The round-trip assertion lives in GATE 1's Task 3. |
| GATE 5 | 7 | 1 | **STANDARD** | Frontend wiring of an existing presentational dossier button to the already-`stride_manage`-gated bulk endpoint (`ids:[id]`). No new entry point, nonce, or capability; domain behavior already integration-tested in Task 3. Verified by the F1 browser pass at shake-out. |

**Tier escalation is one-way:** a finding on a 1a surface in GATE 3/4 promotes that cluster to FULL. (Note: if Task 5 turns out to need NEW sanitization for a field type the questionnaire pipeline doesn't already cover — i.e. a genuinely new public-input parse — GATE 4 escalates to FULL for that field.)

---

## Self-review

- **Spec coverage (revised decision set):** upfront full-info capture (T5 + F0) · find-or-create at promote (T1) · create-from-captured-info incl. **billing-meta map on new account** (T3, M-META-MAP) · **NO overwrite of an existing account's billing meta** (T3, M-NO-OVERWRITE/attack 10/F3) · re-link (T2) · run-promote-against-real-user (T3) · auto welcome mail (T4) · both surfaces (one domain method; T3 + T4b handler test) · `complete_account` task + nudge REMOVED (Tension #2 superseded) · close the anon test gap (T3 domain + T4b handler). All revised decisions mapped.
- **Placeholder scan:** the waitlist field LIST depends on the OPEN QUESTION (flagged, structure invariant either way); all paths exact.
- **Type consistency:** `resolveLeadAccount(): array{user_id:int, was_existing:bool}|WP_Error` consistent T1/T3/T4; `attachUserToWaitlistRow(int,int):bool` consistent T2/T3; meta-map reuses `updateUserProfile(int,array):void` + `getUserMetaMapping():array` (existing signatures, unchanged).
- **Kept-from-v1 audit:** threat model (sharpened with attack 10 + M-NO-OVERWRITE-billing + M-META-MAP + M-ROUNDTRIP), INV-9, promote anon-branch, confirmed-event fix, welcome-mail-gated-on-was_existing, anon test-gap closure, `resolveParticipant` deferred bypass — all retained.
