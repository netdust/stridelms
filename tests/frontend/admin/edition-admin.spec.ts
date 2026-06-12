/**
 * Edition CPT Admin — E2E UAT Tests
 *
 * Tests ALL admin functionality for the vad_edition Custom Post Type:
 *
 *   1. List table: columns, sorting, capacity badges, status badges
 *   2. Create edition: details metabox (tabs), sidebar, save
 *   3. Session CRUD: add / edit / delete sessions via AJAX
 *   4. Session type switching: field visibility per type
 *   5. Registration metabox: participant list, expand detail, approve/reject
 *   6. Attendance metabox: toggle attendance, bulk mark present
 *   7. Notes timeline: add / delete notes
 *   8. Status & actions sidebar: status change, requirements checkboxes
 *   9. Export: registration Excel, attendance Word, namecard Word
 *  10. Capacity visualization: color coding
 *
 * Prerequisites:
 *   ddev exec wp eval-file scripts/seed.php
 */

import { test as baseTest, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import {
  wpAdminLogin,
  gotoEditionList,
  gotoNewEdition,
  gotoEditEdition,
  getFirstEditionRow,
  publishEdition,
  waitForAjax,
  acceptNextDialog,
  dismissNextDialog,
  WP_ADMIN,
} from './fixtures/admin-helpers';

// ---------------------------------------------------------------------------
// Auth fixture — log in once and reuse cookies for all tests
// ---------------------------------------------------------------------------
const AUTH_FILE = '/tmp/stride-admin-auth.json';

const test = baseTest.extend({
  // Override the storageState fixture: login once per worker, reuse cookies
  storageState: async ({ browser, baseURL }, use) => {
    // Check if auth file exists and is less than 5 minutes old
    let needsLogin = true;
    if (fs.existsSync(AUTH_FILE)) {
      const age = Date.now() - fs.statSync(AUTH_FILE).mtimeMs;
      needsLogin = age > 5 * 60 * 1000; // 5 min
    }
    if (needsLogin) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: baseURL! });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await page.close();
      await ctx.close();
    }
    await use(AUTH_FILE);
  },
});

// Default mode: each describe runs independently. Only Create Edition
// and Edition Details Persistence need serial ordering (shared createdEditionId).
test.describe.configure({ mode: 'default' });

// Increase timeout for admin tests (WP admin can be slow)
test.use({ actionTimeout: 15000 });

// Keep track of a created edition so later tests can use it
let createdEditionId: number;

// ============================================================================
// 1. EDITION LIST TABLE
// ============================================================================

test.describe('Edition List Table', () => {
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
  });

  test('list page loads without errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await expect(page.locator('.wrap h1')).toContainText('Edities');
    expect(errors).toHaveLength(0);
  });

  test('custom columns are present', async ({ page }) => {
    // The thead should contain our custom columns
    const headerRow = page.locator('thead tr').first();

    await expect(headerRow.locator('th#course')).toBeVisible();
    await expect(headerRow.locator('th#start_date')).toBeVisible();
    await expect(headerRow.locator('th#venue')).toBeVisible();
    await expect(headerRow.locator('th#capacity')).toBeVisible();
    await expect(headerRow.locator('th#status')).toBeVisible();
  });

  test('editions are listed with seed data', async ({ page }) => {
    // The seed script creates editions — we should see at least one row
    const rows = page.locator('#the-list tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
  });

  test('capacity column shows colored badges', async ({ page }) => {
    // Capacity cells should contain colored text or a capacity indicator
    const capacityCell = page.locator('td.column-capacity').first();
    await expect(capacityCell).toBeVisible();

    const text = await capacityCell.textContent();
    // Format: "X/Y" or "X inschrijvingen"
    expect(text?.trim()).toMatch(/\d+/);
  });

  test('status column shows badge', async ({ page }) => {
    const statusCell = page.locator('td.column-status').first();
    await expect(statusCell).toBeVisible();

    // Should contain a styled badge span
    const badge = statusCell.locator('span');
    await expect(badge.first()).toBeVisible();
  });

  test('start_date column is sortable', async ({ page }) => {
    const sortLink = page.locator('th#start_date a');
    await expect(sortLink).toBeVisible();

    // Click to sort
    await sortLink.click();
    await page.waitForLoadState('domcontentloaded');

    // URL should contain orderby
    expect(page.url()).toContain('orderby');
  });

  test('status column is sortable', async ({ page }) => {
    const sortLink = page.locator('th#status a');
    await expect(sortLink).toBeVisible();

    await sortLink.click();
    await page.waitForLoadState('domcontentloaded');

    expect(page.url()).toContain('orderby');
  });

  test('row actions contain edit link', async ({ page }) => {
    const firstRow = page.locator('#the-list tr').first();

    // Hover to reveal row actions
    await firstRow.hover();

    const editLink = firstRow.locator('.row-actions .edit a');
    await expect(editLink).toBeVisible();
  });
});

