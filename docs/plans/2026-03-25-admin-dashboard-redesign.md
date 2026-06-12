# Admin Dashboard Redesign — Design Spec

**Date:** 2026-03-25
**Status:** Draft
**Scope:** Full frontend rewrite of Stride admin dashboard + new backend features

---

## Overview

Redesign the Stride admin dashboard from a basic stats overview into a **command center** for daily admin operations. The dashboard is a power layer on top of WordPress admin — it surfaces what matters, provides quick actions, and links to WP edit pages for CRUD. It does not replace WP admin.

**Target user:** Small admin team (1-3 people) managing all aspects of the LMS — editions, enrollments, quotes, attendance, user support.

---

## Architecture

### Frontend

Full rewrite of 3 files (same Alpine.js SPA pattern as today):

| File | Purpose |
|------|---------|
| `templates/admin/dashboard.php` | Alpine.js app template — all 5 views |
| `assets/css/admin-dashboard.css` | Soft Violet design system |
| `assets/js/admin-dashboard.js` | Alpine app logic, API calls, state |

Full-screen mode preserved (hides WP admin bar, sidebar collapsed). Hash-based routing: `#/`, `#/edities`, `#/offertes`, `#/trajecten`, `#/gebruikers`.

### Backend

Keep existing `AdminAPIController` endpoints. Add new endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/admin/action-queue` | GET | Action items from configurable rules |
| `/admin/users/search` | GET | User search by name/email/organisation |
| `/admin/users/{id}/detail` | GET | User detail: enrollments, quotes, attendance, audit trail |
| `/admin/users/{id}/impersonate` | POST | Switch session to target user |
| `/admin/alert-rules` | GET | Current alert rule configuration |
| `/admin/alert-rules` | POST | Update alert rule thresholds |
| `/admin/health-checks` | GET | System health indicators |

Alert rule settings stored via `StrideSettingsService`, new "Meldingen" tab in existing settings page.

---

## Views

### 1. Header Bar (persistent across all views)

- **Left:** "Stride" text + horizontal tab navigation: Dashboard, Edities, Offertes, Trajecten, Gebruikers
- **Right:** Notification bell (unread count from ntdst-audit), user avatar (initials circle) + name
- Active tab highlighted with `--primary` underline

### 2. Dashboard Home (`#/`)

**Two-column layout** — primary left (60%), secondary right (40%).

#### Greeting + KPI Row (full width)

- "Hi, [first name]" with current date (Dutch formatted)
- 4-5 KPI cards in a horizontal row:
  - **Komende edities** — count of upcoming editions → links to Edities tab
  - **Actieve inschrijvingen** — confirmed registration count
  - **Openstaande offertes** — total value of draft/sent quotes (formatted as €)
  - **Sessies vandaag** — today's session count
  - **Acties nodig** — action queue count, red when > 0

#### Left Column (primary)

**Action Queue — "Acties nodig"**
- Prioritized list of items requiring admin attention
- Each item: colored priority dot (red/amber/blue), description text, "Bekijk →" link to relevant WP edit page
- Dismissable per admin (user meta, reappears if condition persists next day)
- Fed by configurable rules (see Section: Action Queue Rules)
- Health check indicators at bottom (green/amber dots for registration flow and mail delivery)

**Komende sessies — upcoming session table**
- Flat table showing upcoming sessions (+ sessions up to 3 days past)
- Columns: Edition name, Date, Time, Venue, Capacity (X/Y), Status badge
- Today rows highlighted with subtle violet background
- Click edition name → WP edition edit page
- "Alles bekijken →" link to Edities tab

#### Right Column (secondary)

**Snelle acties — quick action buttons**
- \+ Nieuwe editie → WP new edition page
- \+ Nieuw traject → WP new trajectory page
- Offertes beheren → Offertes tab
- Inschrijvingen exporteren → triggers CSV export

**Recente activiteit — audit log feed**
- Last 10 events from ntdst-audit
- Avatar (initials circle) + user name + action description + relative time
- Event types: registrations, completions, quote sends, attendance marks
- "Meer bekijken →" link (scrolls or expands)

