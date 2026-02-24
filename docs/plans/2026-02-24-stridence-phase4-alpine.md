# Stridence Phase 4: Alpine Components

> **For Claude:** Use superpowers:executing-plans to implement this plan.
> **Prerequisite:** Complete Phases 1-3 first.

**Goal:** Add Alpine.js components for interactivity.

**Location:** `web/app/themes/stridence/src/main.js`

**Pattern:** Components registered via `Alpine.data()`. Server renders complete HTML, Alpine enhances.

---

## Task 4.1: Review existing Alpine setup

**File:** `src/main.js`

Check current state. Should already have:
- Alpine import and start
- `toastStore()` - notification system
- `mobileMenu()` - header mobile menu
- `dropdown()` - generic dropdown
- `expandable()` - accordion/expand

If missing, add them.

---

## Task 4.2: courseDetailTabs() component

**Purpose:** Scroll-spy for course detail page tabs.

**State:**
- `activeTab` - Current tab ID (overzicht, programma, sprekers, praktisch)

**Methods:**
- `scrollTo(id)` - Smooth scroll to section
- `onScroll()` - Update activeTab based on scroll position

**Init:**
- Listen to scroll event (debounced)
- Read initial hash from URL

**Usage:**
```html
<div x-data="courseDetailTabs()">
  <a :class="{ 'tab-active': activeTab === 'overzicht' }" @click.prevent="scrollTo('overzicht')">
```

---

## Task 4.3: courseCatalog() component

**Purpose:** Filter state for course archive (optional enhancement).

**State:**
- `domain` - Selected domain slug
- `format` - Selected format
- `location` - Selected location

**Methods:**
- `applyFilters()` - Update URL and reload (or filter client-side)
- `clearFilters()` - Reset all filters

**Note:** Server already handles filtering via URL params. This component is optional for smoother UX without page reload.

---

## Task 4.4: profileForms() component

**Purpose:** Form submission with loading state and feedback.

**State:**
- `loading` - Boolean
- `errors` - Object of field errors
- `success` - Boolean

**Methods:**
- `submitForm(event)` - Collect form data, POST via ntdstAPI, show toast on success/error
- `clearErrors()` - Reset error state

**Usage:**
```html
<form @submit.prevent="submitForm($event)" x-data="profileForms()">
  <input :class="{ 'input-error': errors.phone }" />
  <span x-show="errors.phone" x-text="errors.phone" class="text-error text-sm"></span>
  <button :disabled="loading">...</button>
</form>
```

---

## Task 4.5: dashboardTabs() component

**Purpose:** Tab switching with URL state.

**State:**
- `activeTab` - Current tab from URL param

**Methods:**
- `switchTab(tab)` - Update URL via history.pushState, trigger content swap
- `init()` - Read tab from URL on load

**Note:** Since tabs are server-rendered, this mainly handles history state for back/forward navigation.

---

## Task 4.6: confirmAction() component

**Purpose:** Confirmation dialog before destructive actions.

**State:**
- `open` - Boolean
- `message` - Confirmation text
- `onConfirm` - Callback function

**Methods:**
- `show(message, callback)` - Open dialog
- `confirm()` - Execute callback, close
- `cancel()` - Close without action

**Usage:**
```html
<button @click="$store.confirm.show('Weet je zeker dat je wilt annuleren?', () => cancelRegistration(id))">
  Annuleren
</button>
```

---

## Task 4.7: Update ntdstAPI helper

**Purpose:** Standardized AJAX calls.

**Methods:**
```javascript
window.ntdstAPI = {
  async post(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', window.strideConfig.nonce);
    Object.entries(data).forEach(([k, v]) => formData.append(k, v));

    const res = await fetch(window.strideConfig.ajaxUrl, {
      method: 'POST',
      body: formData,
    });
    return res.json();
  },

  async get(action, params = {}) {
    const url = new URL(window.strideConfig.ajaxUrl);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

    const res = await fetch(url);
    return res.json();
  }
};
```

---

## Task 4.8: Add CSS for tab states

**File:** `src/css/components.css`

Add if missing:
```css
.tab-link {
  @apply px-4 py-3 border-b-2 border-transparent text-sm font-medium text-text-muted transition-colors;
}
.tab-link:hover {
  @apply text-text border-border;
}
.tab-active {
  @apply border-primary text-primary !important;
}
```

---

## Task 4.9: Build and test

```bash
cd web/app/themes/stridence
npm run build
```

Verify:
- `dist/` contains built assets
- `dist/.vite/manifest.json` exists
- No build errors

---

## Commit

```bash
git add web/app/themes/stridence/src/
git commit -m "feat(stridence): add Alpine components for interactivity"
```
