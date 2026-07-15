# Form Identity Streamline — one participant model for every form

**Date:** 2026-07-14 · **Owner decisions:** Stefan (chat, 2026-07-14) · **Status:** EXECUTED (safer variant — same day)
**Base:** `claude/admin-dashboard-review-imki0g` (independent of the admin-view slices; can run before or after Vandaag)

## The model (Stefan's rules, verbatim intent)

1. People submitting forms normally have an account and are logged in.
   **Only interest and waitlist accept submissions without an account.**
   The waitlist form collects enough (name, e-mail, billing essentials) to
   create the account on the fly when needed (promotion) — already true.
2. **All forms are prefilled when the visitor is logged in.**
3. **The submitted e-mail is ALWAYS checked against existing accounts**, and
   against the submitting user themselves. If an account exists, the row is
   bound to it — **the participant is never "anonymous"; they just don't
   have an account yet.** "Lead" = participant-without-account, a temporary
   state, not an identity.
4. Logged-in users can submit forms **on behalf of someone else** — for every
   form EXCEPT the intake (enrollment questionnaire) and the evaluation:
   those are strictly personal. On-behalf includes completion tasks (e.g.
   session selection by the colleague-enroller).

## The form-identity matrix (documentation + per-handler tests — NO runtime class)

> **Simplicity pass (2026-07-14, Stefan):** no new DB table anywhere in this
> plan, and no runtime policy class either — six forms with one-line gates
> don't justify indirection. The matrix lives HERE and in
> docs/DATA-MODEL-REGISTRATIONS.md; per-handler unit tests pin each gate.
> Also cut: the self/other toggle (the e-mail field IS the choice — rule 3
> resolves whatever is submitted), the resolveParticipant fold (deferred —
> tracked INV-9 cleanup, zero functional gain now), and the code-identifier
> vocabulary sweep (UI copy + docs only).

| Form | Account required | Prefill when logged in | On behalf of another | Participant binding |
|---|---|---|---|---|
| Interest | no | **yes (add — missing today)** | yes (via the e-mail field — no toggle) | e-mail→account resolution (below) |
| Waitlist | no | yes (exists) | yes (via the e-mail field — no toggle) | e-mail→account resolution |
| Enrollment (full) | yes | yes | yes ("collega", exists) | self, or find-or-create for colleague (exists) |
| Completion tasks / session selection | yes | n/a | **yes for `enrolled_by` actor (open up — participant-or-admin today)** | own registration or enroller's colleague |
| Intake | yes | yes | **no** (exists — keep) | own registration only |
| Evaluation | yes | yes | **no** (exists — keep) | own registration only |

## E-mail→account resolution (rule 3, the core change)

At interest/waitlist submission, resolve the submitted e-mail with a plain
`get_user_by('email')` lookup in the two handlers (no new helper class; the
INV-9 create-paths are untouched — submission-time is resolve-only, never
create):

| Case | Row created | Notes |
|---|---|---|
| Logged in, e-mail = own account | `user_id` = self. No lead columns. | Appears in own dossier/dashboard immediately — closes the "member's own interest is invisible" gap. |
| E-mail matches ANOTHER existing account | `user_id` = that account; `submitted_by` records the actor (or null for a visitor). | Rule 3: an account-holder is never a lead. Provenance is kept — see threat model. |
| E-mail matches no account | Lead row (`user_id` NULL, lead columns stamped) — exactly today's behavior. | Adopted collision-safely at promotion/enrollment (INV-9, exists). |

Dedupe stays e-mail-per-edition; for account-bound rows it becomes the
existing `hasActiveRegistration` check (same row-per-person outcome).

**One-time adoption pass** for existing data: every current lead row whose
`lead_email` exactly matches a `wp_users` e-mail is bound to that account
(same merge semantics as the promotion adopt). Run as a WP-CLI/eval-file
routine, not a schema migration — it's data, not DDL. Idempotent, logged.

## Vocabulary (copy + docs only)

The grid/dossier badge for account-less rows becomes "Geen account" (a state,
not an identity); "(anoniem)" remains only as the no-name-captured fallback.
Docs say "lead = participant without an account yet". Code identifiers and
the API `anonymous` flag stay as-is (documented) — renaming them is diff
churn with no behavior change.

## Threat model

**Assets:** member accounts and dossiers (a bound row appears in someone's
dashboard), lead PII, account-existence information.
**Actors:** unauthenticated visitor, logged-in member, colleague-enroller,
admin. Public surface: the two nonce-gated `ntdst/api_data` form actions
(INV-2 covers CSRF; no rate limiting exists today).

| # | Attack | Mitigation |
|---|---|---|
| 1 | **Account enumeration** — response differs when an e-mail matches an account | The handler response is byte-identical in all three resolution cases (same success message). The binding is server-side only. |
| 2 | **Dashboard injection** — visitor types victim@x.be; an "interest" the victim never expressed appears on their dashboard | Accept for interest/waitlist ONLY (low-stakes, non-binding states; admin reviews leads anyway) with: provenance recorded (`submitted_by` ≠ participant, null for visitors) and rendered on the member dashboard ("gemeld via formulier") and in the dossier timeline; audit event on every third-party binding. **Never** auto-bind for statuses beyond interest/waitlist. Residual risk: nuisance entries — bounded by mitigation 3. |
| 3 | **Form spam / bulk probing** (no rate limiting today) | Add a per-IP + per-e-mail transient throttle to the two public actions (e.g. 5/hour) — cheap, and it also bounds mitigation 2's residual risk. |
| 4 | **Profile overwrite via on-behalf** | Already mitigated in enrollment (existing-colleague profile fields divert to enrollment_data, never usermeta) — the new interest/waitlist binding writes NO usermeta at submission time. Keep it that way: meta mapping only happens at promotion (existing M-NO-OVERWRITE). |
| 5 | **Credential/welcome-mail leakage on binding** | Submission-time binding sends NO account mail (nothing was created). Account creation remains only at promotion/colleague-enroll through the single INV-9 helper (collision-safe, no credentials to existing accounts — exists). |
| 6 | **Enroller overreach via completion tasks** | Opening session selection to `enrolled_by` checks THE COLUMN (server-side, per registration), never a client claim; intake/evaluation remain participant-only (the matrix + contract test pin this). |

