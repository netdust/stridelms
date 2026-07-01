/**
 * Unit: Inschrijvingen grid pure mappers (Cluster C, Tier A).
 *
 * The grid is overwhelmingly Tier B (presentational — verified by the
 * cold-landing screenshot gate). These three helpers are the exceptions: each
 * carries real branching logic with a falsifiable contract, so each gets a
 * RED-first behavioural test incl. its empty/edge + denial branch.
 *
 *   1. queueToParams(queueKey) — translates a Vandaag deep-link queue key
 *      (pending/waitlist/offerte/nocert/oldinterest) into the REAL endpoint
 *      query params. The endpoint does NOT accept `queue`; this is the
 *      frontend-side translation. A wrong mapping ships the abandoned attempt's
 *      bug: a deep-link that lands on the WRONG filter. The DENIAL branch is an
 *      unknown queue key → no params invented, no backend param fabricated.
 *
 *   2. offerteClass(label) — maps the AS-RECEIVED Dutch offerte LABEL (INV-7:
 *      never re-derived) to its closed-enum CSS modifier key. A wrong/unknown
 *      label must fall through to 'none', never leak an arbitrary class.
 *
 *   3. gridFilterPayload(filters) — the select-all "blast radius" computation:
 *      the structured filter subset the server expands a cross-page select-all
 *      against. It MUST drop paging/sort/group_by (the expansion ignores them)
 *      and MUST omit empty filters (so an empty payload = the whole active set,
 *      not a malformed query). The edge branch is the all-empty filter set.
 *
 * No browser, no DDEV — the mappers are imported directly (UMD tail on grid.js
 * exposes module.exports under Node, exactly like vandaag.js).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const grid = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/grid.js');

test.describe('queueToParams', () => {
  test('pending → status=pending (the simple-status queues)', () => {
    expect(grid.queueToParams('pending')).toEqual({ status: 'pending' });
  });

  test('waitlist → status=waitlist', () => {
    expect(grid.queueToParams('waitlist')).toEqual({ status: 'waitlist' });
  });

  test('offerte-opvolging → the closest available filter (status=confirmed)', () => {
    // offerte_opvolging is "confirmed + offerte not yet exported"; the endpoint
    // has no offerte param, so we approximate with status=confirmed and do NOT
    // invent a backend param.
    expect(grid.queueToParams('offerte')).toEqual({ status: 'confirmed' });
  });

  test('nocert → status=completed, oldinterest → status=interest', () => {
    expect(grid.queueToParams('nocert')).toEqual({ status: 'completed' });
    expect(grid.queueToParams('oldinterest')).toEqual({ status: 'interest' });
  });

  test('interest_to_invite → status=interest (deep-link to the interest list)', () => {
    // "Interesse — editie nu gepland": the closest real filter is the interest
    // status, same approach as oldinterest. The mail SEND is deferred; this
    // deep-link only views the list.
    expect(grid.queueToParams('interest_to_invite')).toEqual({ status: 'interest' });
  });

  test('DENIAL: unknown queue key → {} (no fabricated backend param, no crash)', () => {
    expect(grid.queueToParams('not-a-queue')).toEqual({});
    expect(grid.queueToParams('')).toEqual({});
    expect(grid.queueToParams(undefined)).toEqual({});
    // Adversarial: a key that is NOT in the queue table must never echo through
    // as a status — the endpoint would silently ignore a bogus status and the
    // grid would show an unfiltered page disguised as a filtered one.
    expect(grid.queueToParams('queue=pending&status=confirmed')).toEqual({});
  });
});

test.describe('offerteClass', () => {
  test('maps each AS-RECEIVED Dutch label to its CSS modifier key', () => {
    expect(grid.offerteClass('Geen offerte')).toBe('none');
    expect(grid.offerteClass('In behandeling')).toBe('draft');
    expect(grid.offerteClass('Verzonden')).toBe('sent');
    expect(grid.offerteClass('Verwerkt')).toBe('exported');
  });

  test('DENIAL: unknown / empty label → "none" (never an arbitrary class)', () => {
    expect(grid.offerteClass('Onbekend')).toBe('none');
    expect(grid.offerteClass('')).toBe('none');
    expect(grid.offerteClass(undefined)).toBe('none');
    expect(grid.offerteClass(null)).toBe('none');
  });
});

test.describe('gridFilterPayload', () => {
  test('carries only the set structured filters (drops paging/sort/group_by)', () => {
    const payload = grid.gridFilterPayload({
      status: 'pending',
      edition_id: 42,
      company_id: 7,
      trajectory_id: 3,
      q: 'anna',
      // these MUST NOT appear — the server expansion ignores paging/order/group
      page: 5,
      per_page: 25,
      sort: 'name',
      order: 'asc',
      group_by: 'status',
    });
    expect(payload).toEqual({
      status: 'pending',
      edition_id: 42,
      company_id: 7,
      trajectory_id: 3,
      q: 'anna',
    });
  });

  test('numeric filters are coerced to numbers (string-from-select tolerated)', () => {
    const payload = grid.gridFilterPayload({ edition_id: '42', company_id: '7' });
    expect(payload).toEqual({ edition_id: 42, company_id: 7 });
  });

  test('EDGE: all-empty filters → {} (empty payload = whole active set, not malformed)', () => {
    expect(grid.gridFilterPayload({ status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' })).toEqual({});
    expect(grid.gridFilterPayload({})).toEqual({});
    expect(grid.gridFilterPayload(undefined)).toEqual({});
  });
});

/**
 * gridStateToParams / gridStateFromParams — the URL round-trip (Tier A).
 *
 * The grid syncs its full view state (filters, search, sort, page, per_page,
 * group_by) into the browser URL via replaceState, so a filtered view is
 * bookmarkable / reload-safe / shareable. shell.js already owns ?view=/?queue=
 * /?user=/?reg= — these two mappers are the grid's HALF of the same URL, and
 * the contract that keeps the two halves from clobbering each other is:
 *
 *   toParams   — emits ONLY non-default state (a pristine grid → {}), so the
 *                URL stays clean and the omit-empty rule matches the fetch.
 *   fromParams — coerces numerics, and on a malformed / unknown value falls
 *                back to the default instead of setting NaN or an arbitrary
 *                group_by (the denial branch: a bogus ?group_by=x must never
 *                become an active grouping the server never allow-listed).
 *
 * Round-trip identity: fromParams(new URLSearchParams(toParams(s))) reproduces
 * the meaningful subset of s.
 */