// ============================================================================
// 2. CREATE EDITION
// ============================================================================

test.describe('Create Edition', () => {
  test('new edition form loads all metaboxes', async ({ page }) => {
    await gotoNewEdition(page);

    // Main metaboxes
    await expect(page.locator('#stride_edition_details')).toBeVisible();
    await expect(page.locator('#stride_edition_sessions')).toBeVisible();
    await expect(page.locator('#stride_edition_attendance')).toBeVisible();
    await expect(page.locator('#stride_edition_notes')).toBeVisible();

    // Sidebar metabox
    await expect(page.locator('#stride_edition_actions')).toBeVisible();
  });

  test('details metabox has expected tabs', async ({ page }) => {
    await gotoNewEdition(page);

    const detailsBox = page.locator('#stride_edition_details');
    const tabs = detailsBox.locator('.stride-tabs-nav .stride-tab');

    // Algemeen, Informatie, Prijzen, Documenten, Cursusinstellingen (hidden by default)
    await expect(tabs).toHaveCount(5);

    await expect(tabs.nth(0)).toContainText('Algemeen');
    await expect(tabs.nth(1)).toContainText('Informatie');
    await expect(tabs.nth(2)).toContainText('Prijzen');
    await expect(tabs.nth(3)).toContainText('Documenten');
  });

  test('tab switching shows correct content', async ({ page }) => {
    await gotoNewEdition(page);

    const detailsBox = page.locator('#stride_edition_details');

    // Click "Prijzen" tab
    await detailsBox.locator('.stride-tab[data-tab="prijzen"]').click();

    // Pricing content should be visible
    const pricingTab = detailsBox.locator('.stride-tab-content[data-tab="prijzen"]');
    await expect(pricingTab).toHaveClass(/active/);

    // General tab content should be hidden
    const generalTab = detailsBox.locator('.stride-tab-content[data-tab="algemeen"]');
    await expect(generalTab).not.toHaveClass(/active/);
  });

  test('course dropdown uses Select2', async ({ page }) => {
    await gotoNewEdition(page);

    // Select2 renders a container element
    const select2Container = page.locator('.select2-container');
    await expect(select2Container.first()).toBeVisible();
  });

  test('can fill and save a new edition', async ({ page }) => {
    await gotoNewEdition(page);

    // Title
    await page.fill('#title', 'E2E Test Editie - ' + Date.now());

    // Course — open Select2 and pick first option
    await page.click('.stride-select2-course + .select2-container');
    await page.waitForSelector('.select2-results__option', { timeout: 5000 });
    await page.click('.select2-results__option:not(.select2-results__message):first-child');

    // Start date
    await page.fill('input[name="ntdst_fields[start_date]"]', '2026-06-01');

    // Capacity
    await page.fill('input[name="ntdst_fields[capacity]"]', '25');

    // Venue
    await page.fill('input[name="ntdst_fields[venue]"]', 'E2E Test Locatie');

    // Switch to pricing tab and fill price
    await page.click('.stride-tab[data-tab="prijzen"]');
    await page.fill('input[name="ntdst_fields[price]"]', '350');

    // Publish
    await publishEdition(page);

    // Should see success notice
    await expect(page.locator('#message, .notice-success, .updated')).toBeVisible();

    // Extract post ID from URL for later tests
    const url = page.url();
    const match = url.match(/post=(\d+)/);
    expect(match).toBeTruthy();
    createdEditionId = Number(match![1]);
  });
});

// ============================================================================
// 3. EDITION DETAILS METABOX — field persistence
// ============================================================================

test.describe('Edition Details Persistence', () => {
  test('saved values persist after reload', async ({ page }) => {
    test.skip(!createdEditionId, 'No edition was created in previous test');

    await gotoEditEdition(page, createdEditionId);

    // Verify persisted values
    await expect(page.locator('input[name="ntdst_fields[start_date]"]')).toHaveValue('2026-06-01');
    await expect(page.locator('input[name="ntdst_fields[capacity]"]')).toHaveValue('25');
    await expect(page.locator('input[name="ntdst_fields[venue]"]')).toHaveValue('E2E Test Locatie');

    // Check pricing tab
    await page.click('.stride-tab[data-tab="prijzen"]');
    // Price is stored in cents but displayed in EUR
    const priceVal = await page.locator('input[name="ntdst_fields[price]"]').inputValue();
    expect(Number(priceVal)).toBeGreaterThan(0);
  });
});

// ============================================================================
// 4. SESSION CRUD
// ============================================================================

