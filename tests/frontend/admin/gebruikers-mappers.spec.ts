/**
 * Unit: Gebruikers (user search) pure mappers (Cluster F, Tier A).
 *
 * The gebruikers surface is search-driven and overwhelmingly Tier B. Two thin
 * pure helpers carry the only branching contracts:
 *
 *   - userRows(payload) — envelope normalizer. The /admin/users/search
 *     endpoint returns the standard { items, total, … } envelope since the
 *     Gebruikers slice (F-U1 — paged); the legacy bare array is tolerated
 *     defensively, and anything else normalizes to [] so the list blanks
 *     safely instead of crashing the render.
 *
 *   - userInitials(name) — first+last initial, upper-cased. Edge cases: single
 *     name, empty/whitespace, extra spaces. A wrong slice ships a broken avatar.
 *
 * Imported via the UMD tail on gebruikers.js (module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const gebruikers = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/gebruikers.js');

test.describe('userRows (envelope normalizer)', () => {
  test('reads the items array from the standard envelope', () => {
    const rows = [{ id: 1 }, { id: 2 }];
    expect(gebruikers.userRows({ items: rows, total: 40, page: 1 })).toEqual(rows);
  });

  test('legacy bare array is tolerated defensively', () => {
    const rows = [{ id: 1 }, { id: 2 }];
    expect(gebruikers.userRows(rows)).toEqual(rows);
  });

  test('DENIAL: a malformed payload normalizes to [] (never crashes the list)', () => {
    expect(gebruikers.userRows(undefined)).toEqual([]);
    expect(gebruikers.userRows(null)).toEqual([]);
    expect(gebruikers.userRows({})).toEqual([]);
    expect(gebruikers.userRows({ items: 'x' })).toEqual([]);
    expect(gebruikers.userRows('nope')).toEqual([]);
  });
});

test.describe('gebruikers() factory search gating', () => {
  const factory = () => gebruikers.gebruikers();

  test('a query shorter than 2 chars never issues a request — prompt state, no error (the 1-char 400 flash)', async () => {
    const f = factory();
    let calls = 0;
    f.api = async () => { calls++; return { items: [] }; };
    f.query = 'a';
    await f.search(1);
    expect(calls).toBe(0);
    expect(f.showPrompt).toBe(true);
    expect(f.error).toBe('');
  });

  test('a 2-char query searches and lands in the searched state with honest totals', async () => {
    const f = factory();
    f.api = async () => ({ items: [{ id: 1 }], total: 26, page: 1, perPage: 25, totalPages: 2 });
    f.query = 'an';
    await f.search(1);
    expect(f.rows).toEqual([{ id: 1 }]);
    expect(f.total).toBe(26);
    expect(f.pageCount).toBe(2);
    expect(f.searched).toBe(true);
  });

  test('shortening the query below the minimum cancels an in-flight longer search', async () => {
    const f = factory();
    let resolveSlow;
    f.api = () => new Promise((res) => { resolveSlow = res; });
    f.query = 'anna';
    const pending = f.search(1);
    f.query = 'a';
    await f.search(1); // back to the prompt, token bumped
    resolveSlow({ items: [{ id: 9 }], total: 1, totalPages: 1 });
    await pending;
    expect(f.rows).toEqual([]); // the stale response must not land on the prompt
    expect(f.showPrompt).toBe(true);
  });
});

test.describe('userInitials', () => {
  test('takes the first letter of the first and last name, upper-cased', () => {
    expect(gebruikers.userInitials('Ada Lovelace')).toBe('AL');
    expect(gebruikers.userInitials('jan willem de jong')).toBe('JJ'); // first + LAST token
  });

  test('a single name yields a single duplicated-position initial', () => {
    expect(gebruikers.userInitials('Cher')).toBe('CC'); // first[0] + last[0], same token
  });

  test('EDGE: empty / whitespace / nullish → empty string, no crash', () => {
    expect(gebruikers.userInitials('')).toBe('');
    expect(gebruikers.userInitials('   ')).toBe('');
    expect(gebruikers.userInitials(undefined)).toBe('');
    expect(gebruikers.userInitials(null)).toBe('');
  });
});
