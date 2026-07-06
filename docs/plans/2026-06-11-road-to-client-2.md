# Road to Client #2 — Operational & Commercial Readiness Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans for the Claude-executable tasks. Steps use checkbox (`- [ ]`) syntax for tracking. Tasks marked **Owner: Stefan** are decisions or human actions — Claude drafts, Stefan decides/signs/sends.

**Goal:** Close every gap that stands between "VAD is live" and "a second client can sign without their IT, DPO, or legal flagging a liability" — without destabilizing the VAD launch happening this week.

**Architecture of the plan:** Four phases. Phase 0 protects the launch (no new scope, just the open checklist items — most gated on one parked decision). Phase 1 builds the deal-blocker pack (contract/DPA/SLA/continuity — what reviewers ask for first). Phase 2 builds the ops floor that makes "managed" true (monitoring, restore, rollout, provisioning). Phase 3 builds sales collateral (one-pager, demo, case study, competitor intel). A final gate checklist defines "client-2 ready."

**Ground truth this plan is based on (verified 2026-06-11):**
- Audit re-audit grade B+, 0 Criticals; suites 924 unit + 369 integration + 121 acceptance green (`docs/architecture/AUDIT-2026-06-10-reaudit.md`)
- No Makefile exists; `site.yml` declares `deploy.method: makefile` — deploy method decision parked by Stefan, and it gates test-login exclusion, Redis enablement, and the deploy runbook
- `RegistrationTable.php` is at SCHEMA_VERSION 2; UNIQUE on (user_id, edition_id) planned for v3; duplicates exist in real data
- `AnnualReportService` is already parameterized (year-driven, generic sections) — NOT VAD-hardcoded
- `scripts/seed.php` (2,149 lines) is demo-credible: 16 courses, 50+ editions, 100+ sessions, trajectories, vouchers, quotes
- Fleet workspace (`~/Sites/netdust-wp-manager`): `new-site.sh` one-command provisioning EXISTS; restic backup to Synology EXISTS (password hardcoded — fix); NO restore script, NO uptime/alerting, NO onboarding playbook, NO contract/SLA/DPA templates anywhere
- Pipeline: RADAR/Karus meeting confirmed (Professional tier), Çavaria re-engage Q3 (`netdust-wp-manager/memory/projects/stride-lms/PIPELINE.md`); pricing locked per `DECISIONS.md` — this plan does NOT reopen pricing tiers

**Explicitly OUT of scope** (separate roadmap plan after this one): SSO, FR/bilingual, WCAG remediation, supervisor dashboard depth, funder-export generalization beyond AnnualReportService, AdminAPIController decomposition. Don't let any of these creep into the next 6 weeks.

---

## Decisions Stefan must make (front-loaded — everything else flows from these)

| # | Decision | Blocks | Deadline |
|---|----------|--------|----------|
| D1 | **Deploy method** (recommend: Ploi git-push webhook running `composer install --no-dev` + post-deploy script; update `site.yml` to `method: git-push`) | Phase 0 entirely: deploy runbook, test-login exclusion, Redis enablement | Before launch |
| D2 | **Redis on Ploi plan** — confirm Redis is available/provisioned on the production server (Q5 from audit) | Task 0.4 | Before launch |
| D3 | **SLA numbers** — uptime target (recommend 99.5%), support hours (recommend business hours NL, explicitly NOT 24/7), response times by severity (recommend: P1 4 business hours, P2 1 business day, P3 3 business days) | Task 1.1 | Week 1 |
| D4 | **Lawyer engagement** — who reviews the MSA/DPA/SLA drafts; budget ~€1.5–3k | Tasks 1.1–1.3 sign-off | Week 1 |
| D5 | **Named continuity person** — which contractor/peer gets repo access + runbook + a paid yearly walkthrough | Task 1.4 | Week 2 |
| D6 | **VAD reference ask** — request written permission for named reference + case study | Task 3.3 | Week 2 (after launch is stable, before RADAR/Karus) |
| D7 | **Demo brand** — reuse BWEEG fictional brand vs. neutral "Stride" brand for demo.stridelms.be | Task 3.2 | Week 3 |

