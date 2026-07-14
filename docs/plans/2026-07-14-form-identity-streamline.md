# Form Identity Streamline — one participant model for every form

**Date:** 2026-07-14 · **Owner decisions:** Stefan (chat, 2026-07-14) · **Status:** awaiting go
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

## The form-identity matrix (the convergence point this slice creates)

One policy table in code (`Modules/Enrollment/FormIdentityPolicy`), consulted
by every handler and pinned by a contract test — handlers can no longer
improvise their own gate:

| Form | Account required | Prefill when logged in | On behalf of another | Participant binding |
|---|---|---|---|---|
| Interest | no | **yes (add — missing today)** | yes (explicit choice, new) | e-mail→account resolution (below) |
| Waitlist | no | yes (exists) | yes (explicit choice, new) | e-mail→account resolution |
| Enrollment (full) | yes | yes | yes ("collega", exists) | self, or find-or-create for colleague (exists) |
| Completion tasks / session selection | yes | n/a | **yes for `enrolled_by` actor (open up — participant-or-admin today)** | own registration or enroller's colleague |
| Intake | yes | yes | **no** (exists — keep) | own registration only |
| Evaluation | yes | yes | **no** (exists — keep) | own registration only |

## E-mail→account resolution (rule 3, the core change)

At interest/waitlist submission, resolve the submitted e-mail through ONE
helper (extending the existing INV-9 point `resolveLeadAccount`; also folds
`resolveParticipant`, the tracked INV-9 bypass, into it):

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

## Vocabulary sweep

"Anonymous" disappears from code and UI where it means "no account":
`resolveAnonymousIdentity`→`resolveLeadIdentity` (presenter already named
`presentLeadIdentity`), `findAnonymousForEmailAndEdition`→
`findLeadByEmailAndEdition`, API flag `anonymous`→ kept for one release as an
alias of new `has_account:false` (grid/dossier JS reads it), UI copy
"(anoniem)" only remains as the no-name-captured fallback; the row badge
becomes "Geen account" (a state, not an identity). The data-model doc §4
updates accordingly.

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

## Tasks

1. **`FormIdentityPolicy`** table + contract test asserting each handler's
   actual gate (login check, on-behalf, participant binding) matches it.
2. **One account resolver** — extend `resolveLeadAccount` into the single
   INV-9 find-or-resolve; fold `resolveParticipant` into it (welcome-mail
   policy as a flag); add the submission-time lookup mode (resolve, never
   create). Update INV-9 doc entry.
3. **Interest/waitlist handlers**: e-mail→account resolution per the table;
   explicit "voor mezelf / voor iemand anders" choice when logged in (self →
   prefilled + locked e-mail); provenance + audit event on third-party
   binding; identical responses (threat 1); rate throttle (threat 3).
4. **Interest form prefill** (waitlist already has it); verify
   enrollment/intake/evaluation prefill coverage.
5. **Completion tasks**: allow the registration's `enrolled_by` actor;
   intake/evaluation untouched.
6. **Adoption pass** for existing lead rows (CLI routine, idempotent).
7. **Vocabulary sweep** + data-model doc §4 update + grid/dossier badge
   ("Geen account").
8. Tests (unit + handler contract + JS spec updates), suites green,
   two-round review, push.

**Estimate:** 1.5–2 days. **Est. risk concentrations:** the resolver fold
(task 2 — touches colleague enrollment) and the adoption pass (task 6 — run
against a copy of production data first).

## Open decision for Stefan

- Threat 2 acceptance: a stranger CAN still put a (visible-provenance,
  interest-only) entry on a member's dashboard by typing their e-mail. The
  alternative — bind only when the submitter is logged in as that account,
  keep everyone else a lead until promotion — is safer but weakens rule 3
  ("account holders are never leads") for visitor submissions. Plan assumes
  rule 3 as stated + mitigations; flag if you want the safer variant.