**Gebruiker zoeken — compact search**
- Type-ahead search by name or email (debounced 300ms)
- Mini result cards: name, email, registration count
- Click → navigates to Gebruikers tab with user detail open

### 3. Edities Tab (`#/edities`)

**Filters bar (full width):**
- Search input (by edition title)
- Status dropdown: Open, Vol, Geannuleerd, Afgelopen
- Date range picker (Flatpickr, from-to)
- Course/category dropdown
- Reset filters button

**Flat session table:**
- Default: shows upcoming editions + up to 3 days past. Date filter reveals older editions.
- Each session = one row. An edition with 3 sessions appears 3 times.

| Column | Content |
|--------|---------|
| Editie | Edition title |
| Sessie | Session label (e.g., "Dag 1") |
| Datum | Session date (full Dutch format) |
| Tijd | Start–end time |
| Locatie | Venue |
| Capaciteit | Registered/max (e.g., "12/15") |
| Status | Badge (Open, Vol, Geannuleerd, etc.) |
| Acties | ✏️ link to WP edition edit page |

- Today rows: subtle violet highlight
- Past rows: dimmed text
- "Bijna vol" badge when capacity exceeds configurable threshold

**Edition slide-over (600px, right):**
- Tab 1: **Studenten** — registered students list (name, email, status badge)
- Tab 2: **Aanwezigheid** — attendance grid (students × sessions, toggle present/absent/excused)
- Tab 3: **Info** — edition details (dates, venue, price, status)
- "Bewerk in WP →" link to edition edit page

**Pagination:** Previous/Next with page info

### 4. Offertes Tab (`#/offertes`)

**Filters bar:**
- Search input (by user name/email)
- Status dropdown: Concept, Verzonden, Geëxporteerd
- Edition dropdown
- Reset button

**Quotes table:**

| Column | Content |
|--------|---------|
| Offerte # | Quote number (monospace) |
| Klant | Customer name |
| E-mail | Customer email |
| Editie | Edition title |
| Datum | Created date |
| Bedrag | Total amount (formatted €) |
| Status | Badge |
| Acties | ✏️ WP edit, 📧 quick-send (Concept/Verzonden only) |

**Quote slide-over (600px, right):**
- Tab 1: **Details** — customer info, billing, edition, dates, voucher, totals (subtotal, BTW 21%, total)
- Tab 2: **Items** — line items with title, type, price
- Status actions: send email, export
- "Bewerk in WP →" link

**Pagination:** Previous/Next

### 5. Trajecten Tab (`#/trajecten`)

**Filters bar:**
- Search input (by trajectory name)
- Status dropdown: Open, Gesloten, Vol, Concept
- Reset button

**Trajectories table:**

| Column | Content |
|--------|---------|
| Traject | Trajectory name |
| Modus | Badge (Cohort/Zelfgestuurd) |
| Cursussen | Course count |
| Ingeschreven | Count/capacity |
| Prijs | Formatted € |
| Deadline | Enrollment deadline |
| Status | Badge |
| Acties | ✏️ WP edit |

**Trajectory slide-over (600px, right):**
- Tab 1: **Details** — status, mode, capacity, pricing (member/non-member), deadlines
- Tab 2: **Cursussen** — course list (required/optional)
- Tab 3: **Studenten** — enrolled users (name, email, status, date)
- "Bewerk in WP →" link

**Pagination:** Previous/Next

### 6. Gebruikers Tab (`#/gebruikers`) — NEW

**Search bar (prominent, full width):**
- Large input: "Zoek op naam, e-mail of organisatie..."
- Type-ahead with debounced 300ms search
- Results: name, email, organisation, registration count

**User detail view (two-column, shown when user selected):**

#### Left column (60%)

**User header card:**
- Avatar (initials), full name, email, phone
- Organisation, department
- Profile type badge
- Buttons: "Bekijk als gebruiker" (impersonation), "Bewerk profiel →" (WP user edit)

**Inschrijvingen — registration table:**
- Edition name, date, status badge, quote link
- Click edition → WP edit page
- All statuses shown (confirmed, completed, cancelled, interest)