---

## Phase 0 — Protect the VAD launch (this week; no new scope)

Everything here is already on `docs/LAUNCH-CHECKLIST.md`. Listed for sequencing only — the checklist remains the source of truth. **Nothing from Phases 1–3 starts until launch + 3 calm days.**

### Task 0.1: Settle the deploy method (D1) and document it

**Owner:** Stefan decides; Claude documents.
**Files:**
- Modify: `site.yml` (deploy block — change `method: makefile` to the chosen method)
- Create: `docs/ops/DEPLOY.md`

- [ ] **Step 1:** Stefan confirms: Ploi git-push webhook is the method (or names the alternative).
- [ ] **Step 2:** Verify on the Ploi panel what the deploy script actually runs for `app.stridelms.be` (staging). Capture it verbatim.
- [ ] **Step 3:** Write `docs/ops/DEPLOY.md`: branch → push → webhook → server steps (composer install --no-dev, cache flush, anything else the Ploi script does), rollback procedure (`git revert` + push; DB rollback = restore procedure ref to Task 2.2), and the post-deploy verification list already in `site.yml` (nginx deny rule curl test, plugin checks).
- [ ] **Step 4:** Update `site.yml` `deploy:` block to match reality. Commit both together.
- [ ] **Step 5:** Do one full staging deploy following ONLY the doc, fixing the doc where it lies. The doc is done when a stranger could deploy from it.

### Task 0.2: Exclude test-login-helper.php from the production artifact

**Owner:** Claude (one line once D1 is settled).
**Files:**
- Modify: the Ploi deploy script (on-panel) OR `.deployignore`-equivalent depending on D1 outcome

- [ ] **Step 1:** Add to the production deploy script, after checkout/composer: `rm -f web/app/mu-plugins/test-login-helper.php` (production target only; staging keeps it — acceptance suite uses it).
- [ ] **Step 2:** Deploy to production path (or dry-run), then verify: `ssh <prod> 'test -f .../web/app/mu-plugins/test-login-helper.php && echo PRESENT || echo ABSENT'` → expect `ABSENT`.
- [ ] **Step 3:** Record the exclusion in `docs/ops/DEPLOY.md` so a future deploy-script rewrite doesn't drop it. Close audit finding L-2.

### Task 0.3: Remaining launch-checklist ops items

**Owner:** Stefan (server/panel access) with Claude assistance. Per `docs/LAUNCH-CHECKLIST.md`: nginx deny rule for completion proofs (Ploi panel, rule text in `site.yml:35-36`), real SMTP creds in FluentSMTP, `stride_admin_email` to real VAD inbox, deactivate `netdust-lti`, recreate 6 footer pages, verify ntdst-audit active post-deploy.

- [ ] **Step 1:** Work through the checklist items above on staging, then production, ticking them off in `docs/LAUNCH-CHECKLIST.md` itself.

### Task 0.4: Enable Redis (staging now, production in week 1 post-launch)

**Owner:** Claude, gated on D1 + D2.
**Files:**
- Create (on servers, via deploy): `web/app/object-cache.php` drop-in
- Reference: `site.yml:40-45` (documented procedure)

- [ ] **Step 1:** After D2 confirms Redis on the server: enable on **staging** — `wp redis enable` (copies the drop-in), verify `wp redis status` → `Connected`.
- [ ] **Step 2:** Run the acceptance suite against staging with Redis on (121 scenarios). Expected: green. Any failure → `wp redis disable`, diagnose before retrying.
- [ ] **Step 3:** Capture baseline timings: catalog page + dashboard TTFB with and without Redis (3 runs each, curl `-w '%{time_starttotal}'`). Record in `docs/ops/DEPLOY.md`.
- [ ] **Step 4:** Production: enable in a low-traffic window in week 1 post-launch (NOT launch day), same verification. Honor the fleet rule: **never flush Redis globally on LMS sites** — note the VAD cache-exclusion rule from `netdust-wp-manager/memory/GLOBAL.md` in the doc.