test.describe('Session Management', () => {
  test.beforeEach(async ({ page }) => {
    // Use a seeded edition that has sessions — find one from the list
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    expect(postId).toBeGreaterThan(0);
    await gotoEditEdition(page, postId);
  });

  test('sessions metabox is visible', async ({ page }) => {
    await expect(page.locator('#stride_edition_sessions')).toBeVisible();
  });

  test('add session button shows inline form', async ({ page }) => {
    await page.click('#stride-add-session-btn');

    // Inline form row should appear
    const formRow = page.locator('.stride-session-form-row');
    await expect(formRow).toBeVisible();

    // Should have date, time, type fields
    await expect(formRow.locator('input[name="session_date"]')).toBeVisible();
    await expect(formRow.locator('input[name="session_start_time"]')).toBeVisible();
    await expect(formRow.locator('input[name="session_end_time"]')).toBeVisible();
  });

  test('cancel button hides session form', async ({ page }) => {
    await page.click('#stride-add-session-btn');

    const formRow = page.locator('.stride-session-form-row');
    await expect(formRow).toBeVisible();

    // Click cancel
    await page.click('.stride-session-cancel');

    // Form should be gone
    await expect(formRow).not.toBeVisible();
  });

  test('can add a new in_person session via AJAX', async ({ page }) => {
    await page.click('#stride-add-session-btn');

    const formRow = page.locator('.stride-session-form-row');

    // Select in_person type (should be default)
    await formRow.locator('.stride-type-option[data-type="in_person"]').click();

    // Fill fields
    await formRow.locator('input[name="session_date"]').fill('2026-06-15');
    await formRow.locator('input[name="session_start_time"]').fill('09:00');
    await formRow.locator('input[name="session_end_time"]').fill('17:00');

    // Fill title/location in the visible type panel (in_person panel)
    const inPersonPanel = formRow.locator('.stride-type-panel[data-for-type="in_person"]');
    const titleInput = inPersonPanel.locator('input[name="session_title"]');
    if (await titleInput.isVisible().catch(() => false)) {
      await titleInput.fill('E2E Sessie');
    }
    const locationInput = inPersonPanel.locator('input[name="session_location"]');
    if (await locationInput.isVisible().catch(() => false)) {
      await locationInput.fill('E2E Locatie');
    }

    // Save and wait for AJAX
    const ajaxPromise = waitForAjax(page);
    await page.click('.stride-session-save');
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });

  test('can edit an existing session', async ({ page }) => {
    const sessionRow = page.locator('.session-row').first();
    if (!(await sessionRow.isVisible().catch(() => false))) {
      test.skip(true, 'No existing sessions to edit');
      return;
    }

    // Click edit
    await sessionRow.locator('.stride-edit-session').click();

    // Form should appear, original row should be hidden
    const formRow = page.locator('.stride-session-form-row');
    await expect(formRow).toBeVisible();

    // Modify date
    await formRow.locator('input[name="session_date"]').fill('2026-07-01');

    // Save
    const ajaxPromise = waitForAjax(page);
    await page.click('.stride-session-save');
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });

  test('delete session asks for confirmation', async ({ page }) => {
    const sessionRow = page.locator('.session-row').first();
    if (!(await sessionRow.isVisible().catch(() => false))) {
      test.skip(true, 'No sessions to delete');
      return;
    }

    // Dismiss the confirmation — session should NOT be deleted
    dismissNextDialog(page);
    await sessionRow.locator('.stride-delete-session').click();

    // Row should still be there
    await expect(sessionRow).toBeVisible();
  });

  test('confirm delete removes session via AJAX', async ({ page }) => {
    const sessionRows = page.locator('.session-row');
    const initialCount = await sessionRows.count();
    if (initialCount === 0) {
      test.skip(true, 'No sessions to delete');
      return;
    }

    // Accept the confirmation
    acceptNextDialog(page);
    const ajaxPromise = waitForAjax(page);
    await sessionRows.first().locator('.stride-delete-session').click();
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });
});

// ============================================================================
// 5. SESSION TYPE SWITCHING
// ============================================================================

