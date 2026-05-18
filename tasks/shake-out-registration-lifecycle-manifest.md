# Shake-out Manifest: Registration Table Lifecycle

**Date:** 2026-05-18
**Scope:** Full lifecycle on `wp_vad_registrations` — from interest (no account) → confirmed enrollment → completion → cancellation, including all data persistence.
**Status:** Pending — manifest only, no execution yet.

---

## Status semantics (verified from code)

Two enum values are **defined but not implemented**. Tests will flag them; no fixes proposed in this manifest.

| Status | Implemented? | Source of truth |
|---|---|---|
| `interest` | ✅ Yes — `EnrollmentService::registerInterest` + `QuestionnaireHandler::handleSubmitInterest` (anonymous) | Edition must be in `Announcement` state (`OfferingStatus::allowsInterest()`) |
| `pending` | ✅ Yes — initial status when edition has completion requirements OR `requiresApproval` is set | `EnrollmentService::enroll` line 207–209 |
| `confirmed` | ✅ Yes — direct on enroll (no requirements/approval) OR after `confirmRegistration` | Default ENUM value |
| `completed` | ✅ Yes — `RegistrationRepository::updateStatus(Completed)` sets `completed_at` | Triggered post-course |
| `cancelled` | ✅ Yes — `cancel()` writes this. Fires `stride/registration/cancelled` → cancels quote, frees seat, audit log | Only terminal-cancel path that exists |
| `waitlist` | ❌ **Dead value.** Admin dropdown shows "Wachtlijst", JS has `.waitlist` class. Zero writes in service layer. | — |
| `withdrawn` | ❌ **Dead value.** Treated as synonym of `cancelled` in re-enrollment check (`RegistrationRepository.php:70`). Zero writes. | — |

**Cancelled vs Withdrawn:** functionally identical today. Both block re-enrollment dedup and both are excluded from "active." The probable intent ("admin cancelled" vs "user withdrew") is not encoded.

**Interest vs Waitlist:** different concepts in principle ("not planned yet" vs "full"), but only Interest is built. User-facing "add me to waitlist" UX for `Full` editions does not exist.

---

## Lifecycle map

```
                                   ┌─────────────────────────────────────┐
                                   │ Edition state: Announcement         │
                                   └─────────────────────────────────────┘
                                                    │
                          ┌─────────────────────────┴──────────────────────┐
                          │                                                │
              ┌───────────▼───────────┐                          ┌─────────▼─────────┐
              │ Anonymous interest    │                          │ Logged-in interest│
              │ (no user_id)          │                          │ (registerInterest)│
              │ enrollment_data.      │                          │ user_id set       │
              │   interest = {...}    │                          │                   │
              └───────────┬───────────┘                          └─────────┬─────────┘
                          │                                                │
                          │ Edition opens (Announcement → Open)            │
                          │ User self-enrolls with matching email          │
                          │ → upgradeFromInterest()                        │
                          └────────────────────────┬───────────────────────┘
                                                   │
                          ┌────────────────────────▼───────────────────────┐
                          │ Edition state: Open                            │
                          │                                                │
                          │  Requirements? OR requiresApproval?            │
                          │  ┌─────────yes──────────┐  ┌───────no────────┐ │
                          │  │ status = Pending     │  │ status =        │ │
                          │  │ (no LMS access)      │  │   Confirmed     │ │
                          │  │ completion_tasks=set │  │ (LMS granted)   │ │
                          │  └──────────┬───────────┘  └─────────────────┘ │
                          │             │                                  │
                          │  Tasks done OR admin confirms                  │
                          │  → confirmRegistration()                       │
                          │             │                                  │
                          │             ▼                                  │
                          │     status = Confirmed                         │
                          │     LMS access granted                         │
                          └────────────────────┬───────────────────────────┘
                                               │
                                               │ Course + post-course tasks done
                                               ▼
                                       status = Completed
                                       completed_at set
                                               │
                          ┌────────────────────┴───────────────────┐
                          │   Either path can also cancel at any   │
                          │   non-terminal point:                  │
                          │   status = Cancelled                   │
                          │   cancelled_at set                     │
                          │   → quote cancelled, seat freed        │
                          └────────────────────────────────────────┘
```

---

## Test scenarios

