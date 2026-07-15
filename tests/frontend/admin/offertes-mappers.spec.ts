/**
 * Unit: Offertes list pure mappers (Cluster F, Tier A).
 *
 * The offertes surface is overwhelmingly Tier B (presentational — verified by
 * the cold-landing screenshot gate). The ONE exception is quoteRows(): the
 * envelope-normalizer. The backend emits ONE envelope ({ items }) on every
 * path since the Offertes slice removed the zero-user-search short-circuit
 * (F-A8); quoteRows stays as DEFENSIVE tolerance for the legacy { data }
 * shape. A wrong normalizer ships a real bug: the
 * list silently blanks on the zero-user-search path (rows = undefined.items),
 * OR crashes on a malformed payload. The contract is falsifiable and branching,
 * so it gets a RED-first behavioural test incl. its empty/edge + denial branch.
 *
 * quoteBadgeClass() is the closed quote-workflow VALUE → CSS hue map (INV-7:
 * the LABEL is rendered AS RECEIVED elsewhere; this maps the value to a hue
 * class only). Unknown → neutral, never an arbitrary class.
 *
 * No browser, no DDEV — the mappers are imported directly (UMD tail on
 * offertes.js exposes module.exports under Node, exactly like grid.js).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const offertes = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/offertes.js');

test.describe('quoteRows (items|data envelope tolerance)', () => {
  test('normal envelope → reads the items array', () => {
    const rows = offertes.quoteRows({ items: [{ id: 1 }, { id: 2 }], total: 2 });
    expect(rows).toEqual([{ id: 1 }, { id: 2 }]);
  });

  test('legacy data envelope → reads the data array (defensive tolerance)', () => {
    // The removed Phase-1 short-circuit returned `data`, not `items`; the
    // normalizer keeps tolerating that shape defensively (F-A8).
    // A normalizer that only reads `items` would silently blank this list.
    const rows = offertes.quoteRows({ data: [{ id: 9 }], total: 0, page: 1 });
    expect(rows).toEqual([{ id: 9 }]);
  });

  test('items wins precedence when both are present', () => {
    expect(offertes.quoteRows({ items: [{ id: 1 }], data: [{ id: 2 }] })).toEqual([{ id: 1 }]);
  });

  test('EDGE: empty arrays under either key → []', () => {
    expect(offertes.quoteRows({ items: [] })).toEqual([]);
    expect(offertes.quoteRows({ data: [] })).toEqual([]);
  });

  test('DENIAL: absent / malformed payload → [] (never a crash, never undefined rows)', () => {
    expect(offertes.quoteRows(undefined)).toEqual([]);
    expect(offertes.quoteRows(null)).toEqual([]);
    expect(offertes.quoteRows({})).toEqual([]);
    expect(offertes.quoteRows('nope')).toEqual([]);
    // Adversarial: keys present but NOT arrays must not echo through as rows.
    expect(offertes.quoteRows({ items: 'x', data: 5 })).toEqual([]);
  });
});

test.describe('quoteBadgeClass', () => {
  test('maps each closed quote-workflow value to a shipped ws-badge hue', () => {
    expect(offertes.quoteBadgeClass('draft')).toBe('pending');
    expect(offertes.quoteBadgeClass('sent')).toBe('confirmed');
    expect(offertes.quoteBadgeClass('exported')).toBe('completed');
    expect(offertes.quoteBadgeClass('cancelled')).toBe('cancelled');
  });

  test('DENIAL: unknown / empty value → neutral "cancelled" hue (never arbitrary)', () => {
    expect(offertes.quoteBadgeClass('paid')).toBe('cancelled');
    expect(offertes.quoteBadgeClass('')).toBe('cancelled');
    expect(offertes.quoteBadgeClass(undefined)).toBe('cancelled');
    expect(offertes.quoteBadgeClass(null)).toBe('cancelled');
  });
});

/* Factory behaviors added at the Offertes slice (F-O1/F-O2). */
test.describe('offertes() factory', () => {
  const factory = () => offertes.offertes();

  test('status filter rides the request and counts as an active filter', () => {
    const f = factory();
    f.filters.status = 'sent';
    expect(f.hasFilters).toBe(true);
    f.filters.status = '';
    expect(f.hasFilters).toBe(false);
  });

  test('clearAllFilters resets status too and issues exactly one load', () => {
    const f = factory();
    let loads = 0;
    f.load = () => { loads++; };
    f.filters = { q: 'x', status: 'sent', tag: '3', dateFrom: '2026-01-01', dateTo: '2026-01-31' };
    // simulate flatpickr clear() firing the cleared branch, as init wires it
    f._fp = { clear: () => f.onDateChange([]) };
    f.clearAllFilters();
    expect(f.filters.status).toBe('');
    expect(loads).toBe(1); // the cleared-branch both-empty guard
  });

  test('openPerson only navigates for a real user id (deleted/lead customers stay put)', () => {
    const f = factory();
    const jumps = [];
    f.switchView = (view, params) => { jumps.push([view, params]); };
    f.openPerson({ user: { id: 7 } });
    f.openPerson({ user: { id: 0 } });
    f.openPerson({});
    f.openPerson(null);
    expect(jumps).toEqual([['dossier', { user: 7 }]]);
  });
});