**Out of scope:** e-mail ownership verification (double opt-in) for leads —
deferred; the netdust-mail broadcast work is the natural place if ever needed.

## Tasks (slim)

1. **Interest/waitlist handlers**: `get_user_by('email')` resolution → bind
   or lead per the table; provenance already exists (`submitted_by`); audit
   event on third-party binding; identical responses (threat 1). Rate
   throttle ONLY under full rule 3 (see open decision).
2. **Interest form prefill** (waitlist already has it); verify
   enrollment/intake/evaluation prefill coverage.
3. **Completion tasks**: allow the registration's `enrolled_by` actor
   (one condition); intake/evaluation untouched.
4. **Adoption pass** for existing lead rows (eval-file script like seed.php,
   idempotent, logged — run against a copy of production data first).
5. **Copy + docs**: "Geen account" badge; matrix into
   DATA-MODEL-REGISTRATIONS.md §4.
6. Per-handler unit tests pinning each gate + JS spec updates, suites green,
   two-round review, push.

**Deferred (tracked, not lost):** resolveParticipant→resolveLeadAccount fold
(INV-9 cleanup); code-identifier renames.

**Estimate:** < 1 day.

## Decision record

**Stefan chose the SAFER VARIANT (2026-07-14):** bind at submission only when
the submitter is logged in as the account owner; every other submission stays
a lead until the promotion adopt. No rate throttle needed (threat 3 falls
away — no stranger-writable account binding exists). The admin grid shows
"Account gevonden" on leads whose e-mail matches an account, so the match is
visible without the binding. Goal restated: a clear, clean submission flow —
clear who submitted what, admin can take each submission to its next step.

## Review round 2 (same day) — verified findings, all fixed

An 8-angle review + adversarial verification pass over the executed slice
confirmed and fixed:

- **Actor gate widened too far / too wide by default:** `actorMayActOn` is now
  an ALLOW-list (`session_selection`, `documents`, `post_documents`) — the
  evaluation is strictly personal like the intake, and future task types are
  enroller-denied by default. `approval`/`post_approval` additionally require
  `stride_manage` at the public endpoint (a participant could previously
  self-approve → auto-confirm; pre-existing, now closed).
- **Rule 4 was unreachable end-to-end:** the completion route bounced the
  colleague-enroller; it now falls back to the most recent registration they
  created for the edition, with the personal tasks rendered read-only. The
  caller-less duplicate endpoint `stride_save_session_selection` (owner-only,
  drifted from the gate) was deleted.
- **Silent bind failure** in the self-bind adopt fell through to `create()`
  (duplicate row / waitlist downgrade via the reactivate branch) — now logs +
  returns an error; the adopted row is reused in memory instead of an
  unguarded re-find.
- **Case-sensitivity:** the lead-adopt lookup compared e-mails via
  `JSON_EXTRACT` (utf8mb4_bin, case-SENSITIVE — the only case-sensitive
  e-mail predicate in the system); it now matches the `lead_email` column
  (*_ci), consistent with self-bind/grid/batch lookups.
- **Cross-boundary duplicates:** promotion now refuses to bind a lead onto an
  account that already holds a row for the edition
  (`duplicate_registration`, mirrors adopt-leads triage); `enroll()` adopts
  waitlist leads too (not only interest). Doc §4 states the real guarantee.
- **Two bind writes, one contract:** `upgradeFromInterest` now guards on
  account-less, clears lead columns and stamps `company_id` — same contract
  as `bindLeadToUser`, which now takes the company in the SAME guarded
  statement (COALESCE; admin-set company wins). The adopt script and the
  promote path stamp through the bind; the separate post-bind update is gone.
- **Reactivation completeness:** a cancelled account row reactivates through
  `create()`'s reactivate branch (full reset: stale quote/selections/
  completed_at cleared, fresh `registered_at` — no waitlist queue-jumping).
- **Missing selection lock:** the stage-append read-modify-write runs under
  `acquireSelectionLock` with a fresh re-read inside the lock (DATA-MODEL §5).
- **Vocabulary:** `RegistrationStatus::isReactivatable()` replaces three
  hand-rolled status lists (handler, repo reactivate branch, enroll pre-check).

## Superseded open decision (kept for history)

- Threat 2 acceptance: a stranger CAN still put a (visible-provenance,
  interest-only) entry on a member's dashboard by typing their e-mail. The
  alternative — bind only when the submitter is logged in as that account,
  keep everyone else a lead until promotion — is safer but weakens rule 3
  ("account holders are never leads") for visitor submissions. Plan assumes
  rule 3 as stated + mitigations; flag if you want the safer variant.