**Offertes — quote table:**
- Quote #, edition, amount, status
- Click → WP quote edit page

#### Right column (40%)

**Audit timeline:**
- Chronological feed from ntdst-audit for this user
- Events: registrations, attendance, completions, emails sent, logins
- Timestamped, color-coded by event type
- Scrollable, last 50 entries

**Aanwezigheid — attendance summary:**
- Per edition: sessions attended / total, hours completed
- Compact view, links to edition detail

#### Impersonation ("Bekijk als gebruiker")

- POST to `/admin/users/{id}/impersonate`
- Stores admin's original user ID in a secure cookie
- Switches current WordPress session to target user
- WP admin bar shows "Terug naar admin" button to switch back
- All actions during impersonation logged in audit trail
- Requires `manage_options` capability

---

## Action Queue Rules (Configurable)

Rules stored in `stride_settings` option. Admin configures via Settings → Meldingen tab.

| Rule | Default Threshold | Configurable | Priority |
|------|-------------------|-------------|----------|
| Edition nearing capacity | 80% full | % threshold | Amber |
| Session approaching, no attendance | 1 day before | Days before | Amber |
| Quote not sent (stale draft) | 7 days as draft | Days as draft | Amber |
| Pending registration approval | Immediate (always) | On/off only | Red |
| Edition starting soon | 3 days before | Days before | Blue |
| Post-course tasks incomplete | 7 days after last session | Days after | Amber |

**Priority display:**
- 🔴 Red: requires immediate action (approvals)
- 🟠 Amber: needs attention soon (capacity, stale quotes, overdue tasks)
- 🔵 Blue: informational (upcoming editions)

**Dismissal:** stored in user meta as array of `[rule_type, subject_id, dismissed_date]`. Rule reappears if condition still true the next calendar day.

**Health checks** (bottom of action queue):
- Registration flow: green if last successful registration < 24h ago OR no editions currently open
- Mail delivery: green if last successful mail send via Fluent SMTP is recent
- Simple dot indicator, not an actionable item

---

## Settings: Meldingen Tab (new)

Added to existing Stride settings page alongside General, Bedrijf, Profieltypes.

**UI:** Toggle switch per rule + threshold input field. Same Alpine.js settings pattern as existing tabs.

```
☑ Editie bijna vol          [80] %
☑ Sessie nadert             [1] dag(en) voor aanvang
☑ Offerte niet verzonden    [7] dag(en) als concept
☑ Goedkeuring nodig         (altijd actief)
☑ Editie start binnenkort   [3] dag(en) voor start
☑ Taken niet afgerond       [7] dag(en) na laatste sessie
```

---

## Visual Design System: Soft Violet

### Color Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--primary` | `#7c3aed` | Buttons, links, active states, accents |
| `--primary-light` | `#ede9fe` | Card borders, hover backgrounds, avatar bg |
| `--primary-bg` | `#f8f7fc` | Page background |
| `--primary-subtle` | `#f5f3ff` | Row dividers, input backgrounds |
| `--text-primary` | `#1e1b3a` | Headings, bold values |
| `--text-secondary` | `#475569` | Body text, table cells |
| `--text-muted` | `#a78bfa` | Labels, timestamps, secondary info |
| `--success` | `#22c55e` | Open status, positive trends |
| `--warning` | `#f59e0b` | Attention items, "bijna vol" |
| `--danger` | `#ef4444` | Urgent items, action count |
| `--surface` | `#ffffff` | Card backgrounds |
| `--border` | `#ede9fe` | Card borders, table dividers |

### Components

- **Cards:** white bg, 1px `--border`, 12px border-radius, 16-20px padding
- **KPI cards:** small uppercase label (`--text-muted`), large number (`--text-primary`), optional trend indicator
- **Tables:** no outer border, subtle row dividers (`--primary-subtle`), hover row highlight
- **Badges:** pill-shaped, colored bg + text per status
- **Buttons:** primary = `--primary` bg white text; ghost = `--primary-light` bg `--primary` text
- **Slide-overs:** 600px wide, right-aligned, white bg, subtle shadow, overlay backdrop
- **Avatars:** 32px circle, `--primary-light` bg, initials in `--primary`
- **Priority dots:** 6px circles (red/amber/blue)
- **Inputs:** `--primary-subtle` bg, `--border` border, focus ring `--primary`

