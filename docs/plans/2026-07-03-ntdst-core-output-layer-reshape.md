# ntdst-core Output-Layer Reshape (Router / Endpoints / Response) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `netdust-agent:harnessed-development` Stage 2 — dispatch `netdust-agent:implementer` per task. Steps use checkbox (`- [ ]`) syntax for tracking. Every `── REVIEW GATE ──` marker is a STOP: the controller dispatches the review at the stated tier before the next cluster starts.

**Goal:** Give ntdst-core a real, CORS-aware REST registration path (`ntdst_router()->rest()` backed by `register_rest_route()`), make `NTDST_Response` the single owner of the API output envelope, fix the latent `Router::handleTemplateInclude()` Response-handling bug, and re-scope `NTDST_Endpoints` as the documented same-origin nonce-gated action dispatcher — with zero behavior change to any existing consumer.

**Architecture:** Two new final classes in `ntdst-core/api/` — `NTDST_Rest_Registrar` (per-namespace route registration, required-permission enforcement, WeakMap permission memoization, pre-dispatch body/depth limits, handler-return normalization) and `NTDST_Cors_Policy` (exact-origin allow-list header emission that overrides WP core's reflection+credentials default). `NTDST_Router` gains a 5-line `rest()` facade; `NTDST_Response` gains static envelope builders + a non-exiting `toRestResponse()`; `NTDST_Endpoints` delegates its private envelope builders to `NTDST_Response` behavior-preservingly and is re-scoped in documentation only.

**Tech Stack:** WordPress REST API (`register_rest_route`, `rest_pre_dispatch`, `rest_pre_serve_request`), PHP 8.2+ (Stride runs 8.3), PHPUnit (Stride: 706 unit / 261 integration tests — both suites must stay green), DDEV.

**Work class:** A (architectural, security-relevant dispatch/CORS, affects every ntdst-core project). Executed in **Stride** (`~/Sites/stride`), Stride-first-then-documented-propagation per the GLOBAL.md 2026-06-12 precedent. Canonical-repo migration was considered and **rejected** for this pass (Stefan's decision — do not reopen).

## Global Constraints

- `declare(strict_types=1)` first line of every new/modified PHP file.
- ntdst-core style: classic `NTDST_`-prefixed classes, NO namespaces, `function_exists()`-guarded global helpers, `defined('ABSPATH') || exit` header — match `Router.php`/`Response.php`/`Endpoints.php` exactly.
- Soft caps: ~400 lines/class, ~30 lines/method (per `ntdst-architecture`).
- **Backward compatibility is a first-class requirement.** No breaking change to any existing public method signature or wire shape. Every existing `Endpoints` response byte-shape, every `Router::when()/template()/register()` return-contract, and the `Endpoints`→`Response` class alias are pinned by characterization tests BEFORE any refactor task runs.
- Errors are `WP_Error`, never `false`/`null` (INV-4). Logging via `ntdst_log('api')`, never `error_log()`.
- Test runner: `ddev exec vendor/bin/phpunit --testsuite Unit` (unit) and `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist` (integration). Static analysis: `ddev exec composer lint:stan`.
- Branch isolation: work happens in a **git worktree** cut from `main` (origin/HEAD → origin/main, verified 2026-07-03). Stride's checkout is on `feat/admin-url-filter-state` with in-flight untracked files — it must NOT be disturbed.

---

## Gates fired (and not fired) — controller record

| Gate | Fired? | One-line trigger reason |
|---|---|---|
| 1a threat-modeling | **YES** | CORS + public REST dispatch + untrusted JSON parsing + origin allow-list = four 1a surfaces at once. Section below. |
| 1b architecture-invariants | **YES** | Reshape CREATES a new convergence point ("where REST routes register / where the CORS decision is made") and touches INV-2 (Endpoints nonce edge) + INV-5 (Response rendering). Doc-update is Task 5.1. |
| 1c ground-truth | **YES** | Handoff was verified against the *todai-client* copy; drift confirmed (see Ground-truth findings). All premises re-verified against Stride `main` 2026-07-03. |
| 1d test tiers | **YES** | Per-task `Tier:` lines below, per `testing-workflow`. |
| 1f/1h review sizing + tiers | **YES** | 7 clusters, ≤4 tasks each, `── REVIEW GATE ──` markers with provisional tiers inline. |
| 1g feature-acceptance | **YES** | The "user" is developer code + a browser doing cross-origin calls. `## Acceptance flows` matrix below. |
| WP stack override `wp-plan-requirements` | **YES** | Framework classes + user-facing data flows. Blocks 0–3 below. |
| `superpowers:brainstorming` / `refining-ideas` | NO | Scope decisions locked by Stefan; design ran through `ntdst-architecture` + `designing-apis` instead (WP override rule). |
| `sourcing-from-docs` / context7 | NO | Every external-behavior premise (the three WP-core quirks) was ground-truthed against WP core *source* during the todai-client build and re-cited from the reference implementation's verified docblocks — no unverified library claim remains. |
| `doubting-decisions` | **YES (run at plan time)** | Record below under "Architectural decisions". |

---

## Ground-truth findings (Stage 1c — verified against Stride `main`, 2026-07-03)

`git diff main HEAD -- web/app/mu-plugins/ntdst-core/` is empty and the working tree is clean for ntdst-core, so these hold for the plan's base branch.

**Handoff claims that HELD:**

1. **Router latent bug — CONFIRMED.** `core/Router.php::handleTemplateInclude()` (lines 282–298) handles only `string`/`null`/`true`/`false` returns. No `instanceof NTDST_Response` branch — while `template()` (:157) and `when()` (:239) both have one. A pattern-route callback returning a Response object (without calling an exiting output method itself) is silently ignored and the default template serves.
2. **Endpoints hardcoded namespace — CONFIRMED.** `private const REST_NAMESPACE = 'ntdst/v1'` (:34), exactly two fixed routes, no extension mechanism.
3. **Endpoints private envelope — CONFIRMED.** `success()`/`error()` at :465–482; never touches `NTDST_Response`. Note the two wire shapes differ: Endpoints error = `{success:false, data:{message,code}}`; `Response::json()` error = `{success:false, error:<message>}`. **They are two pinned contracts — "shared envelope" means one OWNER (`NTDST_Response`), not one shape.**
4. **No CORS response headers anywhere — CONFIRMED.** Zero `Access-Control-*` emission across all three files.
5. **`Response` is the reusable piece — CONFIRMED**, with one constraint the handoff under-weighted: `json()`, `render()`, `redirect()`, `download()` are all `never`-returning (they `exit`). The REST path needs a **non-exiting** representation — hence `toRestResponse()`.

**Handoff claims that did NOT hold (drift found):**

6. **"grep for Origin returns zero hits in Endpoints.php" — FALSE for Stride.** Stride's `Endpoints` has a full `verifyOrigin()` CSRF gate (same-origin Origin/Referer check, `hasCookieAuth()` fallback, `ntdst/api/allowed_origins` filter) plus per-action filterable rate limits (`ntdst/api/rate_limit/{action}`). The todai-client copy the handoff grepped is an **older** revision. Consequence A: Stride's Endpoints is even more clearly the "same-origin dispatcher" — the re-scope decision (D3) is reinforced. Consequence B: the propagation entry (Task 6.1) must note that **Endpoints itself has un-propagated hardening** independent of this reshape.
7. **Endpoints docblock says `ALLOW_RESTAPI_AJAX` must be defined — STALE.** `ntdst-coreloader.php:36` calls `ntdst_endpoints()` unconditionally. Fix the docblock in Task 5.2 (doc-only).
8. **Stride's default `public_actions`** is `['get_recent_posts','search_posts','send_magic_link']` — `search_users` is NOT in the default list (it's cap-gated inside its handler). CONVENTIONS.md lists it as public — stale; corrected in Task 5.3.

**Existing test baseline (must not regress):** `tests/Unit/NtdstRouterTest.php` (pattern compile, URL, redirect-prevention), `tests/Unit/NtdstEndpointsTest.php` (verifyOrigin, cookie-auth, client IP, rate limits, class alias), `tests/Unit/ResponseTest.php` + `NtdstResponseHardeningTest.php` (MIME, file headers, traversal), `tests/Integration/ApiEndpointIntegrationTest.php`, `NtdstCoreHardeningTest.php`. Nothing covers `handleTemplateInclude()`'s Response handling — the Task 1.3 RED test is genuinely new.

---

## Architectural decisions (locked at plan time; doubt-pass record inline)

All four decisions below were attacked via `netdust-agent:doubting-decisions` (fresh-context skeptic) before task breakdown. **All held; three corrections were folded in** (marked ✦).

| # | Decision | Why | Doubt-pass outcome |
|---|---|---|---|
| D1 | REST registration is exposed as `ntdst_router()->rest(string $namespace): NTDST_Rest_Registrar` — a thin facade on `NTDST_Router` delegating to a new final class in `api/RestRegistrar.php`. | Honors Stefan's locked "real REST registration on Router" scope (one discoverable routing entry point) while keeping Router under the size cap and the registrar independently testable. | **Held ✦** — correction: a consumer calling `->rest()` *after* `rest_api_init` has fired would silently register nothing. The registrar registers queued routes on `rest_api_init` AND registers immediately when `did_action('rest_api_init')` is already truthy. |
| D2 | `NTDST_Response` gains **static** `apiSuccess()`/`apiError()` envelope builders + instance `toRestResponse()`. `json()`'s legacy wire shape is untouched. `Endpoints::success()/error()` delegate to the static builders. | "Response becomes the shared output envelope" = one class OWNS envelope construction (a convergence point), while the two existing wire shapes stay byte-identical (Hyrum's law — both have consumers). | **Held ✦** — correction: builders are **static** because `NTDST_Response`'s constructor resolves template paths (WP function calls + test-stub burden Endpoints must not inherit). |
| D3 | `Endpoints` is **re-scoped + documented**, NOT folded into the registrar. Only its two private envelope builders change (delegation), pinned by characterization tests. | Endpoints is INV-2's convergence point with 14+ consumer files in Stride and a back-compat class alias; folding = high-risk churn on an auth surface for zero behavior gain. The handoff explicitly allowed this outcome. | **Held.** |
| D4 | CORS is a **final class** `NTDST_Cors_Policy` (Stefan's "trait or similar" — a class is more testable and carries config-as-data). Quirk ownership is split: **quirk 1** (core's reflection+credentials override) lives in `NTDST_Cors_Policy`; **quirks 2+3** (permission double-invocation memoization; pre-dispatch body/depth limits) live in `NTDST_Rest_Registrar` — they are dispatch concerns, not CORS concerns. All three are handled once, centrally, per the handoff's intent. | Instance-configurable per route; the todai-client reference implementation is instance-method-shaped and ports cleanly. | **Held ✦** — corrections: `Origin: null` is never allow-listable; `'*'` is rejected at construction with `InvalidArgumentException`. |
| D5 | New registrar route params use **WP-native REST syntax** (`(?P<id>\d+)`), not Router's `:param` syntax. No translation layer. | YAGNI; keeps the registrar a thin, predictable wrapper over `register_rest_route`. Documented in the contract. | Not separately doubted (follows from D1's "thin wrapper" premise). |
| D6 | Handler return contract: `WP_REST_Response` → pass through; `WP_Error` → pass through (WP-native error serialization `{code,message,data:{status}}`); `array` → `NTDST_Response::apiSuccess()` envelope, status 200; `NTDST_Response` → `toRestResponse()` (envelope + stored status, **no exit**). | One structured error shape (`designing-apis`): WP-native `WP_Error` serialization — consistent with the WP ecosystem, with Stride's Partner API, and with todai-client's intake route. | Not separately doubted. |

