/**
 * Unit: Sessies list pure mapper (Cluster F, Tier A).
 *
 * The sessies surface is overwhelmingly Tier B (presentational). The ONE piece
 * with branching logic is groupByDate(rows): the agenda view buckets the flat
 * session rows by their `date`, PRESERVING first-seen order (the agenda is
 * chronological as the server emits it — the buckets must not be re-sorted or
 * the agenda jumps around). A wrong bucketer ships a real bug: sessions on the
 * same day split across buckets, or the day order scrambles. Falsifiable +
 * branching → RED-first test incl. its empty/edge branch.
 *
 * Imported via the UMD tail on sessies.js (module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const sessies = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/sessies.js');

test.describe('groupByDate (agenda day bucketing)', () => {
  test('buckets rows by date, preserving first-seen day order', () => {
    const rows = [
      { id: 1, date: '2026-07-01' },
      { id: 2, date: '2026-07-01' },
      { id: 3, date: '2026-07-02' },
    ];
    const groups = sessies.groupByDate(rows);
    expect(groups.map((g: { date: string }) => g.date)).toEqual(['2026-07-01', '2026-07-02']);
    expect(groups[0].rows.map((r: { id: number }) => r.id)).toEqual([1, 2]);
    expect(groups[1].rows.map((r: { id: number }) => r.id)).toEqual([3]);
  });

  test('does NOT re-order days — first appearance wins even if dates arrive out of order', () => {
    const rows = [
      { id: 1, date: '2026-08-10' },
      { id: 2, date: '2026-08-01' },
      { id: 3, date: '2026-08-10' },
    ];
    const groups = sessies.groupByDate(rows);
    // server order is honoured: the later calendar date appears first because it
    // was seen first — the bucketer must not sort.
    expect(groups.map((g: { date: string }) => g.date)).toEqual(['2026-08-10', '2026-08-01']);
    expect(groups[0].rows.map((r: { id: number }) => r.id)).toEqual([1, 3]);
  });

  test('EDGE: a row with a missing date buckets under "" without crashing', () => {
    const groups = sessies.groupByDate([{ id: 1 }, { id: 2, date: '' }]);
    expect(groups.length).toBe(1);
    expect(groups[0].date).toBe('');
    expect(groups[0].rows.map((r: { id: number }) => r.id)).toEqual([1, 2]);
  });

  test('DENIAL: empty / non-array input → [] (never a crash, never undefined)', () => {
    expect(sessies.groupByDate([])).toEqual([]);
    expect(sessies.groupByDate(undefined)).toEqual([]);
    expect(sessies.groupByDate(null)).toEqual([]);
    expect(sessies.groupByDate('nope')).toEqual([]);
  });
});