### Typography

- Font: `-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`
- Greeting: 24px / 600 weight
- Card titles: 13px / 600 weight
- Body/table text: 13px / 400 weight
- Labels: 11px / uppercase / letter-spacing 0.5px
- KPI values: 24px / 700 weight

### Spacing

- Base unit: 8px
- Card gap: 16px
- Card padding: 20px
- Section gap: 24px

### Status Badges

| Status | Background | Text |
|--------|-----------|------|
| Open | `#dcfce7` | `#166534` |
| Vol / Bijna vol | `#fef3c7` | `#92400e` |
| Geannuleerd | `#fee2e2` | `#991b1b` |
| Afgelopen | `#f3f4f6` | `#6b7280` |
| Concept | `#f3f4f6` | `#6b7280` |
| Verzonden | `#dbeafe` | `#1e40af` |
| Geëxporteerd | `#dcfce7` | `#166534` |
| Bevestigd | `#dcfce7` | `#166534` |
| In afwachting | `#fef3c7` | `#92400e` |

---

## Data Sources

All data comes from existing services. No new data models needed.

| Widget/View | Data Source | Existing? |
|-------------|-----------|-----------|
| KPI cards | `AdminAPIController::getStats()` | Yes |
| Action queue | New `ActionQueueService` + rule engine | **New** |
| Session table | `AdminAPIController::getEditions()` (agenda view) | Yes (modify to default 3-day lookback) |
| Activity feed | `NotificationService::getNotifications()` / audit log | Yes |
| User search | WordPress `WP_User_Query` | Yes (new endpoint) |
| User detail | `RegistrationRepository`, `QuoteService`, audit log | Yes (new aggregation endpoint) |
| User impersonation | New impersonation handler | **New** |
| Health checks | Mail + registration status queries | **New** |
| Alert rules | `StrideSettingsService` option storage | Yes (new settings tab) |
| Editions table | `AdminAPIController::getEditions()` | Yes |
| Quotes table | `AdminAPIController::getQuotes()` | Yes |
| Trajectories table | `AdminAPIController::getTrajectories()` | Yes |

### New Backend Components

| Component | Type | Purpose |
|-----------|------|---------|
| `ActionQueueService` | Plain class | Evaluates configured rules against current data, returns prioritized action items |
| `UserTrackingController` | API methods in `AdminAPIController` | User search, detail aggregation, impersonation |
| `HealthCheckService` | Plain class | Checks registration flow and mail delivery status |
| Settings tab template | Template | `templates/admin/settings/tab-notifications.php` |

---

## What Stays the Same

- `AdminDashboardService` — registers menu, injects assets, renders template (minor updates for new dependencies)
- `AdminAPIController` — existing endpoints stay, new ones added
- All WP admin edit pages — editions, quotes, trajectories, users (untouched)
- All existing services and repositories (consumed, not modified)
- Full-screen mode with WordPress chrome hidden
- Alpine.js + Flatpickr stack
- Hash-based routing pattern
- Slide-over detail panels

## What Gets Replaced

- `templates/admin/dashboard.php` — full rewrite (new layout, new views)
- `assets/css/admin-dashboard.css` — full rewrite (Soft Violet design system)
- `assets/js/admin-dashboard.js` — full rewrite (new state, new API calls, 5 views)

## What Gets Added

- `ActionQueueService` class
- `HealthCheckService` class
- New API endpoints in `AdminAPIController`
- Settings tab: `templates/admin/settings/tab-notifications.php`
- Settings registration in `StrideSettingsService`
- `AdminActivityMapper` class (admin-perspective audit log strings)

---

## UX Behaviors & Edge Cases

### Loading & Error States

- **KPI cards:** Show "—" placeholder while loading, independent per card. If a single API call fails, that card shows "—" with a subtle error indicator; others render normally.
- **Action queue:** Skeleton lines while loading. On error: "Kan acties niet laden" message with retry link.
- **Tables (all views):** Skeleton rows while loading. On error: full-width error message with retry.
- **Slide-overs:** Spinner in panel while loading detail data. On error: error message inside slide-over.

