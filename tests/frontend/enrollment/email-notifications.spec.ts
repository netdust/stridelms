import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

/**
 * E2E Tests: Email Notifications with SmartCode Resolution
 *
 * Verifies that Stride email notifications are sent via netdust-mail
 * and that SmartCode placeholders ({{edition.title}}, {{user.first_name}}, etc.)
 * are fully resolved before delivery.
 *
 * Approach:
 * - Use PHP helper scripts via `ddev exec wp eval-file` to avoid bash variable expansion issues
 * - Query Mailpit API to verify emails arrived with correct content
 * - No browser needed for most tests (Playwright runner for structure only)
 *
 * Mailpit API: https://stride.ddev.site:8026/api/v1
 */

// Disable TLS verification for Mailpit self-signed cert (DDEV)
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

const MAILPIT_API = 'https://stride.ddev.site:8026/api/v1';
const PROJECT_ROOT = '/home/ntdst/Sites/stride';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Clear all messages in Mailpit.
 */
async function clearMailbox(): Promise<void> {
  await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE' });
}

/**
 * Search Mailpit for emails matching a query.
 * Retries with delay since emails may take a moment to arrive.
 *
 * @param query  Mailpit search filter (e.g. "subject:keyword", "to:email")
 * @param retries  Number of retry attempts
 * @param delayMs  Delay between retries in ms
 */
