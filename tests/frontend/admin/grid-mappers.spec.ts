/**
 * Unit: Inschrijvingen grid pure mappers (Cluster C, Tier A).
 *
 * The grid is overwhelmingly Tier B (presentational — verified by the
 * cold-landing screenshot gate). These three helpers are the exceptions: each
 * carries real branching logic with a falsifiable contract, so each gets a
 * RED-first behavioural test incl. its empty/edge + denial branch.
 *
 *   1. queueToParams(queueKey) — validates a Vandaag deep-link queue key
 *      against the closed queue table and passes it through as the endpoint's
 *      ?queue= param (the server resolves it to the SAME id-set the Vandaag
 *      card counted — WorklistQueueResolver, RC-2). The DENIAL branch is an
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
  test('every known queue key passes through as the ?queue= param (server-resolved id-set)', () => {
    for (const key of ['pending', 'waitlist', 'offerte', 'nocert', 'oldinterest', 'interest_to_invite']) {
      expect(grid.queueToParams(key)).toEqual({ queue: key });
    }
  });

  test('a queue key never degrades to a bare status filter (the RC-2 drift bug)', () => {
    // The old mapping approximated e.g. nocert → status=completed, so the card
    // said "3" and the grid showed ALL completed rows. The contract now is the
    // queue key itself — the server owns the predicate.
    expect(grid.queueToParams('nocert')).not.toHaveProperty('status');
    expect(grid.queueToParams('offerte')).not.toHaveProperty('status');
  });

  test('DENIAL: unknown queue key → {} (no fabricated backend param, no crash)', () => {
    expect(grid.queueToParams('not-a-queue')).toEqual({});
    expect(grid.queueToParams('')).toEqual({});
    expect(grid.queueToParams(undefined)).toEqual({});
    // Adversarial: a key outside the closed queue table must never echo
    // through — the endpoint 400s on unknown queues, so fabricating one would
    // surface a hard error for a merely-stale deep-link.
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
 * groupRowsFrom(group) — the accordion per-group mapper (Tier A).
 *
 * Task 6: the grouped endpoint item now carries its own child rows
 * ({ group_value, count, rows, row_total, pct_afgerond, avg_attendance_pct,
 * offerte_verdeling }). This PURE mapper turns each server group into the shape
 * the accordion template iterates. Its two load-bearing jobs:
 *
 *   - a STABLE String `key` — collapsed[key] / toggleGroup(key) are keyed by it,
 *     so a null group_value (the "Geen editie / Geen organisatie" bucket) must
 *     coerce to a stable, non-crashing string, NOT `null`/`undefined` (which
 *     would make collapsed[undefined] alias every null-value group together).
 *   - `hasMore` — the server caps `rows` at 8 but reports the true `row_total`;
 *     hasMore drives the "Toon alle N" affordance and MUST be true exactly when
 *     row_total exceeds the number of composed rows shipped.
 *
 * It stays PURE (no `this`) so the template still calls the instance-bound
 * groupLabel(g) for the display label — group_value is passed through so that
 * call still resolves.
 */
