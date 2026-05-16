# Stride Architecture Map — Design

**Date:** 2026-05-16
**Status:** Approved
**Goal:** Generate an onboarding/handoff artifact that maps the Stride app's architecture in a domain-driven view.

## Deliverables

Two files, committed under `docs/architecture/`:

1. **`stride-architecture.json`** — structured data, single source of truth.
2. **`stride-architecture.html`** — self-contained interactive viewer (hierarchical graph + sidebar) that loads the JSON.

## Model: Domain-Driven

The app is partitioned by **business domain**, not by technical layer. Each domain owns its services, data, endpoints, admin UI, and frontend touchpoints.

Domains (Phase 1 launch scope):

| Domain | Phase | Notes |
|--------|-------|-------|
| Edition | launch | Scheduled offerings, sessions |
| Enrollment | launch | Registration lifecycle |
| Invoicing | launch | Quotes, vouchers, PDF |
| Attendance | launch | Session presence |
| Questionnaire | launch | Intake/evaluation forms |
| Notification | launch | FluentCRM bridge |
| User | launch | Profile types, lifecycle |
| Mail | launch | Mail bridge |
| Audit | launch | Audit trail |
| Membership | launch | Membership service |
| Reporting | launch | Annual report (Jaarrapport) |
| Assistant | launch | AI assistant abilities |
| Trajectory | post-launch | Tagged `status: post-launch` |
| PartnerAPI | post-launch | Tagged `status: post-launch` |

Integrations (cross-cutting): LearnDash, FluentCRM, FluentForms, DOMPDF, WordPress core.

## JSON Schema (sketch)

```json
{
  "meta": {
    "generated": "2026-05-16",
    "version": "phase-1",
    "source": "stride-core mu-plugin + stridence theme"
  },
  "domains": [
    {
      "id": "enrollment",
      "name": "Enrollment",
      "status": "launch",
      "purpose": "Users register for editions; lifecycle from interest → confirmed → cancelled.",
      "services": [
        {
          "class": "Stride\\Modules\\Enrollment\\EnrollmentService",
          "file": "web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php",
          "responsibilities": ["Create registration", "Cancel registration", "Map user meta on enrollment"]
        }
      ],
      "data": {
        "cpts": [],
        "tables": ["wp_vad_registrations"],
        "user_meta": ["organisation", "department", "billing_*", "..."],
        "taxonomies": []
      },
      "endpoints": {
        "rest": [],
        "ajax": ["stride_update_profile"]
      },
      "admin": ["dashboard.user-detail.enrollments-tab"],
      "frontend": ["stride_enrollment shortcode"],
      "depends_on": ["edition", "user", "invoicing", "learndash"]
    }
  ],
  "integrations": [
    { "id": "learndash", "name": "LearnDash", "adapter": "Stride\\Integrations\\LearnDash\\LearnDashService", "purpose": "LMS content + access" }
  ]
}
```

Each domain node carries enough metadata that the HTML viewer can render its sidebar without further lookup.

## HTML Viewer

- Single file, no build step.
- **Cytoscape.js** via CDN (~150KB) with the **dagre** hierarchical layout plugin.
- Layout: top-to-bottom, layered. Root → Domains → (services / data / endpoints / admin / frontend).
- Left pane: graph. Right pane: sidebar (collapsed by default).
- Click a node → sidebar shows: purpose, services with file paths, data, endpoints, admin/frontend touchpoints, dependencies.
- Top bar: search box (filters/highlights nodes by name), domain filter (toggle post-launch on/off).
- Cross-domain dependencies rendered as dashed edges between domain nodes.
- All Stride-branded styling — no generic Cytoscape defaults. Dark backdrop, clear typography.

## Data Gathering Plan

Three parallel Explore subagents scan `web/app/mu-plugins/stride-core/`:

1. **Modules + services**
   - Walk `Modules/*/` and `Admin/`, `Handlers/`, `Integrations/`.
   - For each service: class FQCN, file path, `metadata()` priority, public-method-derived responsibilities.
   - Output: `{ "<domain>": { "services": [...] } }`

2. **Data layer**
   - Grep `register_post_type(` for CPTs.
   - Grep `register_taxonomy(` for taxonomies.
   - Grep `$wpdb->prefix` / custom-table refs.
   - Grep `update_user_meta` / `get_user_meta` for canonical user meta keys.
   - Output: `{ "<domain>": { "cpts": [...], "tables": [...], "user_meta": [...], "taxonomies": [...] } }`

3. **Endpoints + admin + frontend**
   - Grep `register_rest_route(` for REST routes.
   - Grep `add_action('wp_ajax_` for AJAX actions.
   - Grep `add_menu_page` / `add_submenu_page` for admin pages.
   - Grep `add_shortcode(` for shortcodes (incl. theme `services/frontend/shortcodes/`).
   - Output: `{ "<domain>": { "rest": [...], "ajax": [...], "admin": [...], "frontend": [...] } }`

Each scanner returns concise JSON (under ~300 lines). Controller merges into final `stride-architecture.json` and writes the HTML viewer.

## Scope Guards

- **Phase 1 launch focus.** Trajectories, Partner API, LTI included but tagged `"status": "post-launch"` per `project_production_priorities` memory. Viewer dims them by default; filter toggle reveals.
- **Backend-first.** Theme touchpoints (shortcodes, templates) are referenced, not exhaustively cataloged. WordPress core internals out of scope.
- **No live introspection.** Pure static analysis of source code at HEAD. The JSON is a snapshot; regenerating is a manual rerun.

## Out of Scope

- ERD-style detailed data model (covered separately if needed).
- Sequence/flow diagrams.
- Per-method docs (responsibilities are summarised).
- CI integration / auto-regeneration on commit.
