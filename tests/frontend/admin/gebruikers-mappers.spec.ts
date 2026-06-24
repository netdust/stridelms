/**
 * Unit: Gebruikers (user search) pure mappers (Cluster F, Tier A).
 *
 * The gebruikers surface is search-driven and overwhelmingly Tier B. Two thin
 * pure helpers carry the only branching contracts:
 *
 *   - userRows(payload) — bare-array tolerance. The /admin/users/search endpoint
 *     returns a bare array; a non-array (an error envelope, undefined, a quirk)
 *     must normalize to [] so the list blanks safely instead of crashing the
 *     render. Denial branch: a non-array never echoes through as rows.
 *
 *   - userInitials(name) — first+last initial, upper-cased. Edge cases: single
 *     name, empty/whitespace, extra spaces. A wrong slice ships a broken avatar.
 *
 * Imported via the UMD tail on gebruikers.js (module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const gebruikers = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/gebruikers.js');

test.describe('userRows (bare-array tolerance)', () => {
  test('passes a bare array of users straight through', () => {
    const rows = [{ id: 1 }, { id: 2 }];
    expect(gebruikers.userRows(rows)).toEqual(rows);
  });

  test('DENIAL: a non-array payload normalizes to [] (never crashes the list)', () => {
    expect(gebruikers.userRows(undefined)).toEqual([]);
    expect(gebruikers.userRows(null)).toEqual([]);
    expect(gebruikers.userRows({})).toEqual([]);
    expect(gebruikers.userRows({ items: [{ id: 1 }] })).toEqual([]); // wrapped envelope, not a bare array
    expect(gebruikers.userRows('nope')).toEqual([]);
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