test.describe('groupRowsFrom', () => {
  test('maps a grouped item to {key,rows,hasMore,rowTotal,count} carrying its child rows', () => {
    const rows = [{ id: 1 }, { id: 2 }, { id: 3 }];
    const g = {
      group_value: 42,
      count: 12,
      rows,
      row_total: 12,
      pct_afgerond: 50,
      avg_attendance_pct: 88,
      offerte_verdeling: { Verzonden: 3 },
    };
    const out = grid.groupRowsFrom(g);
    expect(out.key).toBe('42');            // stable String key for collapsed[]/toggleGroup()
    expect(out.rows).toBe(rows);           // the child rows ride through unchanged
    expect(out.count).toBe(12);
    expect(out.rowTotal).toBe(12);
    expect(out.group_value).toBe(42);      // passthrough so groupLabel(g) still resolves
    expect(out.pct_afgerond).toBe(50);
    expect(out.avg_attendance_pct).toBe(88);
    expect(out.offerte_verdeling).toEqual({ Verzonden: 3 });
  });

  test('hasMore is TRUE when row_total exceeds the shipped rows (the capped case)', () => {
    // Server caps rows at 8 but reports the real total → "Toon alle N" must show.
    const g = { group_value: 7, count: 30, rows: new Array(8).fill(0).map((_, i) => ({ id: i })), row_total: 30 };
    expect(grid.groupRowsFrom(g).hasMore).toBe(true);
    expect(grid.groupRowsFrom(g).rowTotal).toBe(30);
  });

  test('hasMore is FALSE when every row is shipped (row_total === rows.length)', () => {
    const g = { group_value: 7, count: 3, rows: [{ id: 1 }, { id: 2 }, { id: 3 }], row_total: 3 };
    expect(grid.groupRowsFrom(g).hasMore).toBe(false);
  });

  test('EDGE: a null group_value coerces to a stable string key without crashing', () => {
    // The "Geen editie" / "Geen organisatie" bucket has group_value === null.
    // If key were left null/undefined, collapsed[undefined] would alias EVERY
    // null-value group into one toggle — the key MUST be a stable string.
    const g = { group_value: null, count: 4, rows: [], row_total: 4 };
    const out = grid.groupRowsFrom(g);
    expect(typeof out.key).toBe('string');
    expect(out.key).toBe('');              // String(null ?? '') → ''
    expect(out.rows).toEqual([]);          // missing/empty rows → [] (never undefined)
    expect(out.hasMore).toBe(true);        // 4 total, 0 shipped
  });

  test('EDGE: a group with no rows array at all → rows:[], hasMore from row_total', () => {
    const out = grid.groupRowsFrom({ group_value: 'x', count: 0, row_total: 0 });
    expect(out.rows).toEqual([]);
    expect(out.hasMore).toBe(false);       // 0 total, 0 shipped
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

  test('page emits as `p` (NOT `page`) ONLY when > 1; per_page ONLY when ≠ default 25; group_by when set', () => {
    // `p`, never `page` — `page` is WP admin's routing key (?page=stride-dashboard).
    expect(grid.gridStateToParams({ ...pristine(), page: 3 })).toEqual({ p: '3' });
    expect(grid.gridStateToParams({ ...pristine(), page: 1 })).toEqual({});
    expect(grid.gridStateToParams({ ...pristine(), perPage: 50 })).toEqual({ per_page: '50' });
    expect(grid.gridStateToParams({ ...pristine(), perPage: 25 })).toEqual({});
    expect(grid.gridStateToParams({ ...pristine(), groupBy: 'edition_id' })).toEqual({ group_by: 'edition_id' });
  });

  test('REGRESSION: never emits a `page` key (would collide with WP admin routing)', () => {
    // The grid's pagination MUST NOT serialize to ?page= — WordPress routes the
    // whole admin screen on ?page=stride-dashboard, so a grid `page` key deletes
    // WP's and blanks the dashboard on reload. Assert `page` is never produced.
    const s = { ...pristine(), page: 5, perPage: 50, filters: { status: 'pending', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' } };
    expect(grid.gridStateToParams(s)).not.toHaveProperty('page');
    expect(grid.gridStateToParams(s).p).toBe('5');
  });
});

test.describe('gridStateFromParams', () => {
  const from = (qs) => grid.gridStateFromParams(new URLSearchParams(qs));

  test('parses filters + search back, coercing ids to numbers', () => {
    const s = from('status=confirmed&edition_id=42&trajectory_id=3&q=anna');
    expect(s.filters).toEqual({ status: 'confirmed', edition_id: 42, company_id: 0, trajectory_id: 3, q: 'anna' });
  });

  test('parses sort/order, page (from `p`), per_page, group_by', () => {
    const s = from('sort=name&order=desc&p=4&per_page=50&group_by=status');
    expect(s.sortKey).toBe('name');
    expect(s.sortDir).toBe('desc');
    expect(s.page).toBe(4);
    expect(s.perPage).toBe(50);
    expect(s.groupBy).toBe('status');
  });

  test('REGRESSION: a WP `page=stride-dashboard` in the URL is IGNORED (not read as grid page)', () => {
    // The grid reads pagination from `p`, so WordPress's own ?page= must not be
    // mis-parsed as the grid's page (it would coerce the slug to page 1 anyway,
    // but the contract is: grid pagination lives in `p`, WP routing in `page`).
    const s = from('page=stride-dashboard&status=pending');
    expect(s.page).toBe(1);              // WP's page= did not become the grid page
    expect(s.filters.status).toBe('pending');
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
