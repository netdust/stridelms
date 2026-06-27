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