---

## Golden path: none (no matching archetype — framework-internal infrastructure)

- [x] This reshape matches no consumer archetype in `ntdst-patterns/golden-paths/` — it *builds the spine* that a future "cross-origin REST endpoint" golden path will document. Closest relative is `form-data-flow.md`'s REST half; its named deviation on todai-client (`feat/form-intake-api` Deviation 1) is precisely the gap this reshape closes.
- [x] Deviations: n/a (no slice to deviate from). Task 5.3 records the new spine in CONVENTIONS.md so the next feature HAS a golden path to build to.

---

## Threat model

> Written 2026-07-03 at plan time for the ntdst-core output-layer reshape: a new REST registration path + CORS/origin-allow-list helper that every ntdst-core site will use for public, cross-origin JSON endpoints, plus a behavior-preserving refactor of the existing same-origin nonce edge. This surface is security-rich (CORS decisions, public dispatch, untrusted JSON, an auth-adjacent refactor); without this section, `/code-review` rounds would re-discover the attack surface independently. **This section is the convergence target — reviews verify against the numbered mitigations.** The three WP-core interaction quirks referenced throughout were ground-truthed against WP core source during the todai-client `feat/form-intake-api` build (see `~/Sites/todai-client-form-intake/.../SubmissionIntakeService.php` docblocks for the line-level traces).

### What we're defending

1. **The authenticated WP session of every logged-in user on every ntdst-core site** — a CORS misconfiguration that advertises `Access-Control-Allow-Credentials: true` with a reflected origin lets a malicious page read authenticated REST responses cross-origin.
2. **The origin-allow-list decision itself** — the `NTDST_Cors_Policy` configuration (exact origins per route). Once this class exists, it IS where the CORS decision is made framework-wide (proposed INV-10); a weakening bug here weakens every consuming site at once.
3. **Server resources of consuming sites** — PHP memory/CPU during `json_decode` of attacker-supplied bodies; WP core decodes at default depth 512 *before* any permission callback runs.
4. **Integrity of side-effectful permission callbacks** — rate-limit counters and audit writes inside `permission_callback`s, which WP core invokes twice per request.
5. **The existing `ntdst/v1` same-origin surface** — `Endpoints`' nonce gate (`handle_action`, INV-2), `verifyOrigin()` CSRF check, and rate limiting, which the Phase-1 refactor touches (envelope delegation only) and must not weaken.
6. **Data behind future registrar-registered routes on consuming projects** — the registrar's defaults determine whether the next developer's endpoint is safe by default or unsafe by default.

### Who we're defending against

1. **External anonymous attacker** (no account) probing new REST routes and CORS behavior — **IN scope.**
2. **A malicious web page driving a victim's authenticated browser** cross-origin (the CSRF/credentialed-CORS class) — **IN scope.**
3. **A compromised or malicious allowed-origin app** — **partially IN**: the origin gate stops other origins; what an allowed origin may do is bounded by the route's own permission callback + body limits. Full compromise of an allow-listed app is accepted residual risk (same posture as todai-client's threat model).
4. **The careless framework consumer** (a developer registering a route with no permission check or a wildcard origin) — **IN scope**: defended by API design (required permission, `'*'` rejected at construction), not by review vigilance.
5. **Insider / stolen server credentials** — **OUT of scope** (server compromise defeats any application-layer control).

### Attacks to defend against

1. **Origin-reflection + credentials anti-pattern (WP-core quirk 1).** WP core's `rest_send_cors_headers()` (hooked `rest_pre_serve_request` prio 10 via `rest_api_default_filters()`) unconditionally reflects any non-empty `Origin` header AND sets `Access-Control-Allow-Credentials: true`. Any route relying on core defaults ships the reflection+credentials anti-pattern; a naive custom filter at priority <10 gets overwritten by core.
2. **Preflight bypass / broken preflight.** A browser's `OPTIONS` preflight is answered by WP core's `rest_handle_options_request` with no CORS headers of its own; a CORS implementation that only decorates the POST path leaves preflight unanswered (breaking legitimate callers) or answered by core's reflect-anything default (attack 1 on the preflight).
3. **`permission_callback` double-invocation side effects (WP-core quirk 2).** `rest_send_allow_header()` (hooked `rest_post_dispatch`) calls the matched route's `permission_callback` a SECOND time per request to compute the `Allow` header. A side-effectful callback (rate-limit increment) double-counts — halving effective limits, or worse for non-idempotent checks.
4. **Unbounded JSON parse depth/size pre-dispatch (WP-core quirk 3).** `WP_REST_Server::dispatch()` calls `$request->has_valid_params()` → `parse_json_params()` → `json_decode($body, true)` at PHP default depth 512 BEFORE `permission_callback` runs. Only `rest_pre_dispatch` (the first thing `dispatch()` does) runs earlier. A depth-bomb or oversized body burns resources before any application check.
5. **Nonce-mechanism misuse for cookie-less cross-origin callers.** An anonymous WP nonce is a shared, non-origin-bound, non-caller-bound token (`wp_get_session_token()` returns `''` for cookie-less requests, collapsing the hash for all anonymous callers) — verified against WP core during the todai-client doubt pass. A developer reaching for `Endpoints`' nonce flow for a cross-origin caller gets zero real security while believing they have some.
6. **Missing or `__return_true` permission callback on registrar routes.** The INV-1 failure mode, now at framework level: if the registrar defaults to permissive, every consuming site inherits the default.
7. **CORS scope leak.** A CORS filter registered globally (not route-scoped) applies one route's allow-list — or the credentials-strip — to unrelated routes, including core's own `wp/v2` routes.
8. **Origin-matching weakening.** Substring/prefix matching (`str_starts_with('https://evil-example.com', ...)` class), scheme-less comparison, case games, or accepting the literal `Origin: null` (sandboxed iframes, some redirects) — each turns the exact-match gate into a bypass.
9. **Refactor regression on the existing same-origin edge.** The Phase-1 envelope delegation could change `Endpoints`' wire bytes (breaking `ntdstAPI` JS clients) or a careless edit could weaken `verifyOrigin()`/nonce/rate-limit behavior.
10. **Error-detail leakage through the new envelope path.** A handler `WP_Error` carrying internal detail (`$wpdb->last_error` class of message) serialized verbatim to an anonymous caller.

### Mitigations required

