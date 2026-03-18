# Netdust LTI — Unified Settings Page Design

**Date:** 2026-03-02
**Status:** Approved

## Overview

Replace the 5 scattered LTI admin pages with a single Alpine.js-powered settings page. Clean, professional design matching the Stride admin dashboard aesthetic. Includes inline CRUD for Platforms/Tools/Resources and a How-To documentation section.

## Current State (to be replaced)

| Page | Location | Purpose |
|------|----------|---------|
| Netdust LTI | `Settings > Netdust LTI` | Endpoint URLs, links to CPT screens |
| LTI Launch Test | `Settings > LTI Launch Test` | Test launch form |
| LTI Platforms | `Settings > LTI Platforms` | CPT list/edit (Data Manager auto-UI) |
| LTI Tools | `Settings > LTI Tools` | CPT list/edit (Data Manager auto-UI) |
| LTI Resources | `Settings > LTI Resources` | CPT list/edit (Data Manager auto-UI) |

## Architecture

### Single Page, Six Tabs

1. **Dashboard** — Status overview, endpoint URLs with copy buttons, key status
2. **Platforms** — CRUD table for `lti_platform` CPT (incoming LMS connections)
3. **Tools** — CRUD table for `lti_tool` CPT + contextual "Test Launch" button per row
4. **Resources** — CRUD table for `lti_resource` CPT + "Launch" button per row
5. **Logs** — Launch logs and grade passback logs
6. **How-To** — Setup guide + endpoint reference documentation

### Data Flow

- **Dashboard tab**: Static PHP-rendered data (endpoints, key status)
- **CRUD tabs**: `wp.apiFetch()` → `/wp-json/wp/v2/lti_platform|lti_tool|lti_resource`
- **Logs tab**: Custom REST endpoint → reads log files from `WP_CONTENT_DIR/logs/`
- **How-To tab**: Static HTML content (server-rendered)

CPTs keep `show_in_rest: true` (already set by Data Manager). Meta fields exposed via `register_post_meta()` with `show_in_rest: true`.

### Design Language

Matches Stride admin dashboard:
- CSS variables: `--stride-bg`, `--stride-card`, `--stride-primary`, etc.
- Card-based layout, clean header with tab navigation
- Same button styles, stat cards, table patterns
- Full-screen app feel (hides WP admin bar clutter)

## UI Layout

```
┌─────────────────────────────────────────────────────┐
│  Netdust LTI                           [WP Admin →] │
│  ─────────────────────────────────────────────────── │
│  Dashboard │ Platforms │ Tools │ Resources │ Logs │ ? │
├─────────────────────────────────────────────────────┤
│                                                     │
│  [Tab content area]                                 │
│                                                     │
└─────────────────────────────────────────────────────┘
```

## Tab Details

### Dashboard

- **Status cards**: Keys generated (yes/no + kid), platform count, tool count, resource count
- **Tool Provider Endpoints**: OIDC Login, Launch, JWKS, Deep Link, JSON Config, XML Config, Dynamic Registration — all with copy buttons
- **Platform Endpoints**: Issuer, Auth, JWKS, AGS, Deep Link Return — all with copy buttons

### CRUD Tabs (Platforms, Tools, Resources)

Each follows the same pattern:
- **List view**: Clean table with status badges, action buttons
- **Add/Edit**: Slide-out panel with grouped fields matching existing field groups
- **Delete**: Confirmation dialog
- **Alpine state**: `items[]`, `editing: null`, `loading: false`

**Platforms fields** (grouped):
| Group | Fields |
|-------|--------|
| Credentials | platform_id (URL), client_id, deployment_id |
| Endpoints | auth_endpoint, token_endpoint, jwks_endpoint |
| Keys | rsa_key (PEM textarea), kid |
| Settings | enabled (toggle) |
| Roles | role_instructor, role_learner |

**Tools fields** (grouped):
| Group | Fields |
|-------|--------|
| Credentials | client_id, deployment_id |
| Endpoints | launch_url, oidc_url, jwks_url |
| Keys | public_key (PEM textarea) |

Extra actions: "Test Launch" button per row → opens in new tab

**Resources fields** (grouped):
| Group | Fields |
|-------|--------|
| Resource | tool_id (select from tools), launch_url, course_id |
| Extra | description, custom_params (JSON textarea) |

Extra actions: "Launch" button per row → opens in new tab

### Logs Tab

- Sub-tabs: Launches / Grade Passbacks
- Reads log files from `WP_CONTENT_DIR/logs/`
- Filterable by date

### How-To Tab

Static documentation sections:
1. **Quick Start** — 3-step: keys → platform → test
2. **Registering as a Tool Provider** — Endpoint URLs to give external LMS
3. **Adding External Tools** — Configure outbound tool connection
4. **Endpoint Reference** — Full table with descriptions
5. **Grade Passback** — Per-course settings via metabox
6. **Troubleshooting** — Common issues

## File Structure

```
web/app/plugins/netdust-lti/
├── src/Admin/
│   ├── SettingsPage.php           # NEW: single page controller
│   └── CourseSettingsMetabox.php   # Unchanged
├── assets/
│   ├── css/lti-admin.css          # NEW: Stride-style CSS
│   └── js/lti-admin.js            # NEW: Alpine.js app
└── templates/admin/
    ├── settings.php               # NEW: main template (Alpine shell)
    └── howto.php                  # NEW: documentation content
```

### Files to Remove After Migration

- `src/Admin/AdminPage.php`
- `src/Admin/LaunchTestPage.php`
- `templates/admin/settings-page.php`
- `templates/admin/launch-test.php`
- `templates/admin/logs.php`

## Technical Notes

- CPT `show_in_menu` changes from `options-general.php` to `false` (hidden from WP menu, managed inline)
- Meta fields need `register_post_meta()` with `show_in_rest: true` for REST API access
- Alpine.js loaded via `wp_enqueue_script` with `wp-api-fetch` dependency
- Nonce handled automatically by `wp.apiFetch` (uses `X-WP-Nonce` header)
- Launch test forms still POST to `/lti/platform/launch` with `target="_blank"`