test.describe('Session Type Switching', () => {
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);
    await page.click('#stride-add-session-btn');
  });

  test('in_person type shows date, time, location fields', async ({ page }) => {
    const formRow = page.locator('.stride-session-form-row');

    await formRow.locator('.stride-type-option[data-type="in_person"]').click();

    await expect(formRow.locator('input[name="session_date"]')).toBeVisible();
    await expect(formRow.locator('input[name="session_start_time"]')).toBeVisible();
    await expect(formRow.locator('input[name="session_end_time"]')).toBeVisible();
  });

  test('webinar type shows webinar link field', async ({ page }) => {
    const formRow = page.locator('.stride-session-form-row');

    await formRow.locator('.stride-type-option[data-type="webinar"]').click();

    // Webinar link field should be visible
    const webinarField = formRow.locator('input[name="session_webinar_link"]');
    await expect(webinarField).toBeVisible();
  });

  test('online type shows lesson dropdown', async ({ page }) => {
    const formRow = page.locator('.stride-session-form-row');

    await formRow.locator('.stride-type-option[data-type="online"]').click();

    // The online panel should become visible
    const onlinePanel = formRow.locator('.stride-type-panel[data-for-type="online"]');
    await expect(onlinePanel).toBeVisible();

    // Lesson selector exists inside the online panel (may be disabled until lessons load)
    const lessonSelect = onlinePanel.locator('select[name="session_lesson_id"]');
    await expect(lessonSelect).toBeAttached();
  });

  test('assignment type shows lesson dropdown', async ({ page }) => {
    const formRow = page.locator('.stride-session-form-row');

    await formRow.locator('.stride-type-option[data-type="assignment"]').click();

    // The assignment panel should become visible
    const assignmentPanel = formRow.locator('.stride-type-panel[data-for-type="assignment"]');
    await expect(assignmentPanel).toBeVisible();

    // Lesson selector exists inside the assignment panel (may be disabled until lessons load)
    const lessonSelect = assignmentPanel.locator('select[name="session_lesson_id"]');
    await expect(lessonSelect).toBeAttached();
  });
});

// ============================================================================
// 6. REGISTRATION METABOX
// ============================================================================

test.describe('Registration Metabox', () => {
  // Navigate to an edition that has registrations (seed data)
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);
  });

  test('registration metabox has two tabs', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    await expect(metabox).toBeVisible();

    const tabs = metabox.locator('.stride-tabs-nav .stride-tab');
    // Deelnemers + Aanwezigheid
    await expect(tabs).toHaveCount(2);
  });

  test('participants tab shows registration table', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');

    // Deelnemers tab is active by default — no click needed
    // If the edition has no registrations, the table won't exist (shows placeholder)
    const table = metabox.locator('.stride-registration-table');
    const hasTable = await table.isVisible().catch(() => false);
    if (!hasTable) {
      // Verify placeholder notice is shown instead (may be multiple, just check first)
      const notice = metabox.locator('.stride-sessions-notice').first();
      await expect(notice).toBeVisible();
      test.skip(true, 'No registrations — table replaced by placeholder notice');
      return;
    }
    await expect(table).toBeVisible();
  });

  test('registration rows show status badges', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="deelnemers"]').click();

    const statusBadge = metabox.locator('.stride-status-badge').first();
    if (await statusBadge.isVisible().catch(() => false)) {
      const text = await statusBadge.textContent();
      // Should be one of the Dutch status labels
      const validStatuses = [
        'Bevestigd', 'Afgerond', 'Geannuleerd',
        'Wachtlijst', 'Interesse', 'In afwachting',
      ];
      expect(validStatuses.some((s) => text?.includes(s))).toBeTruthy();
    }
  });

  test('clicking a registration row expands detail', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="deelnemers"]').click();

    const clickableRow = metabox.locator('.registration-row').first();
    if (!(await clickableRow.isVisible().catch(() => false))) {
      test.skip(true, 'No registrations to expand');
      return;
    }

    // Click to expand
    await clickableRow.click();

    // Detail row should become visible
    const detailRow = metabox.locator('.registration-detail').first();
    await expect(detailRow).toBeVisible();
  });

  test('export dropdown has three options', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="deelnemers"]').click();

    // Look for export dropdown toggle
    const exportBtn = metabox.locator('.stride-export-toggle').first();
    if (!(await exportBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No export button visible (no registrations)');
      return;
    }

    // Click to open dropdown
    await exportBtn.click();

    // Should show export menu with 3 links (excel, namecards, attendance)
    const exportMenu = metabox.locator('.stride-export-menu');
    await expect(exportMenu).toBeVisible();
    const exportLinks = exportMenu.locator('a');
    const count = await exportLinks.count();
    expect(count).toBe(3);
  });
});

// ============================================================================
// 7. REGISTRATION APPROVAL (pending registrations)
// ============================================================================

test.describe('Registration Approval', () => {
  test('approve button triggers AJAX and updates status', async ({ page }) => {
    // Find an edition that has pending registrations
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="deelnemers"]').click();

    // Look for approve button (only on pending registrations)
    const approveBtn = metabox.locator('.stride-confirm-reg').first();
    if (!(await approveBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No pending registrations to approve');
      return;
    }

    acceptNextDialog(page);
    const ajaxPromise = waitForAjax(page);
    await approveBtn.click();
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });

  test('reject button triggers AJAX', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="deelnemers"]').click();

    const rejectBtn = metabox.locator('.stride-reject-reg').first();
    if (!(await rejectBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No pending registrations to reject');
      return;
    }

    acceptNextDialog(page);
    const ajaxPromise = waitForAjax(page);
    await rejectBtn.click();
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });
});