async function searchEmails(query: string, retries = 8, delayMs = 1000): Promise<any[]> {
  for (let i = 0; i < retries; i++) {
    const res = await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent(query)}`);
    const data = await res.json();
    if (data.messages && data.messages.length > 0) {
      return data.messages;
    }
    await new Promise((r) => setTimeout(r, delayMs));
  }
  return [];
}

/**
 * Get full email message by ID (includes HTML and Text body).
 */
async function getEmailById(id: string): Promise<any> {
  const res = await fetch(`${MAILPIT_API}/message/${id}`);
  return res.json();
}

/**
 * Get all messages currently in Mailpit.
 */
async function getAllEmails(): Promise<any[]> {
  const res = await fetch(`${MAILPIT_API}/messages`);
  const data = await res.json();
  return data.messages ?? [];
}

/**
 * Run a WP-CLI eval-file command via ddev exec.
 * Uses PHP script files to avoid bash variable expansion issues with $wpdb etc.
 */
function wpEvalFile(scriptPath: string, extraArgs: string[] = []): string {
  const argsStr = extraArgs.length > 0 ? ' -- ' + extraArgs.join(' ') : '';
  try {
    return execSync(`ddev exec wp eval-file ${scriptPath}${argsStr}`, {
      cwd: PROJECT_ROOT,
      encoding: 'utf-8',
      timeout: 30000,
    }).trim();
  } catch (e: any) {
    return e.stdout?.trim() ?? '';
  }
}

/**
 * Trigger a Stride action with context via the trigger-mail.php helper.
 */
function triggerAction(action: string, context: Record<string, number | string>): string {
  const jsonCtx = JSON.stringify(context);
  // Shell-escape the JSON by wrapping in single quotes and escaping inner single quotes
  const escaped = "'" + jsonCtx.replace(/'/g, "'\\''") + "'";
  return wpEvalFile('scripts/test-helpers/trigger-mail.php', [action, escaped]);
}

// ---------------------------------------------------------------------------
// Discover seed data IDs at runtime
// ---------------------------------------------------------------------------

interface SeedContext {
  userId: number;
  editionId: number;
  registrationId: number;
  quoteId: number;
  editionTitle: string;
  userFirstName: string;
  userDisplayName: string;
  quoteNumber: string;
  templatesSeeded: boolean;
  siteName: string;
}

let ctx: SeedContext;

/**
 * Discover required IDs from seed data via the get-seed-ids.php helper.
 * This runs once before the test suite.
 */
function discoverSeedData(): SeedContext {
  const output = wpEvalFile('scripts/test-helpers/get-seed-ids.php');

  // Strip WP-CLI noise (anything before the first '{')
  const jsonStart = output.indexOf('{');
  if (jsonStart === -1) {
    throw new Error(`get-seed-ids.php returned no JSON. Output: ${output}`);
  }
  const jsonStr = output.substring(jsonStart);

  let data: any;
  try {
    data = JSON.parse(jsonStr);
  } catch {
    throw new Error(`Failed to parse seed data JSON: ${jsonStr}`);
  }

  return {
    userId: Number(data.user_id) || 0,
    editionId: Number(data.edition_id) || 0,
    registrationId: Number(data.registration_id) || 0,
    quoteId: Number(data.quote_id) || 0,
    editionTitle: data.edition_title ?? '',
    userFirstName: data.user_first_name ?? '',
    userDisplayName: data.user_display_name ?? '',
    quoteNumber: data.quote_number ?? '',
    templatesSeeded: !!data.templates_seeded,
    siteName: data.site_name ?? '',
  };
}

// ===========================================================================
// Test Suite
// ===========================================================================

test.describe('Email Notifications — SmartCode Resolution', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    ctx = discoverSeedData();

    // Ensure mail templates are seeded
    if (!ctx.templatesSeeded) {
      wpEvalFile('scripts/test-helpers/seed-mail-templates.php');
    }
  });

  // -----------------------------------------------------------------------
  // 1. Enrollment created — user confirmation email
  // -----------------------------------------------------------------------
  test.describe('Enrollment created — user email', () => {
    test.beforeAll(async () => {
      await clearMailbox();

      // Fire the registration/created action with seed data context
      triggerAction('stride/registration/created', {
        user_id: ctx.userId,
        edition_id: ctx.editionId,
        registration_id: ctx.registrationId,
      });
    });

    test('user receives enrollment confirmation email', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data (user or edition)');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);
    });

    test('subject contains resolved edition title (not raw SmartCode)', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      // Subject should contain the actual edition title
      const subject: string = messages[0].Subject;
      expect(subject).not.toContain('{{edition.title}}');
      if (ctx.editionTitle) {
        expect(subject).toContain(ctx.editionTitle);
      }
    });

    test('body contains resolved user first name', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const fullMsg = await getEmailById(messages[0].ID);
      const html: string = fullMsg.HTML || fullMsg.Text || '';

      // Should NOT contain raw SmartCode
      expect(html).not.toContain('{{user.first_name}}');

      // Should contain the resolved first name
      if (ctx.userFirstName) {
        expect(html).toContain(ctx.userFirstName);
      }
    });
  });

  // -----------------------------------------------------------------------
  // 2. Enrollment created — admin notification
  // -----------------------------------------------------------------------
  test.describe('Enrollment created — admin email', () => {
    // Emails were already triggered in the previous beforeAll;
    // both user and admin templates fire on the same action.

    test('admin receives new enrollment notification', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Nieuwe inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);
    });

    test('admin email body contains user display name', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Nieuwe inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const fullMsg = await getEmailById(messages[0].ID);
      const html: string = fullMsg.HTML || fullMsg.Text || '';

      expect(html).not.toContain('{{user.display_name}}');
      if (ctx.userDisplayName) {
        expect(html).toContain(ctx.userDisplayName);
      }
    });
  });

  // -----------------------------------------------------------------------
  // 3. Quote created — user email with quote number
  // -----------------------------------------------------------------------
  test.describe('Quote created — user email', () => {
    test.beforeAll(async () => {
      await clearMailbox();

      if (!ctx.quoteId || !ctx.userId || !ctx.editionId) return;

      triggerAction('stride/quote/created', {
        user_id: ctx.userId,
        quote_id: ctx.quoteId,
        edition_id: ctx.editionId,
      });
    });

    test('user receives quote email', async () => {
      test.skip(!ctx.quoteId, 'No seed quote found');

      const messages = await searchEmails('subject:offerte');
      expect(messages.length).toBeGreaterThanOrEqual(1);
    });

    test('quote email subject contains resolved quote number', async () => {
      test.skip(!ctx.quoteId || !ctx.quoteNumber, 'No seed quote found');

      const messages = await searchEmails('subject:offerte');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const subject: string = messages[0].Subject;
      expect(subject).not.toContain('{{quote.number}}');
      expect(subject).toContain(ctx.quoteNumber);
    });
  });

  // -----------------------------------------------------------------------
  // 4. No unparsed SmartCodes in any email
  // -----------------------------------------------------------------------
  test('no email contains unparsed SmartCodes ({{...}})', async () => {
    test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

    // Trigger a fresh batch of emails
    await clearMailbox();

    triggerAction('stride/registration/created', {
      user_id: ctx.userId,
      edition_id: ctx.editionId,
      registration_id: ctx.registrationId,
    });

    // Wait for emails
    const messages = await searchEmails('to:*');
    // Fallback: get all if search-all does not work
    const allMessages = messages.length > 0 ? messages : await getAllEmails();

    expect(allMessages.length).toBeGreaterThan(0);

    for (const msg of allMessages) {
      const fullMsg = await getEmailById(msg.ID);
      const html: string = fullMsg.HTML || '';
      const text: string = fullMsg.Text || '';

      // Assert no unparsed SmartCodes remain in HTML body
      const smartCodePattern = /\{\{[a-z]+\.[a-z_]+\}\}/gi;
      const htmlMatches = html.match(smartCodePattern) ?? [];
      const textMatches = text.match(smartCodePattern) ?? [];

      expect(htmlMatches).toEqual([]);
      expect(textMatches).toEqual([]);
    }
  });

  // -----------------------------------------------------------------------
  // 5. Email layout wrapping (header, footer, branding)
  // -----------------------------------------------------------------------
  test.describe('Email layout wrapping', () => {
    test.beforeAll(async () => {
      await clearMailbox();

      if (!ctx.userId || !ctx.editionId) return;

      triggerAction('stride/registration/created', {
        user_id: ctx.userId,
        edition_id: ctx.editionId,
        registration_id: ctx.registrationId,
      });
    });

    test('email is wrapped in HTML layout with header and footer', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const fullMsg = await getEmailById(messages[0].ID);
      const html: string = fullMsg.HTML || '';

      // Should have proper HTML structure (not raw text)
      expect(html).toContain('<!DOCTYPE html>');
      expect(html).toContain('</html>');
    });

    test('email contains site name in header', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const fullMsg = await getEmailById(messages[0].ID);
      const html: string = fullMsg.HTML || '';

      if (ctx.siteName) {
        expect(html).toContain(ctx.siteName);
      }
    });

    test('email contains copyright footer', async () => {
      test.skip(!ctx.userId || !ctx.editionId, 'Missing seed data');

      const messages = await searchEmails('subject:Bevestiging inschrijving');
      expect(messages.length).toBeGreaterThanOrEqual(1);

      const fullMsg = await getEmailById(messages[0].ID);
      const html: string = fullMsg.HTML || '';

      // Layout template renders: &copy; YYYY SiteName
      const year = new Date().getFullYear().toString();
      expect(html).toContain(year);
      // The footer section has copyright and site link
      expect(html).toContain('All rights reserved');
    });
  });
});