### Phase A: Interest (Announcement edition)

| # | Scenario | Expected state |
|---|---|---|
| A1 | Anonymous user submits interest form (name + email + extra fields) | New row: `user_id=NULL`, `status=interest`, `enrollment_data.interest = {name, email, ...}`, edition_id set. Admin email fires. |
| A2 | Same anonymous user submits again (same email + edition) | No new row. Existing row's `enrollment_data.interest` is overwritten/merged. No duplicate. |
| A3 | Logged-in user calls `registerInterest()` | New row: `user_id` set, `status=interest`. No LMS access granted. No quote. `company_id` propagated from `_stride_company_id` meta. |
| A4 | Interest attempt on edition in `Open` (not `Announcement`) | Returns `WP_Error('interest_closed')`. No row created. |
| A5 | Same user submits interest twice (logged in) | Second call returns `WP_Error('already_registered')`. |
| A6 | Interest on trajectory (not edition) | Row created with `trajectory_id` set, `edition_id=NULL`. |
| A7 | **Upgrade path** — anonymous interest exists for `email@x`, then user with that email self-enrolls when edition opens | Existing row promoted: `user_id` set, `status` becomes `pending`/`confirmed`, `enrollment_data` merged (interest data preserved + new enrollment_personal added). **No duplicate row.** |
| A8 | Upgrade path attempted by **someone else** for victim's email (security check) | Upgrade refused: `$isSelfEnrolment` check blocks it. Either falls through to dedup error or creates separate row. Verify victim's interest row is not silently merged into attacker's enrollment. |

### Phase B: Direct enrollment (Open edition, no requirements)

| # | Scenario | Expected state |
|---|---|---|
| B1 | Self-enrollment on edition without completion requirements + no admin approval | `status=confirmed` immediately. LMS access granted. `registered_at` set. Quote auto-created. |
| B2 | Self-enrollment on edition with completion requirements (questionnaire/docs) | `status=pending`. No LMS access yet. `completion_tasks` JSON initialized with task list. |
| B3 | Self-enrollment on edition with `requiresApproval=true` | `status=pending`. Awaiting admin confirm. |
| B4 | Colleague enrollment (`enrollment_path=colleague`) — covered already in `shake-out-enrollment-v2-manifest.md` | Verify only that `enrolled_by` is set and `user_id` is the colleague, not the caller. |
| B5 | Capacity full — concurrent enrollment racing the last seat | One succeeds, one gets `WP_Error('edition_full')`. Row count vs capacity holds under transaction (`countConfirmedForUpdate` + `FOR UPDATE` lock). |
| B6 | Already-enrolled guard | Second `enroll()` for same user/edition returns `WP_Error('already_enrolled')`. No second row. |

### Phase C: Pending → Confirmed

| # | Scenario | Expected state |
|---|---|---|
| C1 | User on pending row completes all `completion_tasks` (auto-approve path) | Status auto-promotes to `confirmed` (verify which service does this — likely `EnrollmentCompletion`). |
| C2 | Admin clicks "Bevestigen" on a pending row | `confirmRegistration()` runs: `status=confirmed`, LMS access granted, `stride/registration/confirmed` event fires. |
| C3 | Admin tries to confirm a non-pending row (e.g. already confirmed) | Returns `WP_Error('invalid_status')`. No state change. |
| C4 | Pending row that requires approval — what unblocks it: tasks done OR admin click? | Verify exact semantics. If both, document precedence. |

### Phase D: Completed

| # | Scenario | Expected state |
|---|---|---|
| D1 | User completes course + all post-course tasks | `status=completed`, `completed_at` set. LMS access typically remains. Verify whether anything is revoked. |
| D2 | LearnDash marks course complete but post-course tasks (evaluation) still pending | Status stays `confirmed`. `completion_tasks` updated. No premature `completed_at`. |
| D3 | All tasks done but LearnDash course not 100% (e.g. quiz failed) | Status stays `confirmed`. Verify which signal wins. |

### Phase E: Cancellation