### Empty States

| View | Empty State Message |
|------|-------------------|
| Action queue | "Geen acties nodig — alles is in orde." (green checkmark icon) |
| Komende sessies (dashboard) | "Geen sessies gepland voor de komende dagen." |
| Recente activiteit | "Nog geen activiteit." |
| Edities tab (filtered) | "Geen edities gevonden voor deze filters." |
| Offertes tab (filtered) | "Geen offertes gevonden." |
| Trajecten tab (filtered) | "Geen trajecten gevonden." |
| Gebruikers search results | "Geen gebruikers gevonden voor '[query]'." |
| User detail — registrations | "Deze gebruiker heeft geen inschrijvingen." |
| User detail — audit trail | "Geen activiteit gevonden." |

### Slide-over Behavior

- **Close:** X button top-right + click backdrop overlay + ESC key
- **Attendance changes:** Saved immediately on toggle (optimistic update). On save failure: revert cell state, show toast "Aanwezigheid opslaan mislukt" with retry.
- **No unsaved state concept** — all mutations are immediate.
- **URL:** Slide-overs do NOT update the URL hash. Back button navigates between tabs, not slide-over open/close.

### Filter State

- Filters are in-memory Alpine state, reset when navigating between tabs.
- No filter persistence across tab switches (keeps implementation simple).

### Data Refresh

- Dashboard home data loads fresh on each tab switch to Dashboard.
- No auto-polling — admin reloads page or switches tabs to refresh.
- Action queue "Acties nodig" count in KPI row comes from `getStats()` endpoint (cheap count), not from the full action queue query.

### User Search Minimum

- Minimum 2 characters before search fires (both dashboard widget and Gebruikers tab).
- Below 2 chars: no API call, show hint text "Typ minimaal 2 tekens..."

### Capacity Display

- Editions with no max capacity: show registered count only (e.g., "12") — no "/max".
- "Bijna vol" badge uses the same global threshold from Settings → Meldingen.
- Editions with unlimited capacity never show "Bijna vol".

### CSV Export ("Inschrijvingen exporteren")

- Exports all confirmed registrations for upcoming editions as browser-download CSV.
- Columns: Naam, E-mail, Organisatie, Editie, Datum, Status, Offerte #
- Triggers via existing export infrastructure. Shows spinner on button while generating.

### Notification Bell

- Per-admin unread count (each admin has independent read state in user meta).
- Counts audit events of type: registration_created, registration_cancelled, quote_created, completion_completed.
- Clicking bell shows a dropdown with last 10 notifications. Clicking "Alles gelezen" marks all as read.
- Notification read state stored in user meta: `stride_last_read_notification_id`.

### Activity Feed (Admin Perspective)

- New `AdminActivityMapper` class produces admin-perspective strings from audit entries.
- Example: audit entry `registration_created` for user "Jan Peeters" on "Excel Basis" → "Jan Peeters heeft zich ingeschreven voor Excel Basis"
- Reuses the same audit log data, different display strings than the student-facing `NotificationMapper`.

---

## Impersonation — Detailed Design

### Mechanism

Uses the **User Switching** plugin pattern (well-established WordPress convention):

1. Admin clicks "Bekijk als gebruiker" → `POST /admin/users/{id}/impersonate`
2. Server validates: caller has `manage_options`, target user exists, target is not an admin
3. Server stores original admin user ID in a **server-side transient**: `stride_impersonate_{random_token}` → `{admin_user_id}`, TTL 1 hour
4. Server sets a **separate cookie** `stride_impersonate_token` with the random token (HttpOnly, Secure, SameSite=Strict, 1 hour expiry)
5. Server calls `wp_set_auth_cookie($target_user_id)` — browser now authenticated as target user
6. Server returns redirect URL (frontend homepage)
7. **Full page reload** occurs — all nonces regenerate naturally for the target user

### Return to Admin

