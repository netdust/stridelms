# Stridence Phase 3: Dashboard

> **For Claude:** Use superpowers:executing-plans to implement this plan.
> **Prerequisite:** Complete Phase 1 (Partials) and Phase 2 (Pages) first.

**Goal:** Create the user dashboard with tabbed interface.

**Pattern:** URL state via `?tab=xxx`. Server renders active tab content. Alpine enhances tab switching.

---

## Task 3.1: Create dashboard directory

```bash
mkdir -p web/app/themes/stridence/templates/dashboard
git add . && git commit -m "chore: create dashboard directory"
```

---

## Task 3.2: page-mijn-account.php (Dashboard Shell)

**File:** `page-mijn-account.php`

**Behavior:**
- Requires login (redirect to login if not authenticated)
- Read `$_GET['tab']` (default: inschrijvingen)
- Valid tabs: inschrijvingen, offertes, certificaten, profiel

**Structure:**
1. Desktop: Left sidebar rail (1/4) + content area (3/4)
2. Mobile: Bottom tab bar (fixed, z-40)

**Sidebar/Bottom Nav:**
- Inschrijvingen (calendar icon)
- Offertes (file-text icon)
- Certificaten (award icon)
- Profiel (user icon)

**Tab loading:** Use `get_template_part("templates/dashboard/tab-{$tab}")` for active tab content.

---

## Task 3.3: templates/dashboard/tab-inschrijvingen.php

**File:** `templates/dashboard/tab-inschrijvingen.php`

**Sections:**

1. **Komende sessies** (next 2-3 upcoming)
   - session-row.php for each
   - Link to full edition

2. **Actieve inschrijvingen**
   - Expandable cards (Alpine `expandable()`)
   - Each card: edition title, date, venue, status badge
   - Expanded: progress-bar.php, session list, action links (annuleren)

3. **Afgerond** (collapsed by default)
   - Simple list with completion date and certificate link

4. **Geannuleerd** (collapsed, only if exists)
   - Muted text

**Data stub:**
```php
// TODO: Wire up RegistrationRepository
// $registrations = ntdst_get(RegistrationRepository::class)->getForUser($userId);
$registrations = []; // Stub
```

---

## Task 3.4: templates/dashboard/tab-offertes.php

**File:** `templates/dashboard/tab-offertes.php`

**Structure:**
- List of quotes with status badge
- Each quote: date, total amount, line items count, status
- Actions: PDF download, view details
- Older quotes collapsed by default

**Data stub:**
```php
// TODO: Wire up QuoteService
// $quotes = ntdst_get(QuoteService::class)->getForUser($userId);
$quotes = []; // Stub
```

---

## Task 3.5: templates/dashboard/tab-certificaten.php

**File:** `templates/dashboard/tab-certificaten.php`

**Structure:**
- Summary card: total completed courses, total contact hours
- List of completed courses with certificate download button
- Each: course title, completion date, hours, download link

**Data stub:**
```php
// TODO: Wire up CourseService for certificates
// $completed = ntdst_get(CourseService::class)->getCompletedForUser($userId);
$completed = []; // Stub
```

---

## Task 3.6: templates/dashboard/tab-profiel.php

**File:** `templates/dashboard/tab-profiel.php`

**Structure:** Three independent forms (Alpine `profileForms()`)

1. **Persoonlijke gegevens**
   - Voornaam, achternaam, email (readonly), telefoon
   - Submit via AJAX to `stride_update_profile`

2. **Facturatiegegevens**
   - Bedrijfsnaam, BTW-nummer, adres, postcode, stad
   - Submit via AJAX

3. **Wachtwoord wijzigen**
   - Huidig wachtwoord, nieuw wachtwoord, bevestig wachtwoord
   - Submit via AJAX to `stride_change_password`

**Form pattern:**
```html
<form @submit.prevent="submitForm($event)" x-data="profileForms()">
  <!-- inputs with input-text class -->
  <button type="submit" class="btn-primary" :disabled="loading">
    <span x-show="!loading">Opslaan</span>
    <span x-show="loading">Bezig...</span>
  </button>
</form>
```

---

## Task 3.7: Dashboard navigation partial

**File:** `templates/dashboard/nav-sidebar.php`

**Args:**
- `current_tab` - Active tab slug

**Structure:**
- Vertical nav with icon + label for each tab
- Active state: bg-primary/10 text-primary
- Link format: `?tab=xxx`

---

## Task 3.8: Dashboard mobile nav partial

**File:** `templates/dashboard/nav-mobile.php`

**Args:**
- `current_tab` - Active tab slug

**Structure:**
- Fixed bottom bar with 4 icon buttons
- Active state: text-primary
- Labels hidden on small screens, visible on sm+

---

## Commit

After all dashboard files complete:
```bash
git add web/app/themes/stridence/
git commit -m "feat(stridence): add user dashboard with tabs"
```