---

## Phase 1 — Continuity & contract pack (weeks 1–2 post-launch)

This is what a prospect's legal/DPO reviewer asks for before anyone looks at features. Claude drafts everything; lawyer (D4) reviews; Stefan owns numbers and signatures. All drafts live in a NEW private repo — **not** in the Stride product repo: `~/Sites/netdust-wp-manager/commercial/` (it's the business workspace; create `commercial/contracts/`, `commercial/collateral/`).

### Task 1.1: SLA document

**Owner:** Claude drafts from D3; lawyer reviews.
**Files:**
- Create: `~/Sites/netdust-wp-manager/commercial/contracts/SLA-v1.md` (Dutch, since buyers are Flemish nonprofits)

- [ ] **Step 1:** Draft with sections: scope of "managed" (hosting, WP core/plugin/theme updates for declared `stack.plugins` only — codify the existing fleet rule from `memory/feedback_sla_scope_third_party_plugins.md`: third-party installs are billable emergency response); uptime target, measurement method, and exclusions (maintenance windows); support channel + hours + response times by severity (from D3); maintenance windows; backup frequency + retention + RTO/RPO (numbers come from Task 2.2's measured restore — leave bracketed placeholders until that lands); escalation path; what is explicitly NOT covered (24/7, custom feature development, content authoring).
- [ ] **Step 2:** Stefan sanity-checks the hours math: at the response times promised, estimate weekly hours per client; confirm Basic tier (€4,800) covers hosting + those hours + margin. If it doesn't, the SLA gets less generous — not the price lower (pricing is locked per DECISIONS.md).
- [ ] **Step 3:** Lawyer review (D4). Incorporate. Tag v1.0.

### Task 1.2: DPA + sub-processor register + retention schedule

**Owner:** Claude drafts; lawyer reviews. The single most procurement-critical artifact for health/social sector buyers.
**Files:**
- Create: `commercial/contracts/DPA-v1.md`
- Create: `commercial/contracts/subprocessors.md`
- Create: `commercial/contracts/retention-schedule.md`

- [ ] **Step 1:** Build the sub-processor register first (it feeds the DPA): Hetzner (VPS, DE/FI — confirm datacenter location), Ploi (server management panel, NL), the SMTP relay behind FluentSMTP (confirm which provider VAD production uses — from Task 0.3), Synology NAS (Stefan-controlled, on-prem backup target). For each: name, role, data touched, location, transfer mechanism. Flag any non-EU sub-processor loudly — the data-sovereignty pitch depends on this table being clean.
- [ ] **Step 2:** Draft the retention schedule per data class: learner account data (active + N months after last activity), registrations/attendance (justify the long retention via subsidy-audit obligations — name the legal basis, e.g. funder verification requirements; if no written basis exists for the current 7-year audit retention, Stefan decides a defensible number with the lawyer), quotes/invoices (7y, fiscal law — solid basis), audit logs, mail logs, backups. Cross-reference the existing GDPR anonymisation feature (shipped per LAUNCH-CHECKLIST §D-G) as the erasure mechanism.
- [ ] **Step 3:** Draft DPA: roles (client = controller, Netdust = processor), processing purposes, data categories (note: learner training records in addiction/health-adjacent context — flag as potentially sensitive-adjacent, lawyer to advise), security measures (summarize from the audit: server-side authorization w/ CI enforcement, encrypted transport, access control, audit logging, fail-closed migrations), breach notification (72h to controller), DSAR support obligations + how Stride fulfills them today (manual export — honest; self-serve DSAR export is roadmap), sub-processor change notification, exit/return-of-data clause (see Task 1.3).
- [ ] **Step 4:** Lawyer review. Incorporate. Tag v1.0.

### Task 1.3: MSA / subscription agreement with exit clause

**Owner:** Claude drafts; lawyer reviews.
**Files:**
- Create: `commercial/contracts/MSA-v1.md`

- [ ] **Step 1:** Draft: subscription scope per tier (Basic/Professional/Enterprise — pull tier contents from the locked pricing decision; do NOT invent new tier features), term + renewal + indexation, onboarding/data-migration priced as a **separate one-time project** (this is the one pricing addition the plan makes — migration was the hidden unpriced cost), IP (Netdust owns platform; client owns content + data), liability caps, **exit clause**: on termination client receives full Bedrock repo snapshot, DB dump, and uploads within 30 days, in a documented runnable form — this clause is what makes "managed self-hosted" a real category instead of marketing. Reference the DPA and SLA as annexes.
- [ ] **Step 2:** Lawyer review. Incorporate. Tag v1.0.

### Task 1.4: Continuity / bus-factor answer

**Owner:** Stefan (D5 names the person); Claude writes the runbook.
**Files:**
- Create: `~/Sites/netdust-wp-manager/docs/RUNBOOK.md` (fleet-level: written for a competent stranger)
- Create: `commercial/contracts/continuity-annex.md` (one page, client-facing)

- [ ] **Step 1:** Write `RUNBOOK.md` covering, per site (Stride first): where the repo lives + access, hosting (Ploi org access, server, SSH aliases), deploy procedure (link `docs/ops/DEPLOY.md`), backup location + restore procedure (link Task 2.2 output), DNS/registrar, credentials location (password manager — name it), monitoring (Task 2.1 output), the 5 most likely incidents and their first moves (site down, mail not sending, disk full, bad deploy rollback, Redis misbehaving), and who the client contacts are.
- [ ] **Step 2:** Stefan grants the D5 person: repo access (read), Ploi guest access, runbook copy, and books a 2-hour paid walkthrough. Calendar a yearly refresh.
- [ ] **Step 3:** Write the client-facing continuity annex (one page): "In case of incapacity of the principal engineer: [named arrangement — contractor on call / escrow], client's exit rights per MSA §exit apply at any time, all infrastructure is standard (Bedrock WordPress, documented), recovery time commitment." This annex is what gets handed to the prospect's IT reviewer when they ask the bus question.
- [ ] **Step 4:** Test: D5 person (or Claude simulating a stranger, as a weaker proxy) walks the runbook for one scenario ("staging site down — investigate and report") touching nothing they can't find from the doc alone. Fix every gap found.

---

## Phase 2 — Ops floor: make "managed" true (weeks 2–4)

### Task 2.1: Uptime monitoring + alerting

**Owner:** Claude sets up; Stefan installs the phone app.
**Files:**
- Create: `~/Sites/netdust-wp-manager/docs/MONITORING.md`

- [ ] **Step 1:** Pick the boring option: UptimeRobot (or Better Stack free tier) — HTTP checks on `app.stridelms.be` (homepage + `/wp-json/` + the login page), 5-min interval, alert to email + push. Add every other SLA-covered fleet site while in there.
- [ ] **Step 2:** Add a Ploi-level check: confirm Ploi's built-in server monitoring (CPU/disk/memory alerts) is enabled for the production VPS, alert email correct.
- [ ] **Step 3:** Schedule the existing `scripts/wp-status` sweep: cron (or systemd timer) on the workstation or a small runner, weekly, output diffed against last run, mail on drift (new available updates, version changes). It exists and is manual today — scheduling it is the whole task.
- [ ] **Step 4:** Document in `MONITORING.md`: what's monitored, where alerts go, what each alert means, first-response pointer into `RUNBOOK.md`.

### Task 2.2: Restore script + tested restore (RTO/RPO measured)

**Owner:** Claude. The backup that has never been restored is a hope, not a backup.
**Files:**
- Create: `~/Sites/netdust-wp-manager/scripts/restore-synology.sh`
- Modify: `~/Sites/netdust-wp-manager/scripts/backup-synology.sh` (remove hardcoded restic password → env var / `~/.config/restic/password` file, chmod 600)

- [ ] **Step 1:** Fix the hardcoded restic password in `backup-synology.sh` (move to a password file referenced via `RESTIC_PASSWORD_FILE`). Verify next backup run still succeeds.
- [ ] **Step 2:** Write `restore-synology.sh <site> <snapshot|latest> <target-dir>`: list snapshots, restore selected, print next steps (DB import, .env reconstruction). Keep it dumb and readable.
- [ ] **Step 3:** **Execute a real restore**: restore the Stride site from the latest snapshot into a scratch DDEV project, import the DB dump, bring it up, log in. Time the whole thing.
- [ ] **Step 4:** Separately verify the PRODUCTION backup path: confirm what actually backs up the production server (Ploi backups? server-side restic? — ground-truth this; the Synology script backs up the local workstation's `~/Sites`, which is NOT the production DB). If production DB backups don't exist or aren't verified, this becomes the top item in this phase: set up nightly DB dump + offsite (Hetzner Object Storage is already in GLOBAL.md as intended infra), then restore-test THAT.
- [ ] **Step 5:** Write measured RTO/RPO into `RUNBOOK.md` and backfill the bracketed numbers in `SLA-v1.md` (Task 1.1).

### Task 2.3: Fleet update rollout procedure

**Owner:** Claude.
**Files:**
- Create: `~/Sites/netdust-wp-manager/docs/UPDATE-ROLLOUT.md`
- Possibly modify: `scripts/plugin-update`, `scripts/wp-all`

- [ ] **Step 1:** Document the rollout sequence for a WP/plugin security release across N client sites: DDEV first → staging → acceptance smoke → production, per site; order sites by risk (`site.yml` risk field); include the stride-core propagation obligations pattern (`memory/projects/stride/PROPAGATION-2026-06-10.md` shows vendored ntdst-core copies must sync — fold that check into the procedure).
- [ ] **Step 2:** Extend `scripts/plugin-update` (or wrap it) so one invocation handles one site's full cycle with a confirm gate between staging and production. Don't build canary/blue-green — two clients don't need it; the doc + script is the deliverable.
- [ ] **Step 3:** Dry-run the procedure on the next real plugin update that arrives; fix the doc where it lies.

### Task 2.4: Incident response one-pager

**Owner:** Claude drafts, Stefan approves.
**Files:**
- Create: `~/Sites/netdust-wp-manager/docs/INCIDENTS.md`

- [ ] **Step 1:** One page: severity definitions (mirror SLA), first-15-minutes checklist per severity, client communication templates (NL: "we zijn op de hoogte", "opgelost + wat er gebeurde"), post-incident note format (what/impact/cause/prevention — 10 lines max), where notes get filed (`memory/projects/<site>/incidents/`). The Cargo May-2026 indexing incident write-up is the house style reference.

### Task 2.5: Client provisioning playbook (the "client #2 onboarding" path)

**Owner:** Claude.
**Files:**
- Create: `~/Sites/netdust-wp-manager/docs/CLIENT-ONBOARDING.md`

- [ ] **Step 1:** Chain the pieces that already exist into one ordered checklist with time estimates: signed MSA/DPA/SLA → Ploi server or site provisioning (+ `/secure-server` hardening) → `new-site.sh` local scaffold → Stride repo deploy → client mu-plugin via the proven rebrand workflow (`pattern_client_rebrand_workflow`: IDENTITY.md → 5-layer LD skin → block patterns) → DNS/SSL → SMTP → monitoring added (Task 2.1) → backups verified (Task 2.2) → seed/demo content or migration project kickoff → client admin training session → go-live verification list (reuse `site.yml` post-deploy checks).
- [ ] **Step 2:** Walk the playbook end-to-end ONCE for the demo environment build (Task 3.2 doubles as the dry-run). Every step that takes longer than its estimate or needs undocumented knowledge → fix the doc. Output: a realistic "days from signature to live" number you can say in a sales meeting.

### Task 2.6: UNIQUE constraint migration (SCHEMA_VERSION 3)

**Owner:** Claude, via the normal harness (this is product code — branch + tests + review).
**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php`
- Test: `tests/Integration/` (migration test alongside the existing version-gated migration tests)

- [ ] **Step 1:** Wait for 1 calm week of production. Then, on a branch: write the failing integration test — seed two duplicate (user_id, edition_id) rows at schema v2, run `migrate()`, assert: duplicates collapsed by the documented survivor rule (keep the row with the most-advanced status, else lowest id — confirm survivor rule against how commit `8148deaa`'s bug fix chose), UNIQUE key present, schema version = 3.
- [ ] **Step 2:** Implement v3 in `migrate()`: dedup pass (single SQL with self-join, wrapped in the existing failure-backoff pattern), then `ALTER TABLE ... ADD UNIQUE KEY uq_user_edition (user_id, edition_id)`, replacing `idx_user_edition`.
- [ ] **Step 3:** Audit every `INSERT` path into the table (`RegistrationRepository::create` + any direct writes) for the new duplicate-key error path: decide and implement the behavior (return existing registration as WP_Error or idempotent success — match what `8148deaa` established).
- [ ] **Step 4:** Run on staging against a fresh production DB copy (`db-pull.sh`), verify dedup count looks sane vs. a pre-count query. Then production via normal deploy. Close audit task 1.2.

---

## Phase 3 — Commercial collateral (weeks 3–5, overlapping Phase 2)

### Task 3.1: Security & architecture one-pager (buyer-facing)

**Owner:** Claude drafts, Stefan approves. Nearly free — the material exists in the audit docs.
**Files:**
- Create: `commercial/collateral/security-onepager.md` (then PDF; NL + EN versions)

- [ ] **Step 1:** Two pages max, written for a non-developer IT reviewer: hosting & data location (EU, named provider), independent audit summary (date, grade, 0 critical findings — quote the re-audit), authorization enforced server-side with automated CI enforcement, 1,400+ automated tests run on every change, GDPR posture (anonymisation feature, DPA available, sub-processor register), backup + tested restore (RTO/RPO from Task 2.2), update policy, responsible-disclosure contact. No jargon: "INV-1" becomes "every admin action is permission-checked on the server, and our build system blocks any code change that violates this."

### Task 3.2: Demo environment

**Owner:** Claude builds; Stefan picks the brand (D7).
**Files:**
- Create: demo site at `demo.stridelms.be` (Ploi)
- Create: `~/Sites/netdust-wp-manager/docs/DEMO.md` (how to reset/refresh it)

- [ ] **Step 1:** Provision via the Task 2.5 playbook (this IS the playbook dry-run): new Ploi site on the existing server, deploy Stride, apply the D7 brand mu-plugin.
- [ ] **Step 2:** Seed with `scripts/seed.php` (already demo-credible). Sweep the seeded content for anything test-flavored (`seed_student1@seed.test` style emails are fine for logins but rename display names to realistic Flemish names; check course titles read like a real vormingsaanbod).
- [ ] **Step 3:** Build the demo script (the sales artifact, not code): a 20-minute walkthrough document keyed to the evaluation's competitive wedge — scheduled editions + sessions + attendance hours + completion certificate + the admin "Acties nodig" panel + a partner-API call + the annual report. Lead with what Smart Lions and TalentLMS can't show.
- [ ] **Step 4:** Write `DEMO.md`: reset procedure (`FORCE_UNSEED=1 unseed.php` + `seed.php`), credentials, and the rule that demo never holds real data.

### Task 3.3: VAD case study + reference permission

**Owner:** Stefan asks (D6); Claude drafts.
**Files:**
- Create: `commercial/collateral/case-study-vad.md` (then designed PDF)

- [ ] **Step 1:** After 1–2 stable weeks of production: Stefan emails VAD contact for (a) named-reference permission, (b) a 30-min interview, (c) sign-off on the draft. Get it in writing.
- [ ] **Step 2:** Claude drafts from real numbers: users migrated, courses/editions live, registrations processed, what replaced what (6+ admin tools → unified dashboard), one quote from the VAD coordinator. One page. The honest version sells better than the inflated one.

### Task 3.4: Competitor intel before the RADAR/Karus meeting

**Owner:** Claude (deep-research). **Timing: as soon as the meeting date is known — this may need to jump the phase order.**
**Files:**
- Create: `commercial/collateral/competitor-brief.md`

- [ ] **Step 1:** Run deep-research on Smart Lions: current offering, named clients, pricing signals, LMS engine, support model, weaknesses visible in reviews/cases. Secondary sweep: what RADAR/Karus uses today for training management (their site, vacancies, public tenders).
- [ ] **Step 2:** Distill to one page: 3 likely objections in the meeting + the honest answer to each (including the bus-factor answer from Task 1.4 — bring the continuity annex to the meeting).

### Task 3.5: Pricing hours-math + migration fee

**Owner:** Stefan with Claude as calculator. Pricing tiers stay locked; this only verifies cost coverage and adds the onboarding fee.

- [ ] **Step 1:** Model per-client annual hours honestly: monitoring response, update rollouts (Task 2.3 procedure × release cadence), support at SLA response times, hosting cost. Compare against each tier.
- [ ] **Step 2:** Set the onboarding/migration fee policy (fixed-fee tiers by data volume, or day rate with estimate) and write it into the MSA (Task 1.3). The Houvast quote structure is the house reference for how to package it.

---

## Gate: "Client-2 ready" checklist

Signing a second client without meaningful operational or reputational risk requires ALL of:

- [ ] VAD production stable ≥2 weeks, monitoring quiet (Task 2.1 live)
- [ ] Deploy documented + reproduced by the doc alone (0.1), backdoor excluded from prod artifact (0.2)
- [ ] SLA v1.0, DPA v1.0 + sub-processor register, MSA v1.0 with exit clause — lawyer-reviewed (1.1–1.3)
- [ ] Continuity annex exists; D5 person has access + has walked the runbook (1.4)
- [ ] Production backups verified by an actual timed restore; RTO/RPO in the SLA (2.2)
- [ ] Update-rollout procedure written and exercised once (2.3)
- [ ] Onboarding playbook walked end-to-end once, with a days-to-live number (2.5)
- [ ] Demo environment live + demo script (3.2); security one-pager (3.1); case study or at minimum written reference permission (3.3)
- [ ] UNIQUE constraint shipped (2.6) — not deal-blocking, but in before client-2 data exists

When every box ticks: open the RADAR/Karus deal in earnest. Until then, meetings are discovery, not closing.

---

## Effort & sequencing summary

| Phase | Calendar | Stefan-hours (approx) | Claude-executable share |
|---|---|---|---|
| 0 — Launch protection | this week | 4–6h (D1, D2, panel work) | ~60% |
| 1 — Contract pack | wk 1–2 | 6–8h + lawyer | ~70% (drafting) |
| 2 — Ops floor | wk 2–4 | 4–6h | ~85% |
| 3 — Collateral | wk 3–5 | 6–8h (VAD ask, demo review, pricing) | ~75% |

Single biggest schedule risk: D1 (deploy method) stays parked — it blocks four items in Phase 0 alone. Second biggest: lawyer turnaround on Phase 1 — engage D4 in week 1, not when drafts are "perfect."
