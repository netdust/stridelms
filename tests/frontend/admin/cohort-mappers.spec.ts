/**
 * Unit: Cohort lens pure mappers (Cluster G, Tier A).
 *
 * Ported from the standalone cohort.test.cjs (no runner ever invoked it, so its
 * assertions were green-but-blind). The cohort surface is overwhelmingly Tier B
 * (Alpine wiring / presentational). The TWO pieces with real branching logic:
 *
 *   1. cohortExtrasOptionsFrom(rows) — the CF3 loaded-set-ONLY extras filter
 *      builder: dedup + per-token count over rows[].extras. The leak guard is
 *      load-bearing — an option may ONLY come from a row actually in the loaded
 *      set (never an invented/echoed key), so the denial branch (no row carries
 *      extras → []) is the contract that matters.
 *
 *   2. rosterActionsForStates(states) — the roster bulk safe state-intersection:
 *      an action is offered only when EVERY selected state supports it. The
 *      empty-selection branch and the no-shared-action branch are the falsifiable
 *      contracts (offering a bulk action a mixed selection can't all take ships a
 *      real "approve a confirmed registration" bug).
 *
 * Imported directly via the UMD tail on cohort.js (module.exports under Node),
 * exactly like the sibling *-mappers specs.
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const cohort = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/cohort.js');

test.describe('cohortExtrasOptionsFrom (CF3 loaded-set builder)', () => {
  test('builds one option per distinct key=value with a count across loaded rows', () => {
    const rows = [
      { extras: { lunch: 'veg', shirt: 'M' } },
      { extras: { lunch: 'veg', shirt: 'L' } },
      { extras: { lunch: 'meat' } },
    ];
    const opts = cohort.cohortExtrasOptionsFrom(rows);
    const byToken = Object.fromEntries(opts.map((o: { token: string }) => [o.token, o]));
    expect(byToken['lunch=veg'].count).toBe(2);
    expect(byToken['lunch=meat'].count).toBe(1);
    expect(byToken['shirt=M'].count).toBe(1);
    expect(byToken['shirt=L'].count).toBe(1);
    expect(byToken['lunch=veg'].key).toBe('lunch');
    expect(byToken['lunch=veg'].value).toBe('veg');
  });

  test('DENIAL: returns [] when no row carries extras (loaded-set leak guard, empty branch)', () => {
    expect(cohort.cohortExtrasOptionsFrom([])).toEqual([]);
    // rows present but none carry usable extras → an option may NEVER be invented
    expect(cohort.cohortExtrasOptionsFrom([{ extras: {} }, { extras: null }, {}])).toEqual([]);
  });

  test('coerces a non-string extra value to a string token without crashing', () => {
    const opts = cohort.cohortExtrasOptionsFrom([{ extras: { count: 3 } }, { extras: { count: 3 } }]);
    expect(opts.length).toBe(1);
    expect(opts[0].token).toBe('count=3');
    expect(opts[0].value).toBe('3');
    expect(opts[0].count).toBe(2);
  });
});

test.describe('rosterActionsForStates (safe intersection)', () => {
  const ids = (st: string[]) => cohort.rosterActionsForStates(st).map((a: { id: string }) => a.id);

  test('offers approve only when EVERY selected state can be approved', () => {
    expect(ids(['pending'])).toContain('approve');
    expect(ids(['pending', 'waitlist'])).toContain('approve');
    // confirmed is NOT in approve.states → approve drops out of the intersection
    expect(ids(['pending', 'confirmed'])).not.toContain('approve');
  });

  test('DENIAL: returns [] for an empty selection (no actions on nothing)', () => {
    expect(cohort.rosterActionsForStates([])).toEqual([]);
  });

  test('intersects to the SHARED action, or [] when none covers all selected states', () => {
    // interest: approve+message ; completed: message+generate_doc.
    // Only `message` is shared → that is the intersection (NOT empty).
    expect(ids(['interest', 'completed'])).toEqual(['message']);
    // A state outside every action's set yields [].
    expect(ids(['cancelled'])).toEqual([]);
  });

  test('ROSTER_ACTIONS carries scope-agnostic ids the cohortActionName() can prefix', () => {
    expect(cohort.ROSTER_ACTIONS.map((a: { id: string }) => a.id)).toEqual([
      'approve', 'message', 'generate_doc',
    ]);
  });
});