1. **`NTDST_Cors_Policy` registers on `rest_pre_serve_request` at priority 20** (after core's 10), route-prefix-scoped, and **always removes `Access-Control-Allow-Credentials`** via an injectable header-remover seam before deciding anything else. On origin match: `Access-Control-Allow-Origin: <exact request origin>` + `Vary: Origin` + configured methods/headers. On non-match (or absent Origin): remove `Access-Control-Allow-Origin` entirely so core's reflection never survives. Never `*`. (Direct port of the verified `SubmissionIntakeService::applyCorsHeaders()` pattern.) — *Tasks 2.1/2.2.*
2. **Preflight is covered by the same filter**: `rest_pre_serve_request` fires for OPTIONS too, so the policy's headers (incl. `Access-Control-Allow-Methods`/`-Headers`, optional `Access-Control-Max-Age`) apply to the preflight response; the acceptance matrix drives a real OPTIONS. — *Task 2.2, Flow 2.*
3. **The registrar wraps every supplied permission callable in a `\WeakMap`-keyed per-request memoizer** (`spl_object_id` explicitly rejected — id reuse after GC; see reference implementation docblock) so a side-effectful callback runs its evaluation exactly once per real request. — *Task 3.2.*
4. **Registrar options `max_body_bytes` / `max_json_depth` enforce via a `rest_pre_dispatch` filter (priority 5), route-scoped**: `strlen(raw body) > cap` → `WP_Error('payload_too_large', …, ['status'=>413])`; bounded `json_decode($raw, true, $depth)` + `json_last_error()` → `WP_Error('invalid_json', …, ['status'=>400])`. Returning the WP_Error short-circuits `dispatch()` before core's default-depth parse. — *Task 3.3.*
5. **`Endpoints`' docblock and CONVENTIONS.md name it "same-origin, nonce-gated action dispatcher — NOT for cross-origin callers"** with one sentence on WHY (the shared-anonymous-nonce fact, attack 5), and point cross-origin work at `ntdst_router()->rest()` + `NTDST_Cors_Policy`. Documentation-as-control, mirroring todai-client's mitigation 6. — *Tasks 5.2/5.3.*
6. **`permission` is a REQUIRED registrar option with no default.** A route registered without a callable `permission` is NOT registered; the registrar logs `ntdst_log('api')->error(...)` + `_doing_it_wrong()`. A public-by-design route must supply its own explicit callable (pattern documented in the class docblock: origin/key checks à la todai-client). No `'__return_true'` anywhere in framework code. — *Task 3.1.*
7. **Route-prefix scoping is structural**: both the CORS filter and the body-limit filter first check `str_starts_with($request->get_route(), '/' . $namespace . $route)` and pass through unchanged otherwise. A unit test asserts a foreign route's headers/result are untouched. — *Tasks 2.2/3.3.*
8. **Origin matching is exact string identity**: `in_array($origin, $origins, true)` against full `scheme://host[:port]` strings (or a caller-supplied resolver callable for dynamic lists à la `FormKeyRegistry`); the literal string `'null'` and empty string never match regardless of configuration; `'*'` throws `InvalidArgumentException` at construction. Denial paths are Tier-A tested. — *Task 2.1.*
9. **Phase-0 characterization tests pin the exact wire arrays** of `Endpoints::success()/error()` (via `handle_get_nonce`/`handle_action` outputs) and the `Router::when()/template()/register()` return contracts BEFORE any refactor task runs; Phase 1 must keep them green untouched. `verifyOrigin`/rate-limit/nonce logic is not edited at all in this plan (delegation touches only the two private envelope builders). — *Tasks 0.2/0.3, 1.2.*
10. **The registrar's `WP_Error` pass-through is documented as "message goes to the wire"**: the class docblock instructs handlers to log detail via `ntdst_log()` and return generic client-safe messages (the todai-client Finding-2 lesson). The demo/acceptance route demonstrates the pattern. — *Task 3.4 docblock, Flow 1 edge.*

### Out of scope (explicit deferrals)

1. **Rate limiting inside the registrar** — stays a consumer concern (the memoization mitigation makes consumer rate-limit callbacks safe). `Endpoints`' per-action limiter and todai-client's `(ip, form_key)` limiter are the documented patterns. Deferred: a shared `NTDST_Rate_Limiter` extraction is a possible follow-up, not this pass.
2. **DNS rebinding** — N/A by construction: the allow-list is a string comparison against the `Origin` header; this layer makes no outbound fetch to user-supplied URLs.
3. **WAF/CDN-layer protection** — infra concern, per-site (`site.yml`), not framework.
4. **Canonical-repo migration for ntdst-core** — considered and rejected by Stefan for this pass; manual documented propagation per the GLOBAL.md precedent.
5. **`NTDST_Data_Model` naming/scope issue (`api/Data.php`)** — named follow-up only (see Out-of-scope log at the end); nothing in this plan touches it.
6. **Refactoring todai-client's `SubmissionIntakeService` onto the new registrar** — documented in the propagation entry as a candidate consumer; execution is a separate decision for Stefan.
7. **Auth schemes for cross-origin callers beyond origin allow-listing** (API keys, HMAC signatures, OAuth) — consumer-level; the registrar's required-permission hook is where they plug in.

### How to use this section

- **Controller pre-flight:** verify each task's supplied code embeds its named mitigations before dispatching (mitigation ↔ task mapping is inline above).
- **`/code-review` invocations (all FULL-tier clusters):** "Verify the diff against the Threat model in `docs/plans/2026-07-03-ntdst-core-output-layer-reshape.md`. Check each of the 10 numbered mitigations: in place / missing / deferred per the deferrals list. Do not re-raise deferrals 1–7 as new findings."
- **`/evaluate` / shake-out retros:** an unimplemented mitigation is a plan-correction defect, not a nice-to-have.
- **Downstream phases and consuming projects:** cross-reference this model; extend it (don't fork it) if the surface grows.

---

## WP security requirements (per data-flow)

Pillars per `netdust-wp:wp-security` (validate / sanitize / escape / authorize). One line per flow:

- [ ] **New registrar dispatch path (framework):** authorize = REQUIRED `permission` callable (mitigation 6) wrapped in the memoizer; validate = optional `args` schema passed through to `register_rest_route` + body/depth caps (mitigation 4); sanitize = consumer-handler responsibility, named in the registrar docblock; escape = n/a — JSON output via `WP_REST_Response`/envelope, no HTML sink.
- [ ] **CORS header emission (`NTDST_Cors_Policy`):** validate = exact-origin identity match incl. `null`-origin denial (mitigation 8); authorize = the origin decision itself; sanitize = the emitted `Access-Control-Allow-Origin` value is the request's own origin string only after exact match against a server-side list (never attacker-influenced beyond selection); escape = n/a — headers, no HTML.
- [ ] **`rest_pre_dispatch` body limits:** validate = size + bounded-depth decode before core's parse (mitigation 4); authorize/sanitize/escape = n/a — pre-auth resource guard, uniform generic error messages (no echo of body content).
- [ ] **`Endpoints` envelope delegation (existing flow, preserved):** all four pillars remain exactly where they are today (nonce at :343, `verifyOrigin`, per-action rate limit, handler-side sanitize) — this plan's only change is the private array builders; characterization tests are the proof (mitigation 9).
- [ ] **Demo/acceptance route (test fixture, NOT shipped):** authorize = explicit origin-check permission callable; validate = `args` schema + body caps; sanitize = `sanitize_key`/`sanitize_text_field` on params before use; escape = n/a (JSON). Fixture lives under `tests/`, never in a shipped mu-plugin.

## ntdst-core layering requirements

(Only the applicable rows — this is framework-internal code, no data layer involved.)

- [ ] No data access anywhere in the diff (no `ntdst_data()`, no `$wpdb`) — this reshape is pure dispatch/output. Any appearance of either is a finding.
- [ ] Output through `NTDST_Response`/`WP_REST_Response` — no raw `echo` in any new class (header emission via the injectable seams is the one non-Response output, matching the proven reference pattern).
- [ ] No raw `wp_ajax_*` — the whole point of the diff.
- [ ] No swallowed `WP_Error` — registrar passes handler `WP_Error`s through to WP's serializer; internal failures log via `ntdst_log('api')` and return `WP_Error`.
- [ ] `declare(strict_types=1)`, `NTDST_` prefix, ABSPATH guard, `function_exists()` helper guards — house style of the three existing files.
- [ ] New classes are NOT services (no `NTDST_Service_Meta`): `NTDST_Rest_Registrar` and `NTDST_Cors_Policy` are framework infrastructure loaded by `ntdst-coreloader.php` require lines, consistent with `Router`/`Response`/`Endpoints`.
- [ ] Framework-internal filters use the `ntdst/*` prefix (none planned; if one becomes necessary, `ntdst/api/*`).

**Per-task acceptance line (applies to every code task below):**
> Acceptance: drift pre-check clean — `/drift-reviewer web/app/mu-plugins/ntdst-core` returns no findings on the touched files, and the applicable per-flow security line above is satisfied in the diff.

**Convergence contract:** These blocks + the Threat model + INV-10 are the convergence target for `/code-review` and the `ntdst-drift-reviewer` at every review gate and at shake-out. Reviewers verify the diff against the named items — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Architecture invariants touched

- **INV-2 (Frontend AJAX nonce, `Endpoints.php:333/:343`)** — touched by Phase 1's envelope delegation. The convergence point itself (nonce verify, `verifyOrigin`, rate limit) is NOT edited; line numbers may shift — Task 5.1 reconciles the doc's line references.
- **INV-5 (Rendering via `ntdst_response()`)** — touched by Phase 1's Response additions. The additions are additive-only (new static builders + one new instance method); `render()/html()/json()` behavior unchanged.
- **NEW: INV-10 (proposed, authored in Task 5.1)** — *"REST route registration and the CORS decision are made in one place."* Convergence points: `NTDST_Rest_Registrar` (via `ntdst_router()->rest()`) for new REST route registration; `NTDST_Cors_Policy` for any `Access-Control-*` emission. Bypass signals: a new raw `register_rest_route()` call outside the registrar; any hand-rolled `Access-Control-*` header or `rest_pre_serve_request` CORS hook outside `CorsPolicy`; a route registered with no/inline-permissive permission callback. **Known accepted baseline (pre-existing, do not re-flag):** `Modules/PartnerAPI/PartnerAPIController`, `Admin/AdminAPIController`, `Modules/Assistant/Read|WriteAbilityRegistrar`, and `ntdst-core/api/Endpoints.php` itself — all predate the registrar; migration is optional future work, not debt created by this plan. Audit move: `grep -rn "register_rest_route\|Access-Control-\|rest_pre_serve_request" --include="*.php" web/app/mu-plugins web/app/themes | grep -vE "RestRegistrar|CorsPolicy|Endpoints\.php|PartnerAPIController|AdminAPIController|AbilityRegistrar"`.

---

## Acceptance flows (1g — verified at shake-out, driven through the faithful layer)

The "user" is (a) developer code registering routes, (b) a browser making cross-origin calls, (c) existing clients of the untouched surfaces. UI-less feature: the faithful layer is **real HTTP against `https://stride.ddev.site`** (curl with explicit `Origin:` headers for header assertions — faithful, since CORS enforcement is header-level) plus **one real-browser cross-origin `fetch()` pass** (superpowers-chrome from a page on a different origin) at shake-out for Flow 1's happy path, because only a browser executes the preflight state machine for real.

| # | Flow (intended use) | Drive via | Edges (all six classes per row, or named exclusion) |
|---|---|---|---|
| 1 | Dev registers `POST` route via `ntdst_router()->rest('ntdst-test/v1')` with CorsPolicy + body caps; allowed-origin cross-origin POST succeeds (JSON envelope, 200/2xx) | Real browser fetch (shake-out) + curl w/ `Origin` | **empty:** empty body → handler-defined validation error, not a 500; **denied:** disallowed origin → no CORS headers on response (browser-blocked) AND `Origin: null` → denied; **wrong-order:** POST without preflight (curl) still enforces server-side permission; **concurrent/double:** two POSTs with the same request → rate-limit-style side effect in the fixture permission callback increments ONCE per request (mitigation 3 assert); **boundary:** body exactly at `max_body_bytes` passes, +1 byte → 413; JSON at depth cap passes, +1 → 400; **mid-flow failure:** handler returns `WP_Error` → WP-native error JSON, generic message, detail only in `ntdst_log`. |
| 2 | Browser preflight: `OPTIONS` with `Origin` + `Access-Control-Request-Method: POST` | curl + real browser (shake-out) | **denied:** disallowed origin → no `Access-Control-*` headers at all; **empty:** no `Origin` header → no CORS headers; **boundary:** `Access-Control-Allow-Credentials` header ABSENT for every origin (mitigation 1 — the load-bearing assert); wrong-order/concurrent/mid-flow: n/a — stateless preflight, excluded by class. |
| 3 | Backward compat: existing `Endpoints` flow — `POST /wp-json/ntdst/v1/get_nonce` then `/action` for a public action behaves byte-identically | curl + integration suite | **denied:** invalid nonce → same `{success:false,data:{message,code}}` shape as today; anon caller on non-public action → denied; **empty:** missing action → `missing_action`/`missing_params`; **boundary:** rate limit still triggers at the same cap; **wrong-order:** `/action` without prior `get_nonce` → invalid-nonce path; **concurrent/mid-flow:** covered by existing integration suite (`ApiEndpointIntegrationTest`) staying green unmodified. |
| 4 | Backward compat: `Router::when()`/`template()`/pattern routes render unchanged (EnrollmentRouter, stridence enrollment/completion pages) | Integration suite + one manual page load | **empty:** unmatched URL falls through to WP template; **denied:** n/a — no auth at this layer (excluded: authorization lives in page callbacks); **wrong-order:** POST-registered route not matched by GET; **boundary:** trailing-slash variant still matches; **concurrent:** n/a stateless; **mid-flow:** callback returning `false` falls through to next route (existing contract preserved). |
| 5 | Latent-bug fix: pattern-route callback returning `ntdst_response()->template('x')` now renders it | Unit (RED-first) + integration | **empty:** Response with NO template set → `exit` with no render — mirrors `when()`'s existing contract exactly (documented); **boundary:** string-path return still wins file-exists check; **denied/wrong-order/concurrent/mid-flow:** n/a — single-dispatch template concern, excluded by class. |
| 6 | Dev misuse is safe-by-default: route registered without `permission` | Unit + integration | **denied (the flow IS the denial):** route absent from `/wp-json` index, `_doing_it_wrong` fired, `ntdst_log('api')` error recorded; other edge classes n/a — the flow is a single guarded registration path. |

**Manifest requirement at shake-out:** each row emits `pass`/`fail`/`not-reachable`; Flow 1 happy path is not `pass` without the real-browser drive.

---

## API contract (contract-first, before any implementation task)

```php
// ── Entry point (facade on NTDST_Router) ─────────────────────────────
ntdst_router()->rest('myproject/v1')                       // NTDST_Rest_Registrar, cached per namespace
    ->post('/submissions', $handler, [
        'permission'     => $permissionCallable,           // REQUIRED. callable(WP_REST_Request): bool|WP_Error
        'args'           => [ /* register_rest_route args schema, passed through */ ],
        'cors'           => new NTDST_Cors_Policy([
            'origins' => ['https://app.example.com'],      // exact scheme://host[:port] strings — or callable(string $origin, WP_REST_Request): bool
            'methods' => ['POST', 'OPTIONS'],              // default: route methods + OPTIONS
            'headers' => ['Content-Type'],                 // default
            'max_age' => 600,                              // optional; omitted header when null
        ]),
        'max_body_bytes' => 262144,                        // optional; null = no cap
        'max_json_depth' => 20,                            // optional; null = no bound (core default 512 applies)
    ]);
// also: ->get() ->put() ->patch() ->delete() ->route($route, $methods, $handler, $options)
// Route syntax: WP-native REST regex ('(?P<id>\d+)') — NOT Router's ':param' (D5).

// ── Handler return contract (D6) ─────────────────────────────────────
// WP_REST_Response → pass-through | WP_Error → WP-native error JSON (message reaches the wire — log detail, return generic)
// array → ['success'=>true,'data'=>$array] (200) | NTDST_Response → $r->toRestResponse() (envelope + stored status, NO exit)

// ── NTDST_Response additions (additive-only) ─────────────────────────
NTDST_Response::apiSuccess(array $data): array;            // ['success'=>true,'data'=>$data]                          — Endpoints' success shape
NTDST_Response::apiError(string $message, string $code = 'error'): array;
                                                           // ['success'=>false,'data'=>['message'=>…,'code'=>…]]      — Endpoints' error shape
$response->toRestResponse(): WP_REST_Response;             // json()'s payload shape ({success,data}|{success,error}) + $this->status, without exit
```

**Named convergence points (feeds INV-10):** route registration → `NTDST_Rest_Registrar`; the CORS decision → `NTDST_Cors_Policy`; the API envelope → `NTDST_Response`; errors → `WP_Error` end-to-end (INV-4). Additive-only evolution: no existing signature changes; all new surface is new methods/classes.

---

## Phase 0 — Isolation + characterization baseline

### Task 0.1: Worktree + green baseline

**Files:** none (git + verification only)

- [ ] **Step 1:** From `/home/ntdst/Sites/stride` (do NOT touch the `feat/admin-url-filter-state` checkout):
```bash
git -C /home/ntdst/Sites/stride worktree add /home/ntdst/Sites/stride-output-reshape -b feat/ntdst-output-layer main
```
- [ ] **Step 2:** Verify DDEV serves the main checkout — the worktree is for editing/committing; tests run via the existing DDEV project mount. Confirm with Stefan's standing setup: if DDEV mounts only `~/Sites/stride`, run suites there AFTER checking the branch out is not required — instead run PHPUnit directly in the worktree (`composer install` if `vendor/` absent):
```bash
cd /home/ntdst/Sites/stride-output-reshape && composer install --no-interaction
```
  (If integration tests need the DDEV DB, execute integration runs from the DDEV project with the worktree path mounted, or defer integration runs to phase gates run against the worktree via `ddev exec -d /var/www/html …`. Record which mode was used in the task-close note.)
- [ ] **Step 3:** Baseline: unit suite green (expect 706), integration green (expect 261), stan clean:
```bash
vendor/bin/phpunit --testsuite Unit && composer lint:stan
```
- [ ] **Step 4:** Commit nothing (baseline only). Record counts in `tasks/todo.md`.

**Tier:** B — `no unit test: Tier B, environment/branch setup, no behavior`.

### Task 0.2: Characterization tests — Endpoints wire shape

**Files:**
- Test: `tests/Unit/NtdstEndpointsEnvelopeCharacterizationTest.php` (create)

**Interfaces:** Produces the pinned contracts Task 1.2 must keep green: `handle_get_nonce()` success = `['success'=>true,'data'=>['nonce'=>…]]`; error = `['success'=>false,'data'=>['message'=>…,'code'=>…]]`.

- [ ] **Step 1:** Write tests pinning the EXACT array shapes (reflection to reach outputs through the public handlers, as the existing `NtdstEndpointsTest` does with `$_SERVER` fixtures):

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NtdstEndpointsEnvelopeCharacterizationTest extends TestCase
{
    private NTDST_Endpoints $endpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoints = ntdst_endpoints();
    }

    public function testGetNonceSuccessWireShapeIsPinned(): void
    {
        $request = new WP_REST_Request();
        $request->set_param('action', 'search_posts');

        $result = $this->endpoints->handle_get_nonce($request);

        self::assertSame(['success', 'data'], array_keys($result));
        self::assertTrue($result['success']);
        self::assertSame(['nonce'], array_keys($result['data']));
        self::assertIsString($result['data']['nonce']);
    }

    public function testMissingActionErrorWireShapeIsPinned(): void
    {
        $result = $this->endpoints->handle_get_nonce(new WP_REST_Request());

        self::assertSame(
            ['success' => false, 'data' => ['message' => 'No action specified', 'code' => 'missing_action']],
            $result,
        );
    }

    public function testHandleActionMissingParamsErrorWireShapeIsPinned(): void
    {
        $result = $this->endpoints->handle_action(new WP_REST_Request());

        self::assertSame(
            ['success' => false, 'data' => ['message' => 'Missing action or nonce', 'code' => 'missing_params']],
            $result,
        );
    }
}
```
  (Adapt `WP_REST_Request` construction to the existing stub conventions in `tests/Stubs/wordpress-stubs.php` — follow `NtdstEndpointsTest.php`'s fixtures exactly; extend stubs only if `handle_get_nonce` needs one not present.)
- [ ] **Step 2:** Run: `vendor/bin/phpunit --filter NtdstEndpointsEnvelopeCharacterization --testsuite Unit` — expect PASS (characterization: pins CURRENT behavior; the RED it provides is against any future shape mutation in Task 1.2).
- [ ] **Step 3:** Full unit suite green. Commit: `test(ntdst-core): pin Endpoints envelope wire shapes before reshape`.

**Tier:** A (characterization variant — the contract asserted is the acceptance criterion "wire bytes never change"; its RED is any Phase-1 mutation, verified by mutating locally once and watching it fail, then reverting).

### Task 0.3: Characterization tests — Router return contracts

**Files:**
- Test: `tests/Unit/NtdstRouterDispatchCharacterizationTest.php` (create)

**Interfaces:** Pins `handleTemplateInclude()`'s current contract (string-path wins if file exists; `false` falls through; unmatched returns original template) so Task 1.3's new branch demonstrably changes ONLY the Response case.

- [ ] **Step 1:** Write tests using `$_SERVER['REQUEST_URI']`/`REQUEST_METHOD` fixtures (pattern in `NtdstRouterTest::testPreventRedirectReturnsFalseWhenRouteMatches`): (a) callback returning existing file path → that path returned; (b) callback returning `false` → falls through, original template returned; (c) no match → original template. Do NOT test the `null`/`true` exit paths in-process (they `exit`) — assert them at integration (Task 4.1) instead; note this as the deferral line.
- [ ] **Step 2:** Run + full suite green. Commit: `test(ntdst-core): pin Router dispatch return contract before reshape`.

**Tier:** A (characterization; deferral: `Risk this test does NOT cover: exit-path behavior — deferred to integration-gate Task 4.1`).

`── REVIEW GATE ── (tier: STANDARD — test-only cluster; pins 1a-adjacent surfaces but changes no behavior)`

---

## Phase 1 — Response envelope + Router latent-bug fix

### Task 1.1: `NTDST_Response` static envelope builders + `toRestResponse()`

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Response.php` (additive only)
- Test: `tests/Unit/NtdstResponseEnvelopeTest.php` (create)

**Interfaces:** Produces `NTDST_Response::apiSuccess(array): array`, `NTDST_Response::apiError(string, string='error'): array`, `$r->toRestResponse(): WP_REST_Response` — consumed by Tasks 1.2 (Endpoints delegation) and 3.4 (registrar normalization).

- [ ] **Step 1 (RED):** Write the failing tests:

```php
public function testApiSuccessBuildsEndpointsSuccessShape(): void
{
    self::assertSame(
        ['success' => true, 'data' => ['id' => 7]],
        NTDST_Response::apiSuccess(['id' => 7]),
    );
}

public function testApiErrorBuildsEndpointsErrorShape(): void
{
    self::assertSame(
        ['success' => false, 'data' => ['message' => 'Nope', 'code' => 'forbidden']],
        NTDST_Response::apiError('Nope', 'forbidden'),
    );
}

public function testApiErrorDefaultsCodeToError(): void
{
    self::assertSame('error', NTDST_Response::apiError('x')['data']['code']);
}

public function testToRestResponseCarriesJsonPayloadAndStatusWithoutExit(): void
{
    $rest = ntdst_response()->withData(['a' => 1])->toRestResponse();
    self::assertInstanceOf(WP_REST_Response::class, $rest);
    self::assertSame(200, $rest->get_status());
    self::assertSame(['success' => true, 'data' => ['a' => 1]], $rest->get_data());
}

public function testToRestResponseErrorPathMirrorsJsonShape(): void
{
    $rest = ntdst_response()->error('Bad input', 422)->toRestResponse();
    self::assertSame(422, $rest->get_status());
    self::assertSame(['success' => false, 'error' => 'Bad input'], $rest->get_data());
}
```
- [ ] **Step 2:** Run — expect FAIL (methods undefined). Add a `WP_REST_Response` stub to `tests/Stubs/wordpress-stubs.php` if absent (constructor `($data, $status)`, `get_data()`, `get_status()`).
- [ ] **Step 3 (GREEN):** Implement in `Response.php` — extract `json()`'s payload into a private `jsonPayload(): array` used by BOTH `json()` (unchanged bytes) and `toRestResponse()`:

```php
/** API envelope builders — the ONE place the {success,…} wire shapes are decided (INV-10 companion). */
public static function apiSuccess(array $data): array
{
    return ['success' => true, 'data' => $data];
}

public static function apiError(string $message, string $code = 'error'): array
{
    return ['success' => false, 'data' => ['message' => $message, 'code' => $code]];
}

/** json()'s payload without the exit — for the REST dispatch path. */
public function toRestResponse(): WP_REST_Response
{
    return new WP_REST_Response($this->jsonPayload(), $this->status);
}

private function jsonPayload(): array
{
    return $this->error
        ? ['success' => false, 'error' => $this->error]
        : ['success' => true, 'data' => $this->data];
}
```
  and refactor `json()` to `$payload = $this->jsonPayload();` (its serialization-fallback + exit behavior untouched).
- [ ] **Step 4:** Suite green (incl. Phase-0 characterization + existing `ResponseTest`). `composer lint:stan` clean.
- [ ] **Step 5:** Commit: `feat(ntdst-core): Response owns the API envelope — static builders + non-exiting toRestResponse`.

**Tier:** A — envelope transform with a byte-exact contract. RED-first shown above.

### Task 1.2: `Endpoints` delegates its envelope to `Response`

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Endpoints.php:465-482` (the two private builders only)

- [ ] **Step 1:** Replace the bodies:

```php
private function success(array $data): array
{
    return NTDST_Response::apiSuccess($data);
}

private function error(string $message, string $code = 'error'): array
{
    return NTDST_Response::apiError($message, $code);
}
```
- [ ] **Step 2:** Run the Phase-0 characterization tests + full unit suite — expect PASS unchanged (this is the entire proof of the task).
- [ ] **Step 3:** Commit: `refactor(ntdst-core): Endpoints envelope delegates to NTDST_Response (behavior-preserving, pinned by characterization)`.

**Tier:** B — `no unit test: Tier B, behavior-preserving two-method delegation whose contract is already pinned RED-capable by Task 0.2's characterization tests`. (Threat-model mitigation 9: nothing else in Endpoints.php is edited.)

### Task 1.3: Fix `Router::handleTemplateInclude()` Response handling (the latent bug)

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/core/Router.php:282-298`
- Test: `tests/Unit/NtdstRouterDispatchCharacterizationTest.php` (extend)

- [ ] **Step 1 (RED):** Because the fix's happy path exits, unit-test the DECISION seam: extract the result-handling into a protected `resolveRouteResult(mixed $result, string $template): string|false|null` where `null` means "handled — caller exits", `false` means "try next route", string means "use as template". The RED test targets current behavior's gap:

```php
public function testResponseWithTemplateIsRecognizedAsHandled(): void
{
    $router = new class extends NTDST_Router {
        public array $rendered = [];
        protected function renderResponse(NTDST_Response $response): void
        {
            // test seam — production renders + exits; test records
            $this->rendered[] = $response->getTemplate();
        }
        public function expose(mixed $result, string $template): string|false|null
        {
            return $this->resolveRouteResult($result, $template);
        }
    };

    $response = ntdst_response()->template('project/single');
    $outcome  = $router->expose($response, '/theme/index.php');

    self::assertNull($outcome, 'A Response return must be treated as handled, not fall through');
    self::assertSame(['project/single'], $router->rendered);
}
```
  Run — FAIL (`resolveRouteResult` undefined / Response currently ignored).
- [ ] **Step 2 (GREEN):** Implement in `Router.php` — refactor `handleTemplateInclude()`'s result block to:

```php
$result = call_user_func($route['callback'], $params, $template);

$resolved = $this->resolveRouteResult($result, $template);
if ($resolved === null) {
    exit;
}
if ($resolved === false) {
    continue;
}
return $resolved;
```
  with:

```php
/**
 * Decide what a route callback's return value means.
 * string existing-file → template path | NTDST_Response → render+handled
 * null/true → handled | false → try next route | other → keep original.
 * Mirrors when()/template()'s Response contract exactly (incl. exit on a
 * Response with no template set — documented, deliberate parity).
 */
protected function resolveRouteResult(mixed $result, string $template): string|false|null
{
    if ($result instanceof NTDST_Response) {
        $this->renderResponse($result);
        return null;
    }

    if (is_string($result) && file_exists($result)) {
        return $result;
    }

    if ($result === null || $result === true) {
        return null;
    }

    if ($result === false) {
        return false;
    }

    return $template;
}

protected function renderResponse(NTDST_Response $response): void
{
    $template_name = $response->getTemplate();
    if ($template_name) {
        $response->render($template_name); // never returns
    }
}
```
  **Behavior-preservation note:** the old code's final-else was "ignored → outer loop keeps scanning, eventually returns `$template`"; `resolveRouteResult` returning `$template` for unrecognized types short-circuits remaining routes — verify against Task 0.3's pinned contract; if the pinned test shows multi-route scanning must continue after an unrecognized return, return `false`-with-original-template semantics instead: keep parity with the pinned test, which is the authority.
- [ ] **Step 3 — Sibling-site audit (mandatory step):** audit every in-repo caller of `register()/get()/post()` pattern routes for callbacks that return a `Response` object *without* calling an exiting output method (these silently fell through before; they now render/exit):
```bash
grep -rn "ntdst_route(\|ntdst_router()->get(\|ntdst_router()->post(\|ntdst_router()->register(" \
  web/app/mu-plugins/stride-core web/app/themes/stridence --include="*.php"
```
  Read each callback; record findings in the task-close note (expected: none rely on the buggy fall-through — `EnrollmentRouter` etc. return strings or call `render()` directly; if one DOES rely on it, STOP and escalate to Stefan before proceeding).
- [ ] **Step 4:** Run new tests + Phase-0 characterization + full suite → green ×3 runs (dispatch/order-sensitive). Commit: `fix(ntdst-core): Router pattern routes recognize NTDST_Response returns (latent bug — when()/template() parity)`.

**Tier:** A — RED-first mandatory (the named latent bug). Deferral: `Risk not covered: real exit-path through template_include — deferred to integration-gate Task 4.1`.

`── REVIEW GATE ── (tier: FULL — cluster touches INV-5's convergence point (Response) and Router dispatch behavior; Task 1.2 touches Endpoints.php, an INV-2/auth-adjacent file)`

---

## Phase 2 — `NTDST_Cors_Policy`

### Task 2.1: Origin matching (the security predicate)

**Files:**
- Create: `web/app/mu-plugins/ntdst-core/api/CorsPolicy.php`
- Test: `tests/Unit/NtdstCorsPolicyTest.php` (create)

**Interfaces:** Produces `new NTDST_Cors_Policy(array $config)`, `allowsOrigin(string $origin, WP_REST_Request $request): bool` — consumed by Task 2.2 and the registrar (Task 3.4).

- [ ] **Step 1 (RED):** Denial-path-heavy tests:

```php
public function testExactOriginMatches(): void
{
    $p = new NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);
    self::assertTrue($p->allowsOrigin('https://app.example.com', new WP_REST_Request()));
}

public function testSubdomainAndPrefixVariantsAreDenied(): void
{
    $p = new NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);
    foreach ([
        'https://app.example.com.evil.com',
        'https://evil-app.example.com',
        'http://app.example.com',          // scheme downgrade
        'https://app.example.com:8443',    // port variant
        'HTTPS://APP.EXAMPLE.COM',         // case games — exact identity only
    ] as $origin) {
        self::assertFalse($p->allowsOrigin($origin, new WP_REST_Request()), $origin);
    }
}

public function testNullAndEmptyOriginNeverMatchEvenIfConfigured(): void
{
    $p = new NTDST_Cors_Policy(['origins' => ['null', '']]);
    self::assertFalse($p->allowsOrigin('null', new WP_REST_Request()));
    self::assertFalse($p->allowsOrigin('', new WP_REST_Request()));
}

public function testWildcardIsRejectedAtConstruction(): void
{
    $this->expectException(InvalidArgumentException::class);
    new NTDST_Cors_Policy(['origins' => ['*']]);
}

public function testCallableResolverIsSupported(): void
{
    $p = new NTDST_Cors_Policy(['origins' => fn (string $o): bool => $o === 'https://dyn.example.com']);
    self::assertTrue($p->allowsOrigin('https://dyn.example.com', new WP_REST_Request()));
    self::assertFalse($p->allowsOrigin('https://other.example.com', new WP_REST_Request()));
}
```
- [ ] **Step 2 (GREEN):** Implement construction + matching (house style: `declare(strict_types=1)`, ABSPATH guard, `final class NTDST_Cors_Policy`). Config keys: `origins` (list<string>|callable — required), `methods` (default `['GET','POST','OPTIONS']`), `headers` (default `['Content-Type']`), `max_age` (default `null`). Constructor throws `InvalidArgumentException` on `'*'` in the list or a non-list/non-callable. Matching: reject `''` and `'null'` first; list → `in_array($origin, $list, true)`; callable → `(bool) $resolver($origin, $request)` still behind the `''`/`'null'` guard (threat-model mitigation 8).
- [ ] **Step 3:** Green + suite + stan. Commit: `feat(ntdst-core): NTDST_Cors_Policy — exact-origin allow-list (denial-path tested)`.

**Tier:** A — security predicate (erosion guard: always Tier A). RED-first with denial paths shown.

### Task 2.2: Header application (WP-core quirk 1) + route scoping

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/CorsPolicy.php`
- Test: `tests/Unit/NtdstCorsPolicyTest.php` (extend)

**Interfaces:** Produces `register(string $routePrefix): void` (hooks `rest_pre_serve_request` prio 20), `applyCorsHeaders(bool $served, mixed $result, WP_REST_Request $request, mixed $server): bool`, test seams `setHeaderSender(callable)` / `setHeaderRemover(callable)` — consumed by Task 3.4.

- [ ] **Step 1 (RED):** Using capturing seams (the proven todai-client pattern — native `header()` is unobservable in CLI SAPI):
  - allowed origin → sent headers contain `Access-Control-Allow-Origin: https://app.example.com`, `Vary: Origin`, configured methods/headers, `Access-Control-Max-Age` only when configured; removed headers contain `Access-Control-Allow-Credentials` (ALWAYS — mitigation 1);
  - denied origin → removed contains BOTH `Access-Control-Allow-Credentials` AND `Access-Control-Allow-Origin`; sent contains NO `Access-Control-*`;
  - foreign route (`/wp/v2/posts`) → sender AND remover never called, `$served` passed through unchanged (mitigation 7);
  - return value is always the incoming `$served` (never converts to true).
- [ ] **Step 2 (GREEN):** Implement — port `SubmissionIntakeService::applyCorsHeaders()` generalized: route-prefix check first (`str_starts_with($request->get_route(), $this->routePrefix)`), then unconditional `('Access-Control-Allow-Credentials')` removal, then match → exact-origin echo + `Vary: Origin` + methods/headers/max-age, else remove `Access-Control-Allow-Origin`. `register($routePrefix)` stores the prefix and `add_filter('rest_pre_serve_request', [$this,'applyCorsHeaders'], 20, 4)` — priority 20 with the full ground-truthed docblock (core's `rest_send_cors_headers` at 10 reflects any origin + sets credentials; `header()` replaces same-name; 20 guarantees our decision wins) copied/adapted from the reference implementation, cited as such.
- [ ] **Step 3:** Green + suite + stan. Commit: `feat(ntdst-core): CorsPolicy header emission — overrides WP core reflection+credentials (quirk 1), route-scoped`.

**Tier:** A — the CORS header decision is the 1a surface itself.

### Task 2.3: Loader wiring

**Files:**
- Modify: `web/app/mu-plugins/ntdst-coreloader.php` (one require line after `api/Response.php`)

- [ ] **Step 1:** Add `require_once NTDST_PATH . '/api/CorsPolicy.php';` after the Response require (line ~31). Run full suite + load the site once (`curl -sI https://stride.ddev.site | head -1` → 200).
- [ ] **Step 2:** Commit: `chore(ntdst-core): load CorsPolicy`.

**Tier:** B — `no unit test: Tier B, single require line; reachability proven by Task 2.1/2.2 tests running against the loaded class + site smoke`.

`── REVIEW GATE ── (tier: FULL — cluster IS the origin-allow-list / CORS 1a surface; verify against threat-model mitigations 1, 2, 7, 8)`

---

## Phase 3 — `NTDST_Rest_Registrar`

### Task 3.1: Registration core + required-permission enforcement

**Files:**
- Create: `web/app/mu-plugins/ntdst-core/api/RestRegistrar.php`
- Test: `tests/Unit/NtdstRestRegistrarTest.php` (create)

**Interfaces:** Produces `new NTDST_Rest_Registrar(string $namespace)`, `->get/post/put/patch/delete(string $route, callable $handler, array $options): self`, `->route(string $route, string|array $methods, callable $handler, array $options): self`. Registration flushes on `rest_api_init`; **registers immediately if `did_action('rest_api_init')`** (doubt-pass correction D1✦). A route with a missing/non-callable `permission` is NOT registered: `_doing_it_wrong()` + `ntdst_log('api')->error()` (mitigation 6).

- [ ] **Step 1 (RED):** Tests via a `register_rest_route` capturing stub (add to stubs: record calls into a global array): (a) route queued pre-`rest_api_init` registers on flush with namespace + route + methods + wrapped callback + permission present; (b) `did_action('rest_api_init')` truthy → registers immediately; (c) missing `permission` → `register_rest_route` NEVER called for that route, `_doing_it_wrong` recorded (stub), log error recorded; (d) `'args'` passed through verbatim.
- [ ] **Step 2 (GREEN):** Implement (house style; `final class`; no `NTDST_Service_Meta`). Constructor takes namespace; `add_action('rest_api_init', [$this, 'flush'])` once per instance guardedly.
- [ ] **Step 3:** Green + suite + stan. Commit: `feat(ntdst-core): NTDST_Rest_Registrar — namespaced REST registration, permission required (no default)`.

**Tier:** A — the missing-permission denial path is a guard contract (erosion guard).

### Task 3.2: Permission memoization wrapper (WP-core quirk 2)

**Files:**
- Modify: `api/RestRegistrar.php`
- Test: `tests/Unit/NtdstRestRegistrarTest.php` (extend)

- [ ] **Step 1 (RED):** (a) a counting permission callable invoked twice with the SAME `WP_REST_Request` object increments once, both calls return the same result (incl. a `WP_Error` result); (b) two DISTINCT request objects → two evaluations; (c) the memoizer is a `\WeakMap` keyed by the request object (assert via behavior, not reflection: create-and-release requests in a loop must not cross-contaminate).
- [ ] **Step 2 (GREEN):** Port the reference `checkWritePermission()`/`evaluateWritePermission()` split as a generic `wrapPermission(callable $permission): callable` with a private per-wrapper `\WeakMap<WP_REST_Request, bool|WP_Error>` captured in each returned closure. [PLAN-CORRECTION 2026-07-03: per-registrar shared map was an auth-bypass (cross-route verdict leak, caught at 3.2 review); per-wrapper map is the correct realization of mitigation 3] Docblock carries the ground-truth citations (rest_send_allow_header double-call at rest-api.php:854-886; WeakMap vs spl_object_id id-reuse) adapted from the reference implementation.
- [ ] **Step 3:** Green ×3 runs (object-identity sensitivity) + suite + stan. Commit: `feat(ntdst-core): registrar memoizes permission_callback per request (quirk 2 — rest_send_allow_header double-call)`.

**Tier:** A — auth-adjacent behavior with a named WP-core interaction bug.

### Task 3.3: Body/depth limits via `rest_pre_dispatch` (WP-core quirk 3)

**Files:**
- Modify: `api/RestRegistrar.php`
- Test: `tests/Unit/NtdstRestRegistrarTest.php` (extend)

- [ ] **Step 1 (RED):** (a) body over `max_body_bytes` → `WP_Error('payload_too_large', …, ['status'=>413])`; (b) exactly at cap → passes through; (c) JSON deeper than `max_json_depth` → `WP_Error('invalid_json', …, ['status'=>400])`; (d) empty body passes; (e) a request whose route does NOT match the registrar's namespace/route prefix → `$result` passed through untouched (mitigation 7); (f) routes with no caps configured → filter not even added.
- [ ] **Step 2 (GREEN):** Port `enforceBodyLimitsBeforeDispatch()` generalized: the registrar adds ONE `add_filter('rest_pre_dispatch', …, 5, 3)` (lazily, only when at least one route sets a cap) that looks up the matched route's caps from its own route table by prefix. Cast `(string) $request->get_body()` (nullable in core — reference docblock). Generic client messages (`'Request could not be processed.'`).
- [ ] **Step 3:** Green + suite + stan. Commit: `feat(ntdst-core): registrar pre-dispatch body size/JSON depth caps (quirk 3 — before core's default-depth parse)`.

**Tier:** A — untrusted parsing (threat-model predicate).

### Task 3.4: Handler-return normalization + `Router::rest()` facade + loader

**Files:**
- Modify: `api/RestRegistrar.php`, `core/Router.php`, `web/app/mu-plugins/ntdst-coreloader.php`
- Test: `tests/Unit/NtdstRestRegistrarTest.php` (extend), `tests/Unit/NtdstRouterTest.php` (extend)

**Interfaces:** Produces the full D6 normalization + `NTDST_Router::rest(string $namespace): NTDST_Rest_Registrar` (per-namespace cached) + `'cors' => NTDST_Cors_Policy` option wiring (`$policy->register('/' . $namespace . $route)` at flush).

- [ ] **Step 1 (RED):** Normalization: array → `['success'=>true,'data'=>…]` `WP_REST_Response` 200; `WP_Error` → returned as-is; `WP_REST_Response` → as-is; `NTDST_Response` (`->withData()->error()` variants) → `toRestResponse()` payload+status; unexpected scalar → `WP_Error('invalid_handler_return', …, ['status'=>500])` + logged. Facade: `ntdst_router()->rest('a/v1')` returns same instance on second call; different namespace → different instance.
- [ ] **Step 2 (GREEN):** Implement `normalizeResult()` wrapping each handler at registration; implement the facade:

```php
/** @var array<string, NTDST_Rest_Registrar> */
protected array $rest_registrars = [];

public function rest(string $namespace): NTDST_Rest_Registrar
{
    return $this->rest_registrars[$namespace] ??= new NTDST_Rest_Registrar($namespace);
}
```
  Add `require_once NTDST_PATH . '/api/RestRegistrar.php';` after CorsPolicy's require. Class docblock documents: WP-native route syntax (D5), the WP_Error-message-reaches-the-wire warning (mitigation 10), the public-route permission pattern, and the three quirks it + CorsPolicy absorb.
- [ ] **Step 3:** Green + full suite + stan. Commit: `feat(ntdst-core): handler-return normalization + ntdst_router()->rest() facade — the REST registration convergence point`.

**Tier:** A for normalization (transform with contract); the facade + require lines are folded glue (wiring proof lands in Phase 4's seam tests — deferral: `Risk not covered: un-mocked full REST dispatch — deferred to integration-gate Tasks 4.1/4.2`).

`── REVIEW GATE ── (tier: FULL — cluster is the public-dispatch + untrusted-parsing 1a surface; verify against threat-model mitigations 3, 4, 6, 7, 10)`

---

## Phase 4 — Integration + acceptance (the seam proof)

### Task 4.1: Integration — registrar through real WP dispatch

**Files:**
- Test: `tests/Integration/RestRegistrarIntegrationTest.php` (create)

- [ ] **Step 1:** In the integration bootstrap (real WP), register a fixture route `ntdst-test/v1 /echo` through `ntdst_router()->rest()` with a counting permission callable + body caps, then dispatch via `rest_do_request()` (un-mocked chain — the seam-test obligation): (a) allowed request → 200 + `{success:true,data:…}` envelope; (b) permission evaluation count === 1 after a full dispatch cycle incl. `rest_post_dispatch` (`rest_send_allow_header` fires — this is the REAL quirk-2 proof); (c) handler `WP_Error` → error status + WP-native shape; (d) oversized body → 413 short-circuit, permission never invoked (negative/adversarial case); (e) route registered with no permission → absent from the route table.
- [ ] **Step 2:** Also assert Router exit-paths deferred from Task 0.3/1.3 where feasible in-process (a pattern-route callback returning a Response with a template renders that template's output — use `template_include` filter application against a registered fixture route; if `exit` makes in-process assertion impossible, mark that specific assert for Task 4.3's HTTP pass and record it).
- [ ] **Step 3:** Integration suite green: `vendor/bin/phpunit -c phpunit-integration.xml.dist`. Commit: `test(ntdst-core): registrar integration — un-mocked REST dispatch, quirk-2 single-evaluation proof`.

**Tier:** A (seam test — un-mocked chain + adversarial cases, per the wiring-task obligation).

### Task 4.2: Integration — CORS over real dispatch incl. preflight

**Files:**
- Test: `tests/Integration/CorsPolicyIntegrationTest.php` (create)

- [ ] **Step 1:** Fixture route with `NTDST_Cors_Policy(['origins'=>['https://allowed.test']])`, header seams capturing. Drive `rest_pre_serve_request` through a real `WP_REST_Server::serve_request`-shaped call (or apply_filters with a real matched request): (a) allowed origin POST → exact ACAO + Vary, credentials header removed; (b) OPTIONS preflight with allowed origin → same policy headers present; (c) disallowed origin → no ACAO, credentials removed; (d) foreign route (`/wp/v2/types`) → untouched.
- [ ] **Step 2:** Integration suite green. Commit: `test(ntdst-core): CORS integration — preflight + denial + foreign-route isolation`.

**Tier:** A (seam test for the CORS wire).

### Task 4.3: Acceptance — real HTTP drive of the Acceptance flows matrix

**Files:**
- Create: `tests/Support/ntdst-rest-acceptance-fixture.php` (a test-only mu-plugin fixture registering `ntdst-test/v1/echo` with CorsPolicy + caps; installed into `web/app/mu-plugins/` ONLY for this task's run, removed after — NOT committed to the mu-plugins dir; the fixture file itself is committed under `tests/Support/`)

- [ ] **Step 1:** Install fixture (`cp tests/Support/ntdst-rest-acceptance-fixture.php web/app/mu-plugins/`), then drive Flows 1–3 over real HTTP:
```bash
# Flow 2 — preflight, allowed origin (expect: ACAO exact, NO allow-credentials)
curl -si -X OPTIONS 'https://stride.ddev.site/wp-json/ntdst-test/v1/echo' \
  -H 'Origin: https://allowed.test' -H 'Access-Control-Request-Method: POST' | grep -i '^access-control\|^vary'
# Flow 1 — allowed POST (expect 200 + envelope)      | denied origin (expect: no ACAO)
curl -si -X POST 'https://stride.ddev.site/wp-json/ntdst-test/v1/echo' \
  -H 'Origin: https://allowed.test' -H 'Content-Type: application/json' -d '{"a":1}'
curl -si -X POST '…/echo' -H 'Origin: https://evil.test' -H 'Content-Type: application/json' -d '{"a":1}' | grep -ic 'access-control-allow-origin'   # expect 0
# Boundary edges — oversized body (413), deep JSON (400), Origin: null (no ACAO)
# Flow 3 — Endpoints backward-compat
curl -s -X POST 'https://stride.ddev.site/wp-json/ntdst/v1/get_nonce' -H 'Content-Type: application/json' \
  -H "Origin: https://stride.ddev.site" -d '{"action":"search_posts"}'    # expect {success:true,data:{nonce:…}}
```
  Record each Acceptance-flows row's result (`pass`/`fail`/`not-reachable`) in the task-close note. Flow 1's real-browser pass (cross-origin `fetch()` from a scratch page on another local origin, via superpowers-chrome) runs at shake-out per the matrix — record `deferred-to-shakeout` here.
- [ ] **Step 2:** Remove the fixture from `web/app/mu-plugins/`, verify `curl -s https://stride.ddev.site/wp-json/ | grep -c ntdst-test` → 0. Commit fixture under `tests/Support/` only: `test(ntdst-core): acceptance fixture + HTTP drive of acceptance flows`.

**Tier:** A (acceptance evidence; the manifest is the deliverable).

### Task 4.4: Full regression gate

- [ ] **Step 1:** `vendor/bin/phpunit --testsuite Unit` (expect ≥706 + new, 0 failures) → `vendor/bin/phpunit -c phpunit-integration.xml.dist` (expect ≥261 + new) → `composer lint:stan` clean.
- [ ] **Step 2:** Record counts before/after in the commit body. Commit any stragglers: `test(ntdst-core): phase-4 full regression green`.

**Tier:** B — `no unit test: Tier B, verification-only task`.

`── REVIEW GATE ── (tier: FULL — spec-close-shaped cluster proving the 1a surfaces end-to-end; reviewers verify the acceptance manifest against the Acceptance flows matrix + all 10 threat-model mitigations)`

---

## Phase 5 — Invariants doc, re-scope docs, conventions

### Task 5.1: `ARCHITECTURE-INVARIANTS.md` — add INV-10, reconcile INV-2/INV-5

**Files:** Modify: `ARCHITECTURE-INVARIANTS.md`

- [ ] **Step 1:** Author INV-10 exactly per the "Architecture invariants touched" section above (convergence points, rule, known accepted baseline, audit move). Add it to the Quick-reference table (row 10). Verify INV-2's `Endpoints.php:333` line references against the final diff and update if shifted. Add one line to INV-5 noting `Response` additionally owns the API envelope (`apiSuccess/apiError/toRestResponse`).
- [ ] **Step 2:** Run INV-10's audit move — expect only the named baseline files. Commit: `docs(invariants): INV-10 — REST registration + CORS decision convergence point`.

**Tier:** B — `no unit test: Tier B, documentation; the audit-move grep run is the verification`.

### Task 5.2: Re-scope docblocks (`Endpoints`, `Router`)

**Files:** Modify: `api/Endpoints.php` (header docblock only), `core/Router.php` (class + `register()` docblocks)

- [ ] **Step 1:** `Endpoints` header: replace the stale `ALLOW_RESTAPI_AJAX` requirement (drift finding 7); add the re-scope paragraph: *"SCOPE: the same-origin, nonce-gated generic-action dispatcher (`ntdst/api_data/{action}`). NOT a general REST route registrar and NOT for cross-origin callers — an anonymous WP nonce is a shared, non-origin-bound token that authenticates nothing for a cookie-less cross-origin caller. For new REST routes (incl. cross-origin/CORS) use `ntdst_router()->rest()` + `NTDST_Cors_Policy`."* (mitigation 5).
- [ ] **Step 2:** `Router::register()` return-contract docblock gains the `NTDST_Response` line (matching the implemented Task 1.3 behavior). Class docblock gains one `rest()` usage example.
- [ ] **Step 3:** Suite green (docs only). Commit: `docs(ntdst-core): re-scope Endpoints as same-origin dispatcher; Router return contract updated`.

**Tier:** B — `no unit test: Tier B, doc-only`.

### Task 5.3: Update fleet conventions

**Files:** Modify: `~/Sites/netdust-wp-manager/memory/projects/ntdst-core/CONVENTIONS.md` (§9 + REST API section), `~/Sites/netdust-wp-manager/memory/projects/ntdst-core/HANDOFF-output-layer-reshape.md` (status header)

- [ ] **Step 1:** CONVENTIONS.md: rewrite §9 from "gap" to "resolved — the spine": `ntdst_router()->rest()` contract summary (the API-contract block above, condensed), CorsPolicy usage, the three quirks now absorbed centrally, Endpoints' re-scope. Correct the stale `search_users`-is-public line (drift finding 8). HANDOFF: status → `Executed in Stride (feat/ntdst-output-layer, 2026-07-XX) — see Stride docs/plans/2026-07-03-ntdst-core-output-layer-reshape.md; propagation pending per GLOBAL.md`.
- [ ] **Step 2:** Commit in the manager repo: `memory(ntdst-core): conventions §9 — output layer reshaped; handoff status`.

**Tier:** B — `no unit test: Tier B, fleet documentation`.

`── REVIEW GATE ── (tier: STANDARD — docs + invariants authoring; no code behavior, but INV-10's wording is load-bearing for future reviews — one careful pass)`

---

## Phase 6 — Propagation documentation + finish

### Task 6.1: GLOBAL.md propagation entry (documented, NOT executed)

**Files:** Modify: `~/Sites/netdust-wp-manager/memory/GLOBAL.md`

- [ ] **Step 1:** Add an entry per the 2026-06-12 "ntdst-core hardening — propagate to all NTDST sites" precedent: (a) files changed (`api/Response.php`, `api/Endpoints.php`, `core/Router.php`, NEW `api/CorsPolicy.php`, NEW `api/RestRegistrar.php`, `ntdst-coreloader.php` — plus the test files as the porting reference); (b) affected sites = every ntdst-core project (list per the fleet registry); (c) per-site steps: copy the five framework files + loader lines, run that site's suite, re-run the INV-10 audit grep; (d) **drift warnings:** todai-client's `Endpoints.php` is an OLDER revision missing Stride's `verifyOrigin()`/per-action-rate-limit hardening — propagating this reshape to todai-client means propagating that hardening too (they arrive together in the file copy; note the behavioral delta for its `api_data` consumers); `api/QueryCache.php` already differs between the two (pre-existing, out of this entry's scope); (e) named candidate consumer: todai-client `SubmissionIntakeService` can be refactored onto `ntdst_router()->rest()` + `NTDST_Cors_Policy` — separate decision, link the handoff.
- [ ] **Step 2:** Commit: `memory(fleet): ntdst-core output-layer reshape — propagation entry (documented, not executed)`.

**Tier:** B — `no unit test: Tier B, fleet documentation`.

### Task 6.2: Finish branch

- [ ] **Step 1:** Final full regression (unit + integration + stan) in the worktree; `superpowers:verification-before-completion` evidence in the close note.
- [ ] **Step 2:** Per Stride flow (`site.yml`): PR `feat/ntdst-output-layer` → `staging` first (never direct to main; staging validation rule). Hand to `superpowers:finishing-a-development-branch`. Worktree removal after merge: `git worktree remove /home/ntdst/Sites/stride-output-reshape`.

**Tier:** B — `no unit test: Tier B, branch mechanics`.

`── REVIEW GATE ── (tier: LIGHT — memory/docs + branch mechanics only)`

---

## Sibling-site audit blocks (cross-cutting concerns)

1. **`Router` pattern-route callers** (the Task 1.3 behavior change): every `ntdst_route(`/`->get(`/`->post(`/`->register(` call site in `stride-core` + `stridence` audited for Response-return reliance on the old fall-through — executed as Task 1.3 Step 3 (STOP-and-escalate on a hit).
2. **`ntdst/api_data` consumers** (14 files listed in the plan's research — `CompletionTaskHandler`, `NotificationService`, `QuestionnaireHandler`, `CompletionProofStorage`, `AdminDashboardService`, `QuoteUpdateHandler`, `BulkRegistrationHandler`, `EnrollmentFormHandler`, `CatalogEndpoint`, `AnnualReportHandler`, `RosterBulkHandler`, `ICalHandler`, `ProfileHandler`, `StrideSettingsService`): NO changes expected; the Phase-0 characterization tests + untouched `ApiEndpointIntegrationTest` are the guard. Any diff line in these files is a finding.
3. **`register_rest_route` call sites** (PartnerAPI, AdminAPIController, Assistant registrars): untouched; enshrined as INV-10's accepted baseline in Task 5.1 so future reviews don't re-flag them.
4. **`NTDST_Response` output-method consumers** (`QuestionnaireRenderer`, `EnrollmentRouter`, PDF generators, `ICalHandler`, theme templates): additive-only changes; existing `ResponseTest` + `NtdstResponseHardeningTest` + full suite are the guard.

## Out-of-scope log (named follow-ups, nothing more)

1. **`NTDST_Data_Model` naming/scope (`api/Data.php`)** — CPT/post-meta-only ORM under a generic "Data" name; clarity issue flagged in the handoff, deliberately NOT touched here. Follow-up owner: Stefan, next ntdst-core architecture pass.
2. **Canonical-repo / single-source-of-truth for ntdst-core** — rejected for this pass; revisit if propagation pain compounds (the drift findings above are evidence for the file).
3. **Shared `NTDST_Rate_Limiter` extraction** — threat-model deferral 1.
4. **todai-client `SubmissionIntakeService` refactor onto the registrar** — propagation-entry candidate, separate decision.
5. **Endpoints-hardening propagation to todai-client** — rides along with any file-copy propagation; noted in Task 6.1(d).

## Open questions for Stefan — ALL RESOLVED 2026-07-03 (pre-execution)

1. **Test-execution mode for the worktree** (Task 0.1): **CONFIRMED — direct PHPUnit in the worktree for unit; DDEV-mounted run for integration** (the plan's assumption, as written).
2. **Envelope for new registrar routes** (D6): **CONFIRMED — `{success:true,data:…}` wrap**, consistent with `Endpoints`/`Response::json()`. Permanent wire contract as specified in D6/Task 3.4.
3. **Acceptance fixture disposition** (Task 4.3): **CONFIRMED — transient test-only fixture** under `tests/Support/`, installed only for the acceptance run, never shipped. No `WP_DEBUG` example route.