test.describe('gridStateToParams', () => {
  const pristine = () => ({
    filters: { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' },
    sortKey: '', sortDir: 'asc', groupBy: '', page: 1, perPage: 25,
  });

  test('pristine state → {} (a default grid writes NOTHING to the URL)', () => {
    expect(grid.gridStateToParams(pristine())).toEqual({});
    expect(grid.gridStateToParams(undefined)).toEqual({});
  });

  test('emits only the set filters + search (omits zero/empty, string-coerces ids)', () => {
    const s = { ...pristine(), filters: { status: 'confirmed', edition_id: 42, company_id: 0, trajectory_id: 3, q: 'anna' } };
    expect(grid.gridStateToParams(s)).toEqual({
      status: 'confirmed', edition_id: '42', trajectory_id: '3', q: 'anna',
    });
  });

  test('sort emits sort+order ONLY when a sortKey is set', () => {
    expect(grid.gridStateToParams({ ...pristine(), sortKey: 'name', sortDir: 'desc' }))
      .toEqual({ sort: 'name', order: 'desc' });
    // no sortKey → neither sort nor order, even if sortDir drifted from default
    expect(grid.gridStateToParams({ ...pristine(), sortKey: '', sortDir: 'desc' })).toEqual({});
  });

  test('page emits ONLY when > 1; per_page ONLY when ≠ default 25; group_by when set', () => {
    expect(grid.gridStateToParams({ ...pristine(), page: 3 })).toEqual({ page: '3' });
    expect(grid.gridStateToParams({ ...pristine(), page: 1 })).toEqual({});
    expect(grid.gridStateToParams({ ...pristine(), perPage: 50 })).toEqual({ per_page: '50' });
    expect(grid.gridStateToParams({ ...pristine(), perPage: 25 })).toEqual({});
    expect(grid.gridStateToParams({ ...pristine(), groupBy: 'edition_id' })).toEqual({ group_by: 'edition_id' });
  });
});

test.describe('gridStateFromParams', () => {
  const from = (qs) => grid.gridStateFromParams(new URLSearchParams(qs));

  test('parses filters + search back, coercing ids to numbers', () => {
    const s = from('status=confirmed&edition_id=42&trajectory_id=3&q=anna');
    expect(s.filters).toEqual({ status: 'confirmed', edition_id: 42, company_id: 0, trajectory_id: 3, q: 'anna' });
  });

  test('parses sort/order, page, per_page, group_by', () => {
    const s = from('sort=name&order=desc&page=4&per_page=50&group_by=status');
    expect(s.sortKey).toBe('name');
    expect(s.sortDir).toBe('desc');
    expect(s.page).toBe(4);
    expect(s.perPage).toBe(50);
    expect(s.groupBy).toBe('status');
  });

  test('empty URL → default state patch (no filters set, page 1)', () => {
    const s = from('');
    expect(s.filters).toEqual({ status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' });
    expect(s.page).toBe(1);
    expect(s.sortKey).toBe('');
    expect(s.groupBy).toBe('');
  });

  test('DENIAL: malformed numerics fall back to default, never NaN', () => {
    const s = from('page=abc&per_page=notanumber&edition_id=xyz');
    expect(s.page).toBe(1);           // not NaN
    expect(s.perPage).toBe(25);       // not NaN
    expect(s.filters.edition_id).toBe(0);
    expect(Number.isNaN(s.page)).toBe(false);
    expect(Number.isNaN(s.perPage)).toBe(false);
  });

  test('DENIAL: unknown group_by / bogus order → default, never an arbitrary grouping', () => {
    // group_by is server-allow-listed to edition_id|status|company_id — a bogus
    // value must NOT become an active grouping (it would send an un-allow-listed
    // group_by to the server and render a broken grouped view).
    expect(from('group_by=DROP').groupBy).toBe('');
    expect(from('group_by=trajectory_id').groupBy).toBe('');
    // order is asc|desc only; a bogus order coerces to the default asc.
    expect(from('sort=name&order=sideways').sortDir).toBe('asc');
  });

  test('round-trip identity: fromParams(toParams(s)) reproduces the state', () => {
    const s = {
      filters: { status: 'confirmed', edition_id: 42, company_id: 7, trajectory_id: 3, q: 'anna' },
      sortKey: 'name', sortDir: 'desc', groupBy: 'status', page: 4, perPage: 50,
    };
    const round = grid.gridStateFromParams(new URLSearchParams(grid.gridStateToParams(s)));
    expect(round.filters).toEqual(s.filters);
    expect(round.sortKey).toBe(s.sortKey);
    expect(round.sortDir).toBe(s.sortDir);
    expect(round.groupBy).toBe(s.groupBy);
    expect(round.page).toBe(s.page);
    expect(round.perPage).toBe(s.perPage);
  });
});
