/**
 * Unit: global search palette (⌘K, Phase 3c / F-S1, Tier A).
 *
 * The palette's falsifiable contracts:
 *  - flattenResults: the arrow-key list is users→editions→trajectories in
 *    order (flatIndex must agree with it, or the highlight walks one list
 *    while Enter picks from another);
 *  - pick routing: a person opens the dossier, an edition/trajectory opens
 *    the grid SCOPED to it via the shell deep-link whitelist;
 *  - the min-length gate and the request token (a stale response or a
 *    response landing after close must never render).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const gsearch = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/gsearch.js');

const factory = () => {
  const f = gsearch.gsearch();
  f.$nextTick = (cb: () => void) => cb();
  f.$refs = {};
  return f;
};

test.describe('flattenResults / flatIndex agreement', () => {
  test('flat order is users → editions → trajectories and flatIndex matches it', () => {
    const f = factory();
    f.results = {
      users: [{ id: 1 }, { id: 2 }],
      editions: [{ id: 10 }],
      trajectories: [{ id: 20 }],
    };
    const flat = f.flat;
    expect(flat.map((h: any) => h.group)).toEqual(['users', 'users', 'editions', 'trajectories']);
    expect(f.flatIndex('users', 1)).toBe(1);
    expect(f.flatIndex('editions', 0)).toBe(2);
    expect(f.flatIndex('trajectories', 0)).toBe(3);
    // The invariant that keeps highlight and Enter in sync. pick() closes the
    // palette (clearing results), so each probe gets a fresh factory.
    flat.forEach((hit: any, i: number) => {
      const g = factory();
      g.results = {
        users: [{ id: 1 }, { id: 2 }],
        editions: [{ id: 10 }],
        trajectories: [{ id: 20 }],
      };
      g.active = i;
      const jumps: any[] = [];
      g.switchView = (view: string, params: any) => jumps.push([view, params]);
      g.pickActive();
      expect(jumps.length).toBe(1);
      expect(JSON.stringify(jumps[0][1])).toContain(String(hit.item.id));
    });
  });
});

test.describe('pick routing', () => {
  test('person → dossier; edition/trajectory → the grid scoped to it', () => {
    const f = factory();
    const jumps: any[] = [];
    f.switchView = (view: string, params: any) => jumps.push([view, params]);
    f.pick('users', { id: 7 });
    f.pick('editions', { id: 12 });
    f.pick('trajectories', { id: 9 });
    expect(jumps).toEqual([
      ['dossier', { user: 7 }],
      ['inschrijvingen', { edition_id: 12 }],
      ['inschrijvingen', { trajectory_id: 9 }],
    ]);
  });

  test('DENIAL: an id-less hit never navigates', () => {
    const f = factory();
    const jumps: any[] = [];
    f.switchView = (view: string, params: any) => jumps.push([view, params]);
    f.pick('users', { id: 0 });
    f.pick('editions', null);
    expect(jumps).toEqual([]);
  });
});

test.describe('search gating + staleness', () => {
  test('a query below 2 code points never fetches (single emoji included)', async () => {
    const f = factory();
    let calls = 0;
    f.api = async () => { calls++; return { items: [] }; };
    f.q = 'a';
    await f.search();
    f.q = '😀';
    await f.search();
    expect(calls).toBe(0);
    expect(f.searched).toBe(false);
  });

  test('closing the palette drops an in-flight response', async () => {
    const f = factory();
    const resolvers: any[] = [];
    f.api = () => new Promise((res) => { resolvers.push(res); }); // all three fetches
    f.q = 'anna';
    const pending = f.search();
    f.close();
    resolvers.forEach((res) => res({ items: [{ id: 1 }] }));
    await pending;
    expect(f.results.users).toEqual([]);
    expect(f.open).toBe(false);
  });

  test('a failed group renders its notice while the others still show', async () => {
    const f = factory();
    f.api = async (url: string) => {
      if (url.includes('users/search')) throw new Error('boom');
      return { items: [{ id: 5, title: 'X' }] };
    };
    f.q = 'anna';
    await f.search();
    expect(f.failed.users).toBe(true);
    expect(f.results.editions.length).toBe(1);
    expect(f.results.trajectories.length).toBe(1);
    expect(f.hasResults).toBe(true);
  });
});
