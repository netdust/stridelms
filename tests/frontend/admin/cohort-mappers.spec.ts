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
 *   2. cohortApplyMark(row, sessionId, status) — the F-C2 optimistic mark
 *      applier: patches the per-session map (status '' clears) and recomputes
 *      the aggregate FROM that map — the same definition the server derives
 *      its counts from, so an optimistic patch and the next fetch can never
 *      disagree. Purity matters: it must return a NEW row (rollback keeps the
 *      previous object).
 *
 * (rosterActionsForStates and the ROSTER_ACTIONS catalog were REMOVED with the
 * bulk bar — decision 5a, F-C1: the cohort roster is confirmed/completed only,
 * so the one lifecycle action could never appear.)
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

test.describe('cohortApplyMark (F-C2 optimistic mark applier)', () => {
  const row = () => ({
    registration_id: 1,
    attendance_by_session: { '10': 'present', '11': 'absent' },
    attendance: { present: 1, absent: 1, excused: 0 },
  });

  test('stamps a new mark and recomputes the aggregate from the map', () => {
    const next = cohort.cohortApplyMark(row(), 12, 'excused');
    expect(next.attendance_by_session).toEqual({ '10': 'present', '11': 'absent', '12': 'excused' });
    expect(next.attendance).toEqual({ present: 1, absent: 1, excused: 1 });
  });

  test('overwrites an existing mark for the same session (latest wins, no double count)', () => {
    const next = cohort.cohortApplyMark(row(), 10, 'absent');
    expect(next.attendance_by_session['10']).toBe('absent');
    expect(next.attendance).toEqual({ present: 0, absent: 2, excused: 0 });
  });

  test("status '' CLEARS the session's mark", () => {
    const next = cohort.cohortApplyMark(row(), 10, '');
    expect(next.attendance_by_session).toEqual({ '11': 'absent' });
    expect(next.attendance).toEqual({ present: 0, absent: 1, excused: 0 });
  });

  test('PURITY: returns a NEW row and never mutates the input (the rollback snapshot)', () => {
    const before = row();
    const next = cohort.cohortApplyMark(before, 12, 'present');
    expect(next).not.toBe(before);
    expect(before.attendance_by_session).toEqual({ '10': 'present', '11': 'absent' });
    expect(before.attendance).toEqual({ present: 1, absent: 1, excused: 0 });
  });

  test('EDGE: a row without a map starts from empty; numeric and string session ids meet on the string key', () => {
    const next = cohort.cohortApplyMark({ registration_id: 2 }, 7, 'present');
    expect(next.attendance_by_session).toEqual({ '7': 'present' });
    expect(next.attendance).toEqual({ present: 1, absent: 0, excused: 0 });
  });
});
