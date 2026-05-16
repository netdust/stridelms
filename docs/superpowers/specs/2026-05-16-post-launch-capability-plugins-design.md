# Post-Launch Capability Plugin Architecture

**Status:** Design — post-launch
**Date:** 2026-05-16
**Author:** Stefan + Claude (brainstorm)
**Relates to:** `docs/LAUNCH-CHECKLIST.md` (Partner API marked post-launch, line 40), `plans/plugin-extraction.md` (precedent for theme→plugin extraction)
**Supersedes:** nothing — additive
**Triggered by:** brainstorm on "conference subsite" and "livestream" feature ideas, which surfaced a structural decision about where outward-facing capabilities should live.

---

## 1. Problem

Stride v1 ships as a single mu-plugin `stride-core` containing both:

1. **Core engine** — editions, sessions, enrollment, invoicing, attendance, completion tasks, dashboards, LearnDash integration. Required to run *any* Stride instance.
2. **Outward-facing capabilities** — currently only `Modules/PartnerAPI/`, which exposes Stride data to authenticated partner organisations via REST. Optional per client; not required to run a basic Stride instance.

Two post-launch ideas — a **public/headless API for conference frontends** and (longer-term) **LTI integration** — would, if added the same way, balloon `stride-core` further and lock unrelated concerns into one release cadence, test suite, and security review surface.

The status quo also forces every Stride install to load Partner API code, register its routes, and run its tests, even when the client has no partners.

This design proposes a small structural shift, executed only **post-v1-launch**, that re-frames `stride-core` as the engine and moves capabilities outward into dedicated plugins.

---

## 2. Goals & Non-Goals

### Goals
- Establish a "core + capability plugins" pattern for Stride, with one extraction (Partner API) and one new build (Conference API) demonstrating it.
- Enable per-client capability activation: a client without partners doesn't load Partner API; a client without a conference site doesn't load Conference API.
- Keep `stride-core`'s scope honest — only what every Stride instance needs.
- Make the public/headless conference API a real product with a deliberate contract, not a side-effect of enabling `show_in_rest`.
- Decouple release cadences: a Conference API breaking change should not require a `stride-core` release.

### Non-Goals
- **Not** building any conference *frontend*. The frontend (e.g. `studiedag.stride.be`) is a separate downstream project consuming this API. The design covers the backend only.
- **Not** changing anything pre-launch. Ship Stride v1 with PartnerAPI inside `stride-core` as it is today.
- **Not** extracting other modules (Edition, Enrollment, Invoicing, etc.). Those are core.
- **Not** designing the LTI plugin. Mentioned only as the next user of this pattern.
- **Not** building structured speakers/sponsors content into `stride-core`. That lives in the Conference plugin.

---

## 3. Strategic Decisions (with rationale)

### 3.1 Core vs Capability

Definition we'll use going forward:

> **`stride-core` = everything required to run *one* programs+registrations platform with no external integrations.** Editions, sessions, enrollment wizard, attendance, invoicing, completion tasks, user dashboard, admin dashboard, LearnDash integration, mail bridge, audit bridge.

> **Capability plugin = anything outward-facing or optional per client.** Partner B2B API, public/headless API, LTI integration, third-party LMS adapters, per-client customisations.

PartnerAPI is the canonical example of capability that wrongly lives inside core today. Conference API is the canonical example of capability we're tempted to add to core and shouldn't.

### 3.2 Extract before invent

The Partner API extraction comes **before** the new Conference plugin, in that order, for one reason: extracting working code is a refactor (low risk, validates the pattern with a known-good test suite); inventing a new plugin from scratch is pioneering. Doing the new plugin first means designing the "capability plugin extends core" contract on greenfield code, which is harder to verify.

Extract first, prove the pattern works, then build the second plugin against the now-validated shape.

### 3.3 No reliance on WP REST for the public API

WordPress's default REST API is enabled by flipping `show_in_rest => true` on the CPT. We're not doing that for the public conference API because:

1. The default shape leaks internal prefixes (`_ntdst_start_date`, prices as strings, computed fields absent).
2. Every meta field becomes publicly readable unless individually gated — a security footgun.
3. Computed/joined data (seats remaining, edition+sessions+course in one round-trip) requires custom controllers anyway.
4. The existing `stride/v1/...` namespace already establishes the pattern.