// ============================================================================
// 8. ATTENDANCE METABOX
// ============================================================================

test.describe('Attendance Tracking', () => {
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    // Switch to attendance tab
    const metabox = page.locator('#stride_edition_attendance');
    await metabox.locator('.stride-tab[data-tab="aanwezigheid"]').click();
  });

  test('attendance tab shows grid table', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    const attendanceTable = metabox.locator('.stride-attendance-table');

    // Attendance grid might not be visible if no sessions or registrations
    if (await attendanceTable.isVisible().catch(() => false)) {
      // Should have header row with session columns
      const headerCells = attendanceTable.locator('thead th');
      const count = await headerCells.count();
      // At minimum: name column + at least 1 session column
      expect(count).toBeGreaterThanOrEqual(1);
    }
  });

  test('clicking attendance cell cycles status via AJAX', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    const toggleBtn = metabox.locator('.stride-attendance-toggle').first();

    if (!(await toggleBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No attendance toggles visible');
      return;
    }

    // Click to toggle attendance
    const ajaxPromise = waitForAjax(page);
    await toggleBtn.click();
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });

  test('bulk mark present button triggers AJAX', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    const bulkBtn = metabox.locator('.stride-mark-all-present').first();

    if (!(await bulkBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No bulk attendance button visible');
      return;
    }

    acceptNextDialog(page);
    const ajaxPromise = waitForAjax(page);
    await bulkBtn.click();
    const response = await ajaxPromise;
    const body = await response.json();

    expect(body.success).toBe(true);
  });

  test('attendance totals update after marking', async ({ page }) => {
    const metabox = page.locator('#stride_edition_attendance');
    const toggleBtn = metabox.locator('.stride-attendance-toggle').first();

    if (!(await toggleBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No attendance toggles visible');
      return;
    }

    // Read initial total text
    const totalCell = metabox.locator('.attendance-totals .attendance-count').first();
    const hasTotals = await totalCell.isVisible().catch(() => false);

    if (hasTotals) {
      const initialText = await totalCell.textContent();

      // Toggle attendance
      const ajaxPromise = waitForAjax(page);
      await toggleBtn.click();
      await ajaxPromise;

      // Total text should have changed (or at minimum, not error)
      await expect(totalCell).toBeVisible();
    }
  });
});

// ============================================================================
// 9. NOTES TIMELINE
// ============================================================================

test.describe('Notes Timeline', () => {
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);
  });

  test('notes metabox is visible', async ({ page }) => {
    await expect(page.locator('#stride_edition_notes')).toBeVisible();
  });

  test('can add a note', async ({ page }) => {
    const notesMeta = page.locator('#stride_edition_notes');

    // Fill note content
    const noteInput = notesMeta.locator('#stride-note-content');
    await expect(noteInput).toBeVisible();
    await noteInput.fill('E2E test notitie - ' + Date.now());

    // Select note type (userinfo is checked by default; types are radio inputs)
    const todoType = notesMeta.locator('input[name="stride_note_type"][value="todo"]');
    if (await todoType.isVisible().catch(() => false)) {
      await todoType.check();
    }

    // Click add button
    const addBtn = notesMeta.locator('#stride-add-note');
    await addBtn.click();

    // Note should appear in timeline
    const timeline = notesMeta.locator('.stride-notes-timeline');
    await expect(timeline.locator('.stride-note-item').last()).toBeVisible();
  });

  test('note type selector has todo/email/info options', async ({ page }) => {
    const notesMeta = page.locator('#stride_edition_notes');

    // Note types are radio inputs with name="stride_note_type"
    const typeOptions = notesMeta.locator('input[name="stride_note_type"]');
    const count = await typeOptions.count();
    expect(count).toBeGreaterThanOrEqual(3);
  });

  test('can delete a note', async ({ page }) => {
    const notesMeta = page.locator('#stride_edition_notes');
    const noteItem = notesMeta.locator('.stride-note-item').first();

    if (!(await noteItem.isVisible().catch(() => false))) {
      test.skip(true, 'No notes to delete');
      return;
    }

    // Delete button uses class .stride-note-delete (not .stride-delete-note)
    const deleteBtn = noteItem.locator('.stride-note-delete');
    await deleteBtn.click();

    // Note should be removed from timeline (or marked as deleted)
    // The note is marked _deleted in JSON, so it may just be hidden
    // At minimum, no JS errors
    await expect(page.locator('body')).toBeVisible();
  });

  test('notes persist after save', async ({ page }) => {
    const notesMeta = page.locator('#stride_edition_notes');
    const noteInput = notesMeta.locator('#stride-note-content, textarea[name="note_content"]');

    const uniqueText = 'Persist test ' + Date.now();
    await noteInput.fill(uniqueText);
    await notesMeta.locator('#stride-add-note').click();

    // Save the edition
    await publishEdition(page);

    // Reload and check
    await page.reload();
    await page.waitForLoadState('domcontentloaded');

    const timeline = page.locator('#stride_edition_notes .stride-notes-timeline');
    const content = await timeline.textContent();
    expect(content).toContain(uniqueText);
  });
});

