# Codebase Audit — Bugs, Gaps, Hardening

Adversarial whole-repo review. Output is judged on verified findings, not volume.
A finding without a traced code path is noise and counts against the review.

## Fill before running

- **App:** Stride — LMS/training-management platform (VAD launch brand, 4000+ users): public course catalog + enrollment (editions/sessions), trajectories, quotes (Exact Online does real invoicing), attendance, certificates, a Partner REST API for company-scoped enrollment management, and an admin workspace. If it breaks, public enrollment stops, admins can't process registrations/quotes, and partner organisations lose API access.
- **Stack:** WordPress Bedrock / PHP 8.3 / MariaDB 10.11. Business logic in the `stride-core` mu-plugin on the in-house NTDST Core framework (DI container, Router, Data API, repositories); presentation in the `stridence` theme (Tailwind + Alpine.js + Vite). LearnDash as content engine behind an `LMSAdapterInterface`; FluentCRM/FluentForms/FluentSMTP; netdust-mail for templated mail. Hosted on Ploi, deployed via Makefile; local dev on DDEV.
- **Business goal for Phase 4:** Ship the VAD production launch (`docs/LAUNCH-CHECKLIST.md` is the authoritative gate) with zero P0 issues on the launch surfaces — public enrollment, quote/mail flows, Partner API, admin workspace — and keep the codebase able to onboard a second client brand via a `stride-client-<slug>` mu-plugin with zero `stride-core` changes.
- **Known issues:** (intentionally empty — first run of this audit prompt; findings that match already-tracked issues act as calibration canaries)
- **Out of scope:** `vendor/`, `node_modules/`, `web/wp/` (WordPress core), `web/app/plugins/` (Composer-managed third-party plugins), `web/app/uploads/`, `web/app/languages/`, `web/app/themes/stridence/dist/` (build output), `docs/`, `docs/mockups/`, `web/admin-mockup-preview/` (static mockups, never deployed), `_backup/`. In scope: `web/app/mu-plugins/stride-core/`, `web/app/mu-plugins/ntdst-core/` (first-party framework), `web/app/mu-plugins/stride-client-vad/` and loaders, `web/app/themes/stridence/` (PHP + `src/`), `scripts/`, `config/`, `tests/` (as evidence of coverage, not an audit target).

## Ground rules (apply to every phase and every subagent)

1. **Evidence or silence.** Every finding cites `path/file.ext:line-range` and quotes
   the exact code. No finding based on a filename, a comment, or what a function
   "probably" does.
2. **Classification is mandatory:**
   - **CONFIRMED** — full path traced from input/trigger to failure. Must include
     repro steps or a failing-test sketch.
   - **LIKELY** — strong evidence with exactly one named unverified assumption. Name it.
   - **SPECULATIVE** — smell only. Hard cap: 5 in the entire report.
3. **Severity = impact × reachability.** State who can reach the path
   (anonymous / authenticated / admin / internal). An injection behind an admin
   capability check is not the same class as one on a public route.
   - P0: data loss, auth bypass, or injection on a reachable path
   - P1: data-integrity or authz gap requiring specific conditions
   - P2: failure-handling defect (swallowed errors, partial writes, no rollback)
   - P3: hygiene with a concrete failure scenario attached
4. **Banned output:** style opinions; "add comments/types/docs"; "consider more tests"
   without naming the specific untested failure; framework or rewrite suggestions;
   any generic best practice not tied to quoted code.
5. **Depth over coverage.** 8 verified findings beat 40 guesses. Declare what you
   skipped instead of pretending coverage.
6. **Context discipline.** Write intermediate output to `./review-scratch/`.
   The orchestrator never pastes file contents into its own context — subagents
   read files, the orchestrator reads findings.

## Phase 0 — Cheap signal first

Before reading any code by hand:

1. Run what exists: test suite, type checker, linters, `composer audit` /
   `npm audit` / `bun audit`. Record pass/fail counts in the scorecard. Do not
   re-report tool output unless you add reachability or exploit analysis on top.
   (Stride specifics: `ddev exec vendor/bin/phpunit --testsuite Unit`,
   `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist`,
   `ddev exec composer lint:stan`, `composer lint` = Pint + PHPStan.)
2. Map the attack/failure surface: HTTP routes, REST/AJAX handlers, CLI commands,
   cron jobs, webhooks, file uploads, auth boundaries, every DB write path,
   secrets/config loading.
3. Write `review-scratch/targets.md`: the 6–10 highest-risk surfaces with one line
   of justification each. This list gates Phase 1 — nothing off-list gets deep
   review this run.

## Phase 1 — Adversarial hunt (parallel subagents)

Dispatch one subagent per Phase 0 target. Each subagent receives its surface, the
ground rules, and the stack checklist below. Each returns ONLY findings in the
output schema — no narration, no file dumps.

Hunt classes, in priority order:

- **AuthZ:** missing capability/permission checks; object-level access (can user A
  touch user B's records? can partner A see company B's registrations?);
  CSRF/nonce gaps on state-changing endpoints
- **Input → sink:** SQL/shell/path injection; unsafe deserialization; upload
  handling; anything trusting client input, webhook payloads, or third-party API
  responses
- **Data integrity:** writes without constraints or transactions; duplicate-on-retry;
  race conditions; missing idempotency on anything reachable twice
- **Failure handling:** swallowed exceptions on write paths; partial-failure states
  with no rollback; retries without backoff
- **Secrets/config:** credentials or tokens in the repo or build artifacts;
  debug/test bypasses reachable in production builds
- **Resource:** unbounded queries; N+1 on hot paths; missing pagination; anything
  that grows with user count and has no limit

Stack-specific:

**WordPress/PHP:** raw `$wpdb->query` without `prepare()`; REST routes with
`permission_callback => '__return_true'`; `current_user_can` missing on AJAX
handlers; nonce checked but capability not, or vice versa; options/meta written
from unvalidated `$_POST`; hooks firing writes without guards; includes/requires
built from request data.

## Phase 2 — Hardening gaps (checklist, not brainstorm)

Answer each YES / NO / PARTIAL, with evidence for the answer:

1. Can this deploy from a clean machine using only what's in the repo?
2. Can a bad deploy be rolled back? Are migrations reversible, and is that tested?
3. Do DB constraints enforce every invariant the application code assumes?
   List invariants that are assumed but unenforced.
4. Are data-mutation and money paths covered by tests that would fail on regression?
5. If this breaks in production at 02:00, do the existing logs let you find the cause?
6. Is there a backup/restore path, and has restore ever been exercised?

## Phase 3 — Falsification pass (mandatory, before writing the report)

Take every CONFIRMED and LIKELY finding and try to kill it: search for the
validation you missed, the upstream middleware, the constraint defined elsewhere,
the config that disables the path. Downgrade or delete accordingly. Each surviving
finding records what was checked during falsification. A report that skips this
phase is invalid.

## Phase 4 — Next level (max 5 items)

Derived ONLY from the stated business goal plus Phase 1–2 evidence. Each item:
what it unblocks, effort (S/M/L), ordering dependency, and the specific finding
or gap that motivates it. Anything not traceable to a finding or the goal is cut.

## Output — write `REVIEW.md`

1. **Scorecard** — tool results, targets reviewed vs skipped, finding counts by class
2. **Findings table** — id, severity, class, reachability, file:line, one-liner;
   sorted by severity
3. **Detailed findings** — evidence quote, repro or failing-test sketch,
   falsification notes
4. **Gap checklist** — the six answers with evidence
5. **Next-level list**
6. **Not reviewed** — every surface skipped and why. Mandatory. An audit claiming
   full coverage of a large repo in one session is lying.