| # | Scenario | Expected state |
|---|---|---|
| E1 | Admin cancels a confirmed registration | `status=cancelled`, `cancelled_at` set. `stride/registration/cancelled` fires → quote cancelled → seat freed → audit log → notification mail. |
| E2 | Admin cancels a pending registration | Same as E1. No quote may exist; verify graceful handling. |
| E3 | Admin cancels an interest-only registration | Verify whether `cancel()` handles this gracefully or errors. Probably fine since `interest` is non-terminal. |
| E4 | User attempts to cancel themselves | Verify whether self-cancel is exposed in UI/API. If not, document gap. If yes, verify same state change. |
| E5 | **Re-enroll after cancel** | User on `cancelled` row re-enrolls in same edition → `RegistrationRepository.php:70` reactivates the existing row instead of creating a duplicate. `status` flips back to confirmed/pending. `cancelled_at` cleared? — verify. |
| E6 | Cancel a row that's already `completed` | Verify whether allowed. If yes, what does it mean (revoke certificate?). If no, error. |

### Phase F: Edge cases on data columns

| # | Scenario | Expected state |
|---|---|---|
| F1 | Anonymous interest with `user_id=NULL` survives all repository read paths | `find()`, `findByEmailAndEdition()`, `findByCompany()`, exports — no PHP warnings, no broken UI. |
| F2 | `enrollment_data` round-trips through stages | After full flow: contains `interest` + `enrollment_personal` (+ optional `enrollment_billing`, `intake`, `evaluation`) keys. JSON valid at every step. |
| F3 | `completion_tasks` JSON survives partial updates | Marking one task complete doesn't wipe other tasks. `updateCompletionTasks()` does merge, not replace (verify). |
| F4 | `selections` (Keuzecursus) locked at edition start | After `selections_locked_at` is set, user can no longer change session selections via the API. |
| F5 | `company_id` propagation | Set on register when user has `_stride_company_id` meta. Partner API queries by company return correct rows. |
| F6 | `quote_id` ↔ registration linkage | Quote cancellation cancels its registration (and vice versa). Verify no orphaned quotes after registration cancel. |

### Phase G: Dead enum values (FLAG, do not fix)

| # | Scenario | Expected outcome |
|---|---|---|
| G1 | Manually insert a row with `status='waitlist'` via SQL, browse admin | UI shows "Wachtlijst" label correctly but no business logic acts on it (no waitlist queue, no promote-to-pending). Document the gap. |
| G2 | Manually insert a row with `status='withdrawn'` via SQL | Re-enrollment dedup treats it like cancelled. Verify nothing else (audit, notifications, reports) special-cases it. |
| G3 | Look for any UI affordance to write either status | Grep admin templates + JS for status-change controls. Confirm dropdowns expose only the implemented states. |

---

## What's already covered (do not re-test)

These exist in prior manifests — link, don't duplicate:

- Enrollment form mechanics (self/colleague/voucher) → `shake-out-enrollment-v2-manifest.md`
- Completion tasks UX (questionnaire/documents/approval card) → `shake-out-enrollment-completion-manifest.md`
- Online enrollment scenarios (closed LD, mobile CTA, webinar) → `shake-out-online-enrollment-manifest.md`

This manifest focuses on the **state machine and data persistence** — what each status means, when it's written, what side-effects fire, what data lives where.

---

## Test approach

1. **Setup:** reseed DB. Create 3 editions in 3 states: Announcement, Open (no requirements), Open (with questionnaire + approval). Plus 1 Full edition for G1.
2. **Phase A** — browser + anonymous tab + Mailpit for admin notifications.
3. **Phase B/C/D** — browser as seed_student, admin actions as seed_admin.
4. **Phase E** — verify cancel side-effects via `wp db query` on `quote`/`registration` rows + audit log table.
5. **Phase F** — direct `wp eval` + DB inspection.
6. **Phase G** — manual SQL inserts, then exercise admin UI.
7. Log each scenario as PASS / BUG-RL-N with severity.

---

## Open questions before execution

- C1: which service actually flips pending → confirmed when tasks complete? (`EnrollmentCompletion` exists but exact code path needs tracing.)
- D3: precedence between completion_tasks done vs LearnDash course completion when both are required.
- E5: does cancel reactivation clear `cancelled_at`, or does it leave stale data?
- E6: is cancelling a completed registration allowed? What's the audit/certificate impact?

These should be answered during execution (via code reading + browser testing) and incorporated as findings.