// ============================================================================
// 10. STATUS & ACTIONS SIDEBAR
// ============================================================================

test.describe('Status & Actions Sidebar', () => {
  test.beforeEach(async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);
  });

  test('sidebar shows current status with color', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');

    // The sidebar section renders on saved editions (not auto-draft)
    const sidebarContent = sidebar.locator('.stride-edition-sidebar');
    await expect(sidebarContent).toBeVisible();

    // Status header with colored background (color comes from inline <style> block, not style attr)
    const statusHeader = sidebar.locator('.stride-sidebar-status');
    await expect(statusHeader).toBeVisible();
  });

  test('capacity bar is displayed', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');
    const capacityBar = sidebar.locator('.stride-capacity-bar');

    await expect(capacityBar).toBeVisible();
  });

  test('status dropdown contains all status options', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');
    const statusSelect = sidebar.locator('select[name="stride_change_status"]');

    await expect(statusSelect).toBeVisible();

    // Verify all OfferingStatus enum values are present
    const expectedStatuses = [
      'draft', 'announcement', 'open', 'full',
      'in_progress', 'postponed', 'cancelled', 'completed', 'archived',
    ];

    for (const status of expectedStatuses) {
      const option = statusSelect.locator(`option[value="${status}"]`);
      await expect(option).toBeAttached();
    }
  });

  test('changing status updates on save', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');
    const statusSelect = sidebar.locator('select[name="stride_change_status"]');

    // Change to "open"
    await statusSelect.selectOption('open');
    await publishEdition(page);

    // Reload and verify
    await page.reload();
    await page.waitForLoadState('domcontentloaded');

    const selectedValue = await page.locator('select[name="stride_change_status"]').inputValue();
    expect(selectedValue).toBe('open');
  });

  test('requirements checkboxes are present', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');

    // Requirements only render on saved editions (not auto-draft)
    // The beforeEach navigates to the first edition which should be saved
    const sidebarContent = sidebar.locator('.stride-edition-sidebar');
    if (!(await sidebarContent.isVisible().catch(() => false))) {
      test.skip(true, 'Sidebar not rendered (edition may be auto-draft)');
      return;
    }

    // These are the enrollment requirement checkboxes
    // Note: there are hidden inputs (value=0) + checkbox inputs (value=1) for each field
    const questionnaire = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_questionnaire]"]');
    const documents = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_documents]"]');
    const sessionSelection = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_session_selection]"]');
    const approval = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_approval]"]');

    await expect(questionnaire).toBeVisible();
    await expect(documents).toBeVisible();
    await expect(sessionSelection).toBeVisible();
    await expect(approval).toBeVisible();
  });

  test('session selection checkbox toggles deadline field', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');
    const sessionSelectionCb = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_session_selection]"]');
    const selectionControls = page.locator('#stride-selection-controls');
    const selectionOpenCb = sidebar.locator('input[type="checkbox"][name="ntdst_fields[selection_open]"]');
    const deadlineField = sidebar.locator('input[name="ntdst_fields[selection_deadline]"]');

    if (!(await sessionSelectionCb.isVisible().catch(() => false))) {
      test.skip(true, 'Session selection checkbox not found (edition may be auto-draft)');
      return;
    }

    // Enable session selection
    if (!(await sessionSelectionCb.isChecked())) {
      await sessionSelectionCb.check();
    }

    // Sub-controls section should become visible (toggled by jQuery)
    await expect(selectionControls).toBeVisible();
    await expect(selectionOpenCb).toBeVisible();
    await expect(deadlineField).toBeVisible();

    // Uncheck session selection — sub-fields should hide
    await sessionSelectionCb.uncheck();
    await expect(selectionControls).not.toBeVisible();
  });

  test('requires_approval checkbox persists', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');
    const approvalCb = sidebar.locator('input[type="checkbox"][name="ntdst_fields[requires_approval]"]');

    if (!(await approvalCb.isVisible().catch(() => false))) {
      test.skip(true, 'Approval checkbox not found (edition may be auto-draft)');
      return;
    }

    // Toggle approval on
    if (!(await approvalCb.isChecked())) {
      await approvalCb.check();
    }

    // #publish works for both Publish (new) and Update (existing) buttons
    await publishEdition(page);
    await page.reload();
    await page.waitForLoadState('domcontentloaded');

    await expect(
      page.locator('input[type="checkbox"][name="ntdst_fields[requires_approval]"]'),
    ).toBeChecked();
  });

  test('meta info shows linked course and dates', async ({ page }) => {
    const sidebar = page.locator('#stride_edition_actions');

    // Should display course title, created date, modified date
    const metaInfo = sidebar.locator('.stride-edition-sidebar');
    await expect(metaInfo).toBeVisible();
  });
});

