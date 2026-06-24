/**
 * Unit: Offertes list pure mappers (Cluster F, Tier A).
 *
 * The offertes surface is overwhelmingly Tier B (presentational — verified by
 * the cold-landing screenshot gate). The ONE exception is quoteRows(): the
 * envelope-normalizer that tolerates the backend's Phase-1-deferred quirk where
 * AdminQuoteService::getQuoteList returns { items } normally but { data } on a
 * zero-user-search short-circuit. A wrong normalizer ships a real bug: the
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

  test('zero-user-search short-circuit → reads the data array (the Phase-1 quirk)', () => {
    // The deferred backend quirk: a zero-user-search returns `data`, not `items`.
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