We **may** enable `show_in_rest` on `vad_edition` for Gutenberg/block-editor benefit if a future authoring need arises, but the *public consumption* contract is hand-shaped at `stride/v1/public/*`. This mirrors how WooCommerce coexists: `wp/v2/products` exists, `wc/v3/products` is the actual product.

### 3.4 Soft vs hard content split (option C from brainstorm)

For conference event pages, content splits along two axes:

- **Hard data** (dates, price, capacity, sessions, venue, status, course link) — lives in `stride-core`, authoritative, served unchanged by the Conference API.
- **Soft/editorial content** (speakers, sponsors, hero image, tagline, FAQ) — modeled by the **Conference plugin**, exposed through the same API, *optional* per edition. The frontend can use it or override with its own CMS.

Without the Conference plugin installed, `stride/v1/public/editions/{id}` still works — it just returns the hard-data subset. With the plugin installed, the same endpoint adds speakers/sponsors/etc. to the response. The plugin enriches the API; it doesn't fork it.

### 3.5 Enrollment handoff: hard redirect, not embed

The Conference frontend's "Inschrijven" button hard-redirects to `stride.be/inschrijven/{edition_id}` (an alias route to be added). The conference site is a marketing/presentation layer; the enrollment wizard is the product. Re-implementing the wizard's completion-task / session-selection / questionnaire flow on a separate frontend would duplicate the most complex part of Stride for cosmetic gain.

The handoff is made to feel intentional via:
- Visual continuity (per-client tokens shared between conference site and stridence theme).
- A clear "Je gaat nu inschrijven via Stride" header on the Stride side.
- Optional return-URL preservation so a successful enrollment can deep-link back to the conference site's confirmation page.

The route accepts unauthenticated visitors and only prompts login at the wizard step where it's required (currently: before submitting the form). Today, the route requires login up front (see `EnrollmentRouter.php:34-48`); this needs adjusting. Where this alias route physically lives — in the Conference plugin or in `stride-core` itself — is left to §7.2.

---

## 4. Target Architecture

```
web/app/mu-plugins/
├── ntdst-coreloader.php           # framework loader (existing)
├── ntdst-core/                    # framework (existing)
├── stride-coreloader.php          # core loader (existing)
├── stride-core/                   # ENGINE (existing, slimmed)
│   ├── Modules/
│   │   ├── Edition/               # ← stays
│   │   ├── Enrollment/            # ← stays
│   │   ├── Invoicing/             # ← stays
│   │   ├── Attendance/            # ← stays
│   │   ├── Trajectory/            # ← stays
│   │   ├── Questionnaire/         # ← stays
│   │   ├── Notification/          # ← stays
│   │   ├── Mail/, Audit/, User/, Assistant/  # ← stay
│   │   └── PartnerAPI/            # ← REMOVED post-launch (extracted)
│   ├── Admin/, Handlers/, Integrations/, Contracts/, Domain/, Infrastructure/
│   └── plugin-config.php
│
├── stride-partner-api-loader.php  # NEW
├── stride-partner-api/            # NEW — extracted from stride-core
│   ├── PartnerAPIController.php
│   ├── plugin-config.php
│   └── tests/                     # moved from stride-core's test dirs
│
├── stride-conference-loader.php   # NEW
├── stride-conference/             # NEW — green-field plugin
│   ├── PublicAPIController.php    # GET /stride/v1/public/editions[/{id}]
│   ├── ConferenceFields/          # speakers, sponsors, hero, FAQ admin UI
│   ├── ConferenceTab.php          # adds tab to Edition admin screen
│   ├── plugin-config.php
│   └── tests/
│
└── stride-client-{kindred,...}/   # per-client branding (existing pattern)
```

### Loading model

Each capability plugin registers itself via its own `*-loader.php` mu-plugin file (matching the existing `stride-coreloader.php` convention). Per-client deployments can selectively include only the loaders they need by managing which loader files are present in `mu-plugins/`.

Capability plugins assume `stride-core` is loaded (declared as a hard dependency in the loader, with a fatal admin notice if absent). They do **not** depend on each other.

### Bootstrap order

1. `ntdst-coreloader` — framework
2. `stride-coreloader` — core engine, services registered
3. `stride-partner-api-loader` — registers Partner routes (if present)
4. `stride-conference-loader` — registers Public API + Conference admin (if present)
5. `stride-client-{client}` — branding tweaks (if present)

Capability plugins hook into `stride-core` only through:
- The DI container (`ntdst_get(SomeStrideService::class)`).
- The existing `Stride\Contracts\*` interfaces (e.g. `EditionQueryInterface`).
- Public WordPress hooks Stride fires.