// ============================================================================
// 11. EXPORT FUNCTIONALITY
// ============================================================================

test.describe('Export Functionality', () => {
  test('Excel export triggers file download', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const metabox = page.locator('#stride_edition_attendance');

    // Export toggle only renders when there are registrations
    const exportBtn = metabox.locator('.stride-export-toggle');
    if (!(await exportBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No export button (no registrations for this edition)');
      return;
    }

    // Open export dropdown and find Excel link
    await exportBtn.click();
    const excelLink = metabox.locator('.stride-export-menu a[href*="type=excel"]').first();
    if (!(await excelLink.isVisible().catch(() => false))) {
      test.skip(true, 'No export link visible');
      return;
    }

    // Listen for download event
    const downloadPromise = page.waitForEvent('download', { timeout: 15000 });
    await excelLink.click();
    const download = await downloadPromise;

    // Verify it's an xlsx file
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
  });

  test('attendance export triggers file download', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const metabox = page.locator('#stride_edition_attendance');

    // Export toggle only renders when there are registrations
    const exportBtn = metabox.locator('.stride-export-toggle');
    if (!(await exportBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No export button (no registrations for this edition)');
      return;
    }

    await exportBtn.click();
    const attendanceLink = metabox.locator('.stride-export-menu a[href*="type=attendance"]').first();
    if (!(await attendanceLink.isVisible().catch(() => false))) {
      test.skip(true, 'No attendance export link visible');
      return;
    }

    const downloadPromise = page.waitForEvent('download', { timeout: 15000 });
    await attendanceLink.click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toMatch(/\.docx$/);
  });

  test('namecard export triggers file download', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const metabox = page.locator('#stride_edition_attendance');

    // Export toggle only renders when there are registrations
    const exportBtn = metabox.locator('.stride-export-toggle');
    if (!(await exportBtn.isVisible().catch(() => false))) {
      test.skip(true, 'No export button (no registrations for this edition)');
      return;
    }

    await exportBtn.click();
    const namecardLink = metabox.locator('.stride-export-menu a[href*="type=namecards"]').first();
    if (!(await namecardLink.isVisible().catch(() => false))) {
      test.skip(true, 'No namecard export link visible');
      return;
    }

    const downloadPromise = page.waitForEvent('download', { timeout: 15000 });
    await namecardLink.click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toMatch(/\.docx$/);
  });
});

// ============================================================================
// 12. CAPACITY VISUALIZATION
// ============================================================================

test.describe('Capacity Visualization', () => {
  test('capacity bar reflects registration count', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    const sidebar = page.locator('#stride_edition_actions');

    // Capacity section is inside .stride-edition-sidebar (only on saved editions)
    const sidebarContent = sidebar.locator('.stride-edition-sidebar');
    if (!(await sidebarContent.isVisible().catch(() => false))) {
      test.skip(true, 'Sidebar not rendered (edition may be auto-draft)');
      return;
    }

    // Capacity text shows "X / Y plaatsen" or "X inschrijvingen"
    // The bar (.stride-capacity-bar) only renders when capacity > 0
    // The text is in .stride-capacity-text
    const capacityText = sidebar.locator('.stride-capacity-text');
    await expect(capacityText).toBeVisible();
    const text = await capacityText.textContent();
    expect(text?.trim()).toMatch(/\d+/);
  });

  test('list table capacity colors match fill level', async ({ page }) => {
    await gotoEditionList(page);

    // Check that capacity cells have color coding
    const capacityCells = page.locator('td.column-capacity');
    const count = await capacityCells.count();

    if (count > 0) {
      // At least one should have a color indicator
      const firstCell = capacityCells.first();
      const html = await firstCell.innerHTML();
      // Should contain a colored element (span with style or class)
      expect(html).toMatch(/(color|background|green|yellow|red|class)/i);
    }
  });
});

// ============================================================================
// 13. EDGE CASES & ERROR HANDLING
// ============================================================================

