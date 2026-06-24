/* ==========================================================================
   Tier-A behavioral test for the cohort lens's two BRANCHING pure helpers.

   These are the two pieces lifted/extracted from the proven god-component that
   carry real branching logic (not glue): the loaded-set extras-filter option
   builder (CF3 — dedup + per-token count over rows[].extras) and the roster
   bulk safe-state-intersection (the action set offered for a set of selected
   statuses, incl. the empty branch). Everything else in cohort.js is Alpine
   wiring / presentational (Tier B).

   Run with plain node (the app has no JS unit runner; grid.js's UMD tail is a
   future-Playwright hook). RED first: cohort.js does not export these yet.
     node assets/js/admin/cohort.test.cjs
   ========================================================================== */
'use strict';

const assert = require('assert');
const { cohortExtrasOptionsFrom, rosterActionsForStates, ROSTER_ACTIONS } = require('./cohort.js');

let pass = 0;
function it(name, fn) {
  fn();
  pass++;
  // eslint-disable-next-line no-console
  console.log('  ok - ' + name);
}

/* ---- cohortExtrasOptionsFrom (CF3 loaded-set builder) ------------------- */

it('builds one option per distinct key=value with a count across loaded rows', () => {
  const rows = [
    { extras: { lunch: 'veg', shirt: 'M' } },
    { extras: { lunch: 'veg', shirt: 'L' } },
    { extras: { lunch: 'meat' } },
  ];
  const opts = cohortExtrasOptionsFrom(rows);
  const byToken = Object.fromEntries(opts.map((o) => [o.token, o]));
  assert.strictEqual(byToken['lunch=veg'].count, 2, 'lunch=veg counted twice');
  assert.strictEqual(byToken['lunch=meat'].count, 1);
  assert.strictEqual(byToken['shirt=M'].count, 1);
  assert.strictEqual(byToken['shirt=L'].count, 1);
  assert.strictEqual(byToken['lunch=veg'].key, 'lunch');
  assert.strictEqual(byToken['lunch=veg'].value, 'veg');
});

it('returns [] when no row carries extras (empty branch)', () => {
  assert.deepStrictEqual(cohortExtrasOptionsFrom([]), []);
  assert.deepStrictEqual(cohortExtrasOptionsFrom([{ extras: {} }, { extras: null }, {}]), []);
});

it('coerces non-string extra values to a string token without crashing', () => {
  const opts = cohortExtrasOptionsFrom([{ extras: { count: 3 } }, { extras: { count: 3 } }]);
  assert.strictEqual(opts.length, 1);
  assert.strictEqual(opts[0].token, 'count=3');
  assert.strictEqual(opts[0].value, '3');
  assert.strictEqual(opts[0].count, 2);
});

/* ---- rosterActionsForStates (safe intersection) ------------------------- */

it('offers approve only when EVERY selected state can be approved', () => {
  const ids = (st) => rosterActionsForStates(st).map((a) => a.id);
  assert.ok(ids(['pending']).includes('approve'), 'pending alone → approve offered');
  assert.ok(ids(['pending', 'waitlist']).includes('approve'), 'pending+waitlist both approvable');
  // confirmed is NOT in approve.states → approve drops out of the intersection
  assert.ok(!ids(['pending', 'confirmed']).includes('approve'), 'mixed with confirmed → no approve');
});

it('returns [] for an empty selection (empty branch — no actions on nothing)', () => {
  assert.deepStrictEqual(rosterActionsForStates([]), []);
});

it('intersects to [] when no action covers all selected states (mixed hint case)', () => {
  // interest: approve+message ; completed: message+generate_doc.
  // Only `message` is shared → that is the intersection (NOT empty).
  const shared = rosterActionsForStates(['interest', 'completed']).map((a) => a.id);
  assert.deepStrictEqual(shared, ['message']);
  // A state outside every action's set yields [].
  assert.deepStrictEqual(rosterActionsForStates(['cancelled']).map((a) => a.id), []);
});

it('ROSTER_ACTIONS carries scope-agnostic ids the cohortActionName() can prefix', () => {
  const idSet = ROSTER_ACTIONS.map((a) => a.id);
  assert.deepStrictEqual(idSet, ['approve', 'message', 'generate_doc']);
});

// eslint-disable-next-line no-console
console.log('\n' + pass + ' assertions passed');