1. WordPress hooks `admin_bar_menu` to check for `stride_impersonate_token` cookie
2. If cookie exists and transient is valid: render "Terug naar [admin name]" button in admin bar
3. **Force `show_admin_bar(true)`** during active impersonation (overrides user preference) — prevents admin from being trapped
4. Click triggers `POST /admin/impersonate/end`
5. Server validates transient, calls `wp_set_auth_cookie($original_admin_id)`, deletes transient and cookie
6. Redirects to dashboard (`/wp-admin/admin.php?page=stride-dashboard#/gebruikers`)

### Audit Logging During Impersonation

- All audit entries during impersonation include `impersonated_by: {admin_user_id}` in the event metadata
- This is achieved by hooking the audit bridge to check for the impersonation transient and inject the original admin ID
- Audit trail clearly distinguishes: "Action by User X (impersonated by Admin Y)"

### Security Constraints

- Only `manage_options` capability can impersonate
- Cannot impersonate other administrators
- Impersonation auto-expires after 1 hour (transient TTL + cookie expiry)
- If cookie is deleted: admin is logged in as target user with no return path — must log in manually. Acceptable because the transient also expires.
- Session expiry during impersonation: standard WordPress login redirect, admin logs in as themselves normally
- All impersonation start/end events logged in audit trail

### Quote Quick-Send

- Clicking 📧 shows a confirmation popover: "Offerte [Q-number] verzenden naar [email]?" with "Verzenden" / "Annuleren" buttons
- On confirm: calls existing quote send endpoint, updates status to Verzonden, refreshes table row
- On failure: toast "Offerte verzenden mislukt — probeer opnieuw"

---

## User Detail Endpoint Response Schema

`GET /admin/users/{id}/detail` returns:

```json
{
  "user": {
    "id": 42,
    "display_name": "Jan Peeters",
    "email": "jan@firma.be",
    "phone": "0471234567",
    "organisation": "Firma NV",
    "department": "IT",
    "profile_type": { "name": "Medewerker", "color": "#7c3aed" }
  },
  "registrations": [
    {
      "id": 101,
      "edition_id": 55,
      "edition_title": "Excel Basis - Maart 2026",
      "edition_date": "2026-03-25",
      "status": "confirmed",
      "quote_id": 201,
      "quote_number": "Q-2026-042",
      "created_at": "2026-03-15"
    }
  ],
  "quotes": [
    {
      "id": 201,
      "number": "Q-2026-042",
      "edition_title": "Excel Basis - Maart 2026",
      "total": 45000,
      "status": "sent",
      "created_at": "2026-03-15"
    }
  ],
  "attendance": [
    {
      "edition_id": 55,
      "edition_title": "Excel Basis - Maart 2026",
      "sessions_attended": 2,
      "sessions_total": 3,
      "hours_completed": 12.0
    }
  ],
  "audit_trail": [
    {
      "id": 500,
      "event": "registration_created",
      "description": "Ingeschreven voor Excel Basis - Maart 2026",
      "timestamp": "2026-03-15T10:30:00+01:00",
      "type_color": "#22c55e"
    }
  ],
  "audit_trail_total": 23,
  "registrations_total": 5
}
```

- `registrations`: paginated, first 20 by default. Frontend can request more via `?reg_page=2`.
- `quotes`: all quotes (typically few per user, no pagination needed).
- `attendance`: summary per edition (no pagination).
- `audit_trail`: last 50 entries. No pagination — capped for performance.
- `*_total` fields for showing counts when lists are capped.

---

## Action Queue Caching

- `ActionQueueService` results cached in a short-lived transient (`stride_action_queue`, TTL 5 minutes).
- Cache invalidated on: registration status change, quote status change, attendance mark.
- The `getStats()` endpoint returns the action queue count from a cheap count query, not from the full rule evaluation.
- Dismissal cleanup: on each action queue load, prune dismissals where `dismissed_date` is older than 30 days.

---

## Course/Category Filter

The Edities tab "Course/category dropdown" uses the existing `course_tag` taxonomy term filter from `AdminAPIController`. The dropdown is populated via `GET /admin/course-tags` (existing endpoint). This is a taxonomy term selector, not a free-text filter.