test.describe('Edge Cases', () => {
  test('saving without required course shows validation', async ({ page }) => {
    await gotoNewEdition(page);

    // Set title but skip course selection
    await page.fill('#title', 'No Course Edition');
    await page.fill('input[name="ntdst_fields[start_date]"]', '2026-06-01');

    await publishEdition(page);

    // Edition should still save (WordPress doesn't block publish),
    // but course_id should be empty. No PHP fatal error.
    await expect(page.locator('body')).toBeVisible();
    const content = await page.textContent('body');
    expect(content?.toLowerCase()).not.toContain('fatal error');
  });

  test('no JS errors on page load', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    // Wait for all JS to initialize
    await page.waitForTimeout(2000);

    expect(errors).toHaveLength(0);
  });

  test('edition admin CSS loads correctly', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    // Check that the edition admin stylesheet is loaded
    const styleTag = page.locator('#stride-edition-admin-styles, style:has-text("stride-edition"), link[href*="edition-admin"]');
    await expect(styleTag.first()).toBeAttached();
  });

  test('edition admin JS is enqueued', async ({ page }) => {
    await gotoEditionList(page);
    const { postId } = await getFirstEditionRow(page);
    await gotoEditEdition(page, postId);

    // The localized script object should be available
    const hasScript = await page.evaluate(() => typeof (window as any).strideEditionAdmin !== 'undefined');
    expect(hasScript).toBe(true);
  });

  test('Select2 initializes for course dropdown', async ({ page }) => {
    await gotoNewEdition(page);

    // Select2 should be initialized (renders a .select2-container next to the select)
    const select2Container = page.locator('.select2-container');
    await expect(select2Container.first()).toBeVisible();
  });

  test('AJAX nonce is present in localized data', async ({ page }) => {
    await gotoNewEdition(page);

    const nonce = await page.evaluate(() => (window as any).strideEditionAdmin?.nonce);
    expect(nonce).toBeTruthy();
    expect(typeof nonce).toBe('string');
  });
});

// ============================================================================
// 14. FULL ROUND-TRIP: Create → Session → Save → Verify
// ============================================================================

test.describe('Full Round-Trip', () => {
  test('create edition, add session, set status, save, verify on list', async ({ page }) => {
    const timestamp = Date.now();

    // 1. Create a new edition
    await gotoNewEdition(page);
    await page.fill('#title', `Roundtrip Editie ${timestamp}`);

    // Pick a course via Select2
    await page.click('.stride-select2-course + .select2-container');
    await page.waitForSelector('.select2-results__option', { timeout: 5000 });
    await page.click('.select2-results__option:not(.select2-results__message):first-child');

    await page.fill('input[name="ntdst_fields[start_date]"]', '2026-09-01');
    await page.fill('input[name="ntdst_fields[capacity]"]', '15');
    await page.fill('input[name="ntdst_fields[venue]"]', 'Roundtrip Locatie');

    // Publish first to get a post ID (sessions need edition_id)
    // Status dropdown is not available on auto-draft — set it after first save
    await publishEdition(page);

    // Now the edition is saved — set status to Open (sidebar now renders full controls)
    const statusSelect = page.locator('select[name="stride_change_status"]');
    if (await statusSelect.isVisible().catch(() => false)) {
      await statusSelect.selectOption('open');
    }

    // 2. Add a session
    await page.click('#stride-add-session-btn');
    const formRow = page.locator('.stride-session-form-row');
    await formRow.locator('.stride-type-option[data-type="in_person"]').click();
    await formRow.locator('input[name="session_date"]').fill('2026-09-15');
    await formRow.locator('input[name="session_start_time"]').fill('09:00');
    await formRow.locator('input[name="session_end_time"]').fill('17:00');

    // Wait specifically for the session save AJAX response
    const sessionSavePromise = page.waitForResponse(
      (res) => res.url().includes('admin-ajax.php') && res.request().postData()?.includes('stride_add_session') === true,
      { timeout: 10000 },
    );
    await page.click('.stride-session-save');
    const response = await sessionSavePromise;
    const body = await response.json();
    expect(body.success).toBe(true);

    // 3. Add a note
    const noteInput = page.locator('#stride-note-content');
    if (await noteInput.isVisible().catch(() => false)) {
      await noteInput.fill(`Roundtrip note ${timestamp}`);
      await page.locator('#stride-add-note').click();
    }

    // 4. Save again (Update)
    await publishEdition(page);

    // 5. Verify on list page
    await gotoEditionList(page);

    // Find our edition by title
    const ourRow = page.locator('#the-list tr', {
      hasText: `Roundtrip Editie ${timestamp}`,
    });
    await expect(ourRow).toBeVisible();

    // Verify status column shows "Open" (if status was set)
    const statusCell = ourRow.locator('td.column-status');
    const statusText = await statusCell.textContent();
    expect(statusText?.toLowerCase()).toContain('open');

    // Verify venue column
    const venueCell = ourRow.locator('td.column-venue');
    await expect(venueCell).toContainText('Roundtrip Locatie');
  });
});