No capability plugin reaches into another's internals. Two capability plugins can coexist or be installed alone; each is independently shippable.

---

## 5. The Two Concrete Pieces

### 5.1 Partner API extraction (refactor, ~1-2 days)

**Scope:** move `stride-core/Modules/PartnerAPI/` to its own plugin. Behaviour unchanged.

**Steps (overview, plan-level detail belongs in the implementation plan):**
1. Create `web/app/mu-plugins/stride-partner-api/` skeleton with its own `plugin-config.php`.
2. Move `PartnerAPIController.php` and any PartnerAPI-only helpers. Update namespace from `Stride\Modules\PartnerAPI\` to `Stride\PartnerAPI\` (or keep — see open question 7.1).
3. Move tests: `tests/Unit/PartnerAPIControllerTest.php`, `tests/Integration/PartnerAPIIntegrationTest.php`, and any fixtures.
4. Create `stride-partner-api-loader.php` mirroring `stride-coreloader.php`.
5. Remove the PartnerAPI registration from `stride-core/plugin-config.php`.
6. Update `composer.json` autoload mappings if PSR-4 paths change.
7. Verify all 674 unit + 221 integration tests still pass.
8. Verify Partner API endpoints respond unchanged from a real curl request.

**Acceptance:** `composer test`, manual curl against `/stride/v1/partner/*`, plus a deliberate test that disabling the plugin (rename loader file) returns 404 on those routes — confirming isolation.

**Risk:** low. The module is already self-contained (its own controller, its own auth, its own tests). The main risk is missing a hidden coupling — e.g. a `ntdst_get(PartnerAPIController::class)` call from somewhere unexpected. Plan should include a grep for cross-references before starting.

### 5.2 Conference plugin (new build, ~1-2 weeks)

**Scope:**
- A public read-only REST namespace `stride/v1/public/*`.
- Structured editorial content (speakers, sponsors, hero, FAQ) attached to editions.
- An "Conference" tab added to the Edition admin screen, only visible when the plugin is active.
- CORS support for cross-origin reads.
- An unauthenticated enrollment entry-point alias.

#### 5.2.1 Public API contract

**`GET /stride/v1/public/editions`** — list of upcoming editions.

Query parameters:
- `per_page` (default 20, max 50)
- `page` (default 1)
- `from` (ISO date; default: today)
- `to` (ISO date; optional)
- `course_id` (optional)
- `status` (optional; whitelist of public-safe statuses only — never returns drafts or cancelled). The exact public-safe set should align with `OfferingStatus` enum values; the implementation plan resolves which values map to which API string.

Response shape (single item):
```json
{
  "id": 123,
  "slug": "studiedag-alcohol-jongeren-2026",
  "title": "Studiedag Alcohol & Jongeren 2026",
  "summary": "...",                              // post excerpt
  "start_date": "2026-09-15",
  "end_date": "2026-09-15",
  "price": {
    "amount_cents": 4500,
    "currency": "EUR",
    "formatted": "€ 45,00"
  },
  "price_non_member": { ... },                   // optional
  "capacity": 300,
  "seats_remaining": 47,                          // computed
  "status": "open",                              // public-safe subset of OfferingStatus; exact mapping in implementation plan
  "venue": {
    "name": "...",
    "address_line_1": "...",
    "postal_code": "...",
    "city": "..."
  },
  "course": {
    "id": 47,
    "title": "..."
  },
  "enroll_url": "https://stride.example/inschrijven/123",
  "conference": {                                // only if Conference plugin populated
    "hero_image": "https://...",
    "tagline": "...",
    "speakers": [
      { "name": "...", "bio": "...", "photo": "https://...", "talk_title": "..." }
    ],
    "sponsors": [
      { "name": "...", "logo": "https://...", "url": "https://..." }
    ],
    "faq": [
      { "q": "...", "a": "..." }
    ]
  }
}
```

**`GET /stride/v1/public/editions/{id}`** — same shape as a single list item, plus:
```json
{
  ...
  "sessions": [
    {
      "id": 456,
      "date": "2026-09-15",
      "start_time": "09:30",
      "end_time": "17:00",
      "title": "Plenaire opening",                // session title if present
      "location": "Zaal A",                        // optional, public-safe only
      "speaker_name": "..."                        // optional
    }
  ]
}
```

Notes on the shape:
- All prices as integer cents + ISO currency + formatted convenience string. Never strings-of-floats.
- `seats_remaining` and `status` are computed live (not stored). The controller calls existing `EditionService` methods.
- `enroll_url` is built server-side; the frontend doesn't reconstruct it.
- `conference.*` is only present if the Conference plugin has populated those fields for this edition. If absent, the key is omitted (not null).
- Internal fields explicitly **excluded**: `completion_threshold`, `requires_approval`, `requires_questionnaire`, `requires_documents`, `requires_session_selection`, `selection_open`, `post_requires_*`, `enrollment_form`, `documents`, `notes`, `selection_deadline`, `session_slots`, `completion_mode`. (See `EditionCPT.php:90-164` for the full internal field list to gate against.)
- Sessions exclude `lesson_ids`, `webinar_link`, `capacity`, `optional` flags — anything that's enrollment-flow business logic rather than agenda info.

**`GET /stride/v1/public/editions/{id}/availability`** — lightweight CTA-state endpoint.
```json
{ "status": "open", "seats_remaining": 47, "is_enrollable": true }
```
Used by the frontend to update the register button state without re-fetching the full edition payload.

#### 5.2.2 Structured editorial content

A new repeatable field group attached to the Edition CPT (registered only when the Conference plugin is active):

- `_ntdst_conf_hero_image` — attachment ID
- `_ntdst_conf_tagline` — text
- `_ntdst_conf_speakers` — JSON array of `{ name, bio, photo (attachment ID), talk_title }`
- `_ntdst_conf_sponsors` — JSON array of `{ name, logo (attachment ID), url }`
- `_ntdst_conf_faq` — JSON array of `{ q, a }`

These are added via `ntdst_data()->register()` extension or a similar hook into the existing field registry, so the framework's field handling (sanitisation, type coercion) is reused. The admin UI lives in `ConferenceTab.php` and renders a tab in the edition admin screen — only when the plugin is loaded.

**Why JSON arrays not child posts/CPTs:** speakers/sponsors are per-edition editorial content, not reusable entities. A speaker at one studiedag isn't "the same speaker" being reused; if they speak twice, the admin re-enters them. Avoids a CPT-per-thing explosion. If reuse becomes a real need later, migration to a `vad_speaker` CPT is straightforward.

#### 5.2.3 CORS

The Conference plugin adds an allowlist of origins (settings page or constant in `wp-config.php`):
```php
define('STRIDE_CONFERENCE_CORS_ORIGINS', [
    'https://studiedag.stride.be',
    'https://studiedag-alcohol-2026.stride.be',
]);
```

Headers added **only for `stride/v1/public/*` routes** via a `rest_pre_serve_request` filter. Other endpoints (Partner API, Admin API, wp-admin AJAX) remain origin-locked. Wildcards not supported in v1 — explicit list.

#### 5.2.4 Enrollment handoff route

Add to the Conference plugin (or to a small core route — see open question 7.2):
- `GET /inschrijven/{edition_id}` — accepts unauthenticated visitors, looks up the edition slug, redirects to the existing `/vormingen/{slug}/inschrijving/` flow. Adjusts that route's auth gate so the login prompt happens at the form-submission step, not page load.

This means the conference frontend only ever needs to know `edition.id`. Slug changes don't break inbound links.

#### 5.2.5 Auth posture & rate limiting

- All `stride/v1/public/*` routes: unauthenticated, GET-only, idempotent.
- Rate limiting: out of scope for v1 of this plugin. WordPress doesn't have native rate limiting; if abuse becomes a real problem, add it via existing infrastructure (Cloudflare, fail2ban) rather than building it into the plugin.
- Output caching: each response gets `Cache-Control: public, max-age=60` so a CDN or reverse proxy can absorb traffic spikes. 60s is short enough that capacity changes propagate quickly without hammering the database.
- Drafts, cancelled editions, and editions with `status=draft` are never returned, regardless of query.

#### 5.2.6 Testing

- Unit tests: response shape, field exclusion (assert internal fields never appear), pagination math, CORS header logic, status computation.
- Integration tests: full happy path against a seeded edition with and without conference fields populated; verify availability endpoint matches list endpoint; verify a draft edition is invisible.
- Acceptance test: `curl` from a different origin against the public endpoint and verify CORS headers + response shape (can be a simple shell script committed to the plugin).

---

## 6. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Partner API extraction breaks a hidden coupling. | Pre-flight grep for `PartnerAPIController` / `Modules\PartnerAPI` references across `stride-core` and theme before starting. Full test run after each step. |
| Conference plugin's editorial fields conflict with core field naming. | Use `_ntdst_conf_*` prefix exclusively; never reuse a core meta key. |
| Public API leaks an internal field through a future change to `EditionCPT`. | The controller has an explicit *allowlist* of fields to serialise. New fields added to the CPT are invisible to the public API by default; surfacing them is a conscious change with test coverage. |
| `seats_remaining` is computed live, vulnerable to load. | 60-second `Cache-Control`. If that's not enough, add a 10-second transient cache inside the controller. |
| Enrollment route allowing unauthenticated access regresses an existing auth requirement. | The change is *narrow*: allow page load + form render unauthenticated; require login at form-submission step (where it currently lives anyway). Existing tests on enrollment auth should be reviewed and updated explicitly. |
| Per-client deployments forget to install the right loader. | Document a per-client manifest (which loaders go in `mu-plugins/`); add a simple admin notice in `stride-core` listing detected capability plugins. |
| Speakers as JSON makes reporting/search harder. | Accepted trade-off; reporting on speakers is not a v1 need. Migration path to a `vad_speaker` CPT exists if it ever becomes one. |

---

## 7. Open Questions

These need answers before the implementation plan is written:

1. **Namespace for extracted plugins.** Keep `Stride\Modules\PartnerAPI\` (clean move) or rename to `Stride\PartnerAPI\` (signals it's no longer a sub-module)? The latter is more honest but is a bigger diff. Recommendation: rename, do it once, cleanly.
2. **Where does `/inschrijven/{id}` alias live?** In Conference plugin (capability) or in `stride-core` (since the route serves all enrollments, not just conference ones)? Recommendation: `stride-core`, because it's a general improvement to deep-linking and benefits non-conference flows too.
3. **Should `show_in_rest => true` be enabled on `vad_edition` regardless,** for Gutenberg/internal authoring tools, even though it's not the public API? Doesn't hurt as long as meta is gated, but adds a second contract to keep an eye on. Recommendation: defer until a Gutenberg need actually materialises.
4. **Conference plugin: per-edition opt-in, or always-on once installed?** Should every edition get the Conference tab, or only those flagged `type=studiedag`? Recommendation: always-on. Fields are optional; if unused they don't appear in the API. An `edition.type` discriminator can be added later if useful.
5. **Test infrastructure split.** Do extracted plugins keep their tests under root `tests/` or move them into `plugins/stride-partner-api/tests/`? Affects how `phpunit` discovers them. Recommendation: per-plugin tests under the plugin, root `tests/` becomes core-only.

---

## 8. Sequencing

Single timeline, post-v1-launch only:

1. **v1 launches** with PartnerAPI inside `stride-core`. No changes.
2. **Week 1 post-launch (~2 days):** Partner API extraction. Refactor only, no feature changes. Validates the capability-plugin loading pattern.
3. **Week 2-3 post-launch (~1-2 weeks):** Conference plugin built from scratch on the now-proven pattern. Ships with the public API contract, editorial fields, admin tab, CORS, enrollment alias.
4. **Downstream (separate project):** First conference frontend (e.g. `studiedag.stride.be` for a real VAD event) built against the now-stable API. Whatever tech stack makes sense — Astro, Next, Eleventy. Stride doesn't care.

Each step ships independently. Conference frontend work can begin as soon as step 3 lands.

---

## 9. What this design explicitly does not cover

- The conference frontend itself (separate downstream project).
- Livestreams. The other idea from the brainstorm is unrelated to this architectural decision; it deserves its own design when it gets prioritised.
- LTI plugin — mentioned as a future user of this pattern but not designed here.
- Migration plans for existing partners. Partner API extraction is internal; partner consumers see no contract change.
- A REST-based POST enrollment endpoint. Enrollment stays form-based and goes through the existing wizard.

---

## 10. Reviewer's checklist

When reviewing this doc:

- [ ] Does the core/capability split match how Stride should evolve, or is "capability" too vague?
- [ ] Is the WP-REST-as-non-public-API decision (§3.3) the right call?
- [ ] Is the soft/hard content split (§3.4) actually clean, or does it create a "but who owns dates" question I haven't anticipated?
- [ ] Is anything in `EditionCPT.php` missing from the field allowlist / blocklist in §5.2.1?
- [ ] Are the open questions in §7 the *real* open questions, or have I missed a structural one?
- [ ] Is "1-2 days for Partner API extraction" credible, or have I underestimated hidden couplings?
