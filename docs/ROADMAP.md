# Stride — Feature Roadmap

**Status baseline (2026-07-03):** frontend production-ready (Stefan's ruling). Phase-1 feature-complete, hardened, audited: suites 974+ unit / 458+ integration / 128+ acceptance green, acceptance gates main in CI. From here: features ship step by step, each through `harnessed-development`.

This document is the forward-looking plan. Sources of truth it consolidates: `memory/STATE.md` (Post-Launch Vision), `tasks/todo.md`, `docs/LAUNCH-CHECKLIST.md` (deploy-time list), auto-memory backlog entries. Update this file when a horizon item ships or a ruling changes its priority.

**Strategy anchor — pricing tiers (ruled 2026-07-03,** `project_pricing_tiers.md`**):**
- **Basic ~€400/mo** — e-learning, webinars, on-demand paid courses (B2C checkout), certificates, evaluations. Pitch: organisational/admin level, GDPR, managed hosting.
- **Pro** — + blended/attendance, trajectories, gates/intake, quotes/B2B, memberships, accreditation reports, mail broadcast. Upgrade trigger: "training leaves the browser."
- **Enterprise** — + Partner API, LTI, CRM/SSO, streaming provider, SLA. Trigger: other systems need access.

Roadmap items are tagged with the tier they enable: `[BASIC]` `[PRO]` `[ENT]` `[OPS]` `[DEBT]`.

**Two market bets (Stefan, 2026-07-03)** — each tier anchors one:

1. **BASIC → webinar-organisation bet.** Small orgs running webinars + e-learning + on-demand paid courses. Stride never streams — it owns everything *around* the stream (discovery, enrollment, payment, join links, attendance, certificates); delivery is pluggable (YouTube free → Cloudflare Stream white-label). Born from losing a client to WebinarGeek (€69/mo): that buyer alone is unwinnable on price, but the org that *also* needs certificates/admin/B2C sales is the Basic customer. **Build list = Horizon 1 items 1 + 3** (join-link mail, payment wrapper) — days-to-weeks, not months. **Attendance is NOT part of this bet (ruled 2026-07-03):** an admin can't verify who watched a webinar, so manual attendance marking stays out of the Basic flow entirely — the attendance module remains cleanly Pro. Basic webinar completion/certificate story: none at launch of the bet; it becomes honest only when the platform *reports* viewing (auto-attendance import / CF player heartbeats — H2), which is also the natural Basic→certificate upsell moment.

2. **PRO → corporate-onboarding bet.** Internal-academy vertical: bigger companies onboarding employees (HR/L&D buyer) — internal courses + policy documents + "who has read/completed what" tracking. Wedge = the `vad_document` design (itself born from an onboarding client ask). **Build list = vad_document + the gates (kennisname = policy acknowledgment, video-watched)**; the rest is configuration on a dedicated client instance — auto-enrollment needs NO build (LD course set to *open*, `isOpenCourse` adapter op = every employee has access; the no-registration-row/LD-progress tracking model is exactly what vad_document uses). SSO + supervisor-dashboard depth as per-deal Enterprise extras. No payments needed — simpler than training-provider flows.

(The Totara-refugee segment is the third revenue line but not a bet — it's opportunistic sales of what already exists, gated only on Horizon-3 hardening + the SCORM answer.)

---

## Horizon 0 — Launch operations (not features; blocks go-live)

| Item | Owner | Detail |
|---|---|---|
| Deploy tooling decision (Makefile vs git-push/Ploi webhook) | **Stefan (parked)** | `site.yml` declares `make deploy-staging`; no Makefile exists. Likely answer: git-push via Ploi auto-deploy. |
| Deploy-time checklist | at deploy | LAUNCH-CHECKLIST §deploy-time: nginx deny for `stride-proofs/`, `netdust_trusted_proxies` filter, LTI off, real SMTP, `stride_admin_email`, 6 footer pages replay, `test-login-helper` inert, ntdst-audit active, **`wp option delete upload_path upload_url_path` after any v3 DB port**. |
| Gate-reminder cron on prod | at deploy | Audit #7 (out-of-tree): ensure `ntdst_schedule_recurring` daily event actually registered on prod. |
| Redis enablement | after Q5 + deploy access | Audit #9: drop-in is prepped; needs Ploi plan confirmation. Procedure in site.yml notes. |

## Horizon 1 — Revenue enablers (next builds; sequence in listed order)

These make the tier strategy sellable. Small → large.

0. **⚠ URGENT — Mail broadcast / campaign layer** `[PRO]` — *medium (~1–1.5 weeks with test discipline).* **Marked urgent (Stefan, 2026-07-05).** Manual bulk send: build send-list → pick template → one-off adjust (override, never mutates the CPT) → send. **Nothing is built yet** (verified 2026-07-05): the `netdust-mail` engine is purely 1-template→1-recipient synchronous transactional; there is no `ndmail_recipient_sources` filter, no broadcast admin UI, no queue/cron drain, and the `ndmail_before_send` hook is registered but never applied (dead wiring). Build lives IN `netdust-mail` (must stay Stride-agnostic); Stride registers registration/edition/trajectory recipient sources through the new filter from `StrideMailBridge`. Five build areas: (1) `ndmail_recipient_sources` filter + generic sources (single address, pasted emails, pick WP users); (2) queue store + wp-cron batch drain with per-recipient sent/failed tracking + inline-vs-queued threshold (~25 inline, ~300 ceiling); (3) a real pre-send seam (wire up or replace the dead `ndmail_before_send`) for the one-off override + per-recipient short-circuit; (4) admin broadcast UI (list builder → template picker → adjust → send + progress view); (5) integration tests. Transport = plain `wp_mail()` + FluentSMTP. **Threat model required** (admin send action + recipient resolution). Full scope decisions in `project_mail_broadcast_feature.md` (Stefan, 2026-06-16); current-state map verified 2026-07-05.
0b. **⚠ URGENT — Profile-type enrollment gate + catalog flag + dashboard curation** `[PRO]` — *medium (~1 week, 11 tasks / 4 review clusters).* **Marked urgent (Stefan, 2026-07-05).** Three orthogonal single-job concepts driven by the existing Stride profile type (`ProfileTypeService`, one type/user): **(A)** a blunt `_stride_exclude_from_catalog` bool keeping internal courses off the normal catalog (not role-based); **(B)** a profile-type gate at the enroll seam — block / minimal-form / auto-voucher per profile type, enforced at the true chokepoints (`EnrollmentService::enroll()` + `TrajectorySelection::enroll()`, covering web/direct-URL/Partner-API/waitlist; interest exempt); **(C)** additive dashboard "Voor jou" curated page-links per profile type (per-page metabox → `UserDashboardService`, promotion only, pages never hidden/gated). **Nothing built yet** — no content-visibility machinery (that earlier framing was dropped as bug-prone). **Threat model required** (enroll authorization + auto-voucher money surface + admin metabox saves) — done, plan-embedded. Plan (gated, ready at the seam): `docs/plans/2026-07-05-profiletype-visibility-filter.md`, branch `feat/profiletype-enroll-gate`.
1. **Webinar join-link mail plumbing** `[BASIC]` — *small (~1–2 days).* `session.*` SmartCode group in `StrideMailBridge` + session-date-triggered reminder ("morgen om 10u, hier is je link") following the `GateReminderService` cron pattern. Everything else of the webinar flow already exists end-to-end (SessionType::Webinar, `webinar_link`, attendance→completion→certificate). Scoping: STATE.md Post-Launch Vision §Webinar flow.
2. **Kennisname/link-click gate** `[PRO]` — *small (~1 day).* 8th completion-task type (checkbox + per-edition URL, "Gelezen en begrepen"). Touches the known 9 places; scoping in STATE.md §New gate types. Consent-variant nearly free afterwards. Gates are Pro machinery — this build serves the onboarding bet (policy acknowledgment), NOT the webinar bet.
3. **Payment-gateway wrapper** `[BASIC]` — *medium; the Basic-tier prerequisite.* `PaymentGatewayInterface` in stride-core (createPayment/handlePush/refund/getStatus), payment state machine on registration, one webhook route; concrete gateways (Buckaroo — client ask; Mollie) in client mu-plugins, billed per client. **Threat model required** (webhook grants access). Design shape agreed in STATE.md §Online payments. First client pays the wrapper (~80%); later clients pay gateway-only.
4. **Tier fencing in config** `[OPS]` — per-client mu-plugin + FLAG default-off pattern; decide flag surface (service-registration gating vs capability checks). Tiers become config, not engineering. **Granularity ruling (2026-07-03): webinars in Basic pull the Edition/Session/enrollment machinery into every tier — so those are CORE, never fenced. Fences must be granular:** session *types* (in-person/blended = Pro), attendance module (Pro), trajectories, gates, quotes/B2B, memberships, Partner API — per-feature flags, not "Edition module on/off."
5. **stridelms.be pricing page** `[OPS]` — publish the ladder. Marketing-site location unknown — discovery first.

## Horizon 2 — Sales-driven product gaps (build when a deal needs them)

- **Courses on demand / incompany "op aanvraag"** `[PRO]` — 3 phases per STATE.md scoping: ① `vad_request` intake + lifecycle (small, standalone value) → ② private/company-scoped editions (**multi-tenancy boundary — threat model required**; visibility predicate must land in BOTH `EditionRepository` and theme `helpers/catalog.php`) → ③ partner frontend dashboard (large; forces minimal Company entity).
- **Trajectory phased choices** `[PRO]` — plan exists (`plans/2026-05-20-trajectory-phased-choices.md`), cascade prerequisite shipped. Per-keuzegroep `opens_at`/`deadline` + lazy task creation.
- **Video-watched gate** `[BASIC/PRO]` — medium; decide strictness first (client-side spoofable vs server heartbeats). STATE.md §New gate types.
- **Seat-type pricing (live vs stream seat)** `[BASIC]` — native ticket variants. Workaround exists today (two slots with `price_modifier`); build native only when a client needs it prominent in the enrollment UI.
- **Cloudflare Stream Live provider** `[ENT]` — `StreamingProviderInterface` mirroring the gateway pattern; per-registration signed playback tokens; BYO Cloudflare account. Full ruling + verified capabilities in STATE.md §Delivery integration. Phase 2: player-heartbeat auto-attendance.
- **Webinar auto-attendance import** `[PRO]` — platform webhook → `AttendanceService::markPresent` → existing completion chain. Pairs with either the CF provider or external platforms.
- **Course documents & onboarding tracking (`vad_document`)** `[PRO]` — *medium-large; design approved, client-driven. The wedge for the corporate-onboarding market bet (see top).* Structured document records at course/edition/trajectory level + secure download + per-employee tracking (enrolled · downloaded · started/finished · quiz results). Design: `docs/superpowers/specs/2026-06-26-course-documents-onboarding-design.md`. Note: the shipped "documents instruction field" (2026-06-30) is only the instruction text, NOT this document-record model. (Auto-enrollment for onboarding needs no build — LD open-course setting on a per-client instance covers it.)
- **Second-client deferred scope** (from `docs/plans/2026-06-11-road-to-client-2.md`, explicitly parked for "a separate roadmap" — this is that roadmap): **SSO** `[ENT]` (large — no design doc yet despite being on the Enterprise tier line), **FR/bilingual** (large), **WCAG remediation** (medium), **supervisor-dashboard depth** (medium), **funder-export generalization** beyond AnnualReportService (medium). All gated on a second-client deal; SSO needs a design doc before any Enterprise pitch names it.

## Horizon 3 — Deferred-module hardening (before first paying user of each module)

- **Trajectories** `[PRO]` — design done, **never shake-out tested**. Also: M2 quote race (audit), `validateSelections()` 4 field-shape leftovers cleanup, per-group deadline follow-ups. Gate: run `/shakeout` on the module before any client relies on it.
- **Partner API** `[ENT]` — 5 deferred bugs + C2/L2 + rate limiting; then extract to capability plugin (pathfinder for the plugin architecture, design `docs/superpowers/specs/2026-05-16-post-launch-capability-plugins-design.md`).
- **LTI** `[ENT]` — parked on staging; pre-existing `WPDataConnectorTest::canUpdateExistingPlatform` failure (likely `PlatformRepository::update()` title mapping). Sell per-deal only after un-parking. ⚠ Scope note: un-parking covers only the *tool-provider* work — the full **Platform-side design (AGS grade passback, NRPS roster sync, TinCanny xAPI bridge)** in `docs/LTI-LearnDash-TinCanny-Deep-Research.md` + `docs/plans/2026-02-26-lti-platform-design.md` is a separate LARGE build an Enterprise deal would surface immediately; scope it per-deal alongside the SCORM question.
- **SCORM decision** `[ENT]` — ⚠ doesn't exist in ANY tier (LearnDash has no native SCORM; needs GrassBlade LRS or similar). Scope before the first Totara-refugee pitch — it's their first due-diligence question.
- **Admin workspace phase 2** — frontend rebuild per `docs/plans/2026-06-24-admin-frontend-rebuild.md` + cohort lens (`2026-06-23-admin-workspace-2a-cohort-lens.md`). Open regressions to fold in: "indelen per" accordion rows (`bug_grid_groupby_lost_accordion_rows`), anon-lead name search.
- **AdminAPIController decomposition** `[DEBT]` — god class 2526 lines after cleanup; continue strangling per `project_unified_api_postlaunch` as workspace phases touch it.

## Horizon 4 — Platform polish backlog (no urgency; pick up opportunistically)

Enrollment timeline view · activity feed grouping · session-date→LD drip sync (`project_session_date_drip_sync`) · assistant exports (CSV/Excel/DOCX) + assistant evolution (headless/WP-CLI/event-triggered) · Phase-8 voucher automations (member renewal/reversal) · OGM payment reference (when Exact integration lands) · enrollment "Voor wie" step optional per edition · form schema edition→course move (Stefan leans course-only) · density modes · anonymise UX polish · conference capability plugin (`stride-conference`, design exists).

## Standing debt register (small, attach to whichever sprint touches the area)

From the 2026-06-10 audit close-out (todo.md §Follow-ups): registrations UNIQUE key at next SCHEMA_VERSION bump (never standalone ALTER) · dateless/self-paced catalog product ruling · `front-page.php` status-literal convergence + active-status grep sweep · `quote-admin.js` taxRate localization from QuoteCalculator (INV-8) · dashboard LD-floor product question (32 free e-learnings hydrate per user; blocks the inschrijvingen empty state) · ntdst-core nosniff/Mailer + ntdst-audit v1.1.0 fleet sync · D-Cap2 `recomputeStatus()` CLI · dead-table drops (task #21) · ntdst-auth translation files (B5-015) · **XLSX export "recover content" warning + latent OpenSpout v3/v4 conflict with FluentForm** (unresolved investigation, `docs/plans/excel-export-content-warning.md`).

## Decisions needed (ideas found in docs with no ruling)

- **FluentCommunity social/community layer** — named in `docs/ARCHITECTURE-V4-PROPOSAL.md` (BuddyBoss replacement) but never built, and the v3→v4 migration plan rules "no community features carry forward." Presumed dead — confirm and strike from V4 proposal, or park it here deliberately.
- **VAD→Stride historical-data migration scripts** (editions/enrollments backfill, `tasks/vad-to-stride-migration.md` Phases 10–11) — not a feature but launch-time DB work with no owner/date; belongs to the VAD go-live plan (H0 adjacent).

---

## Sequencing rationale

1. **Horizon 1 before everything** — it's what converts the 2026-07-03 pricing strategy into sellable SKUs, and items 1–2 are days, not weeks.
2. **Horizon 2 is demand-driven** — don't build on-demand/phased-choices/streaming speculatively; each has a scoped design ready so a deal can trigger a fast build.
3. **Horizon 3 is a hard gate, not a wishlist** — trajectories/Partner API/LTI are sellable on the pricing page but each needs its hardening pass before the first paying user touches it.
4. Every feature build enters via `harnessed-development` (Class per intake dial); security-triggering items above are flagged where the threat-model gate must fire.
