/**
 * Unit: the per-surface first-activation guard (I-1, Tier A).
 *
 * wsLazyLoad(self, myView, run) is the ONE piece of branching logic behind the
 * lazy-load behaviour change: a surface loads its data the FIRST time its view
 * becomes active, instead of every surface eager-loading on mount. The realistic
 * regression this pins:
 *   - a surface that is NOT the active view must NOT load on mount (the bug the
 *     finding fixes: 6 unused REST calls fired on a Vandaag landing);
 *   - the active-on-mount surface (default view / deep-link) MUST still cold-load;
 *   - the load must fire AT MOST ONCE even if the view toggles away and back
 *     (the _wsLoaded latch — no double fetch, no reload thrash).
 *
 * The guard reads `self.view` once on mount (Alpine's scope chain) for the
 * active-on-mount surface, then listens for the `ws-view-changed` event the
 * shell dispatches — it does NOT use $watch, because a child x-data's $watch
 * does not observe the parent's `view` mutation (proven against the live
 * dashboard in lazyload-landing.spec.ts). Here we inject a tiny EventTarget bus
 * and drive view changes deterministically through it.
 *
 * shell.js's window block is guarded by `typeof window`, so it requires cleanly
 * under Node and exports the pure guard.
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { wsLazyLoad } = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/shell.js');

function makeSelf(initialView: string) {
  const bus = new EventTarget();
  const self: Record<string, unknown> = { view: initialView };
  // simulate the shell flipping the active view: mutate + broadcast, exactly as
  // the shell's view chokepoint does.
  const setView = (v: string) => {
    self.view = v;
    bus.dispatchEvent(new CustomEvent('ws-view-changed', { detail: { view: v } }));
  };
  return { self, setView, bus };
}

test.describe('wsLazyLoad — first-activation guard', () => {
  test('loads immediately when THIS surface is the active view on mount (default/deep-link)', () => {
    const { self } = makeSelf('vandaag');
    let runs = 0;
    wsLazyLoad(self, 'vandaag', () => { runs += 1; });
    expect(runs).toBe(1); // cold-landing surface cold-loads
  });

  test('DENIAL: does NOT load on mount when another surface is active (the fixed bug)', () => {
    const { self } = makeSelf('vandaag');
    let runs = 0;
    // inschrijvingen is mounted but vandaag is active → must stay cold
    wsLazyLoad(self, 'inschrijvingen', () => { runs += 1; });
    expect(runs).toBe(0);
  });

  test('loads lazily the FIRST time its view becomes active', () => {
    const { self, setView, bus } = makeSelf('vandaag');
    let runs = 0;
    wsLazyLoad(self, 'offertes', () => { runs += 1; }, bus);
    expect(runs).toBe(0);          // not active yet
    setView('offertes');           // user navigates to it
    expect(runs).toBe(1);          // now it loads
  });

  test('fires AT MOST ONCE across repeated activations (the _wsLoaded latch)', () => {
    const { self, setView, bus } = makeSelf('vandaag');
    let runs = 0;
    wsLazyLoad(self, 'edities', () => { runs += 1; }, bus);
    setView('edities');   // first activation → load
    setView('offertes');  // navigate away
    setView('edities');   // navigate back → must NOT reload
    setView('edities');   // again
    expect(runs).toBe(1);
  });

  test('ignores view changes to OTHER surfaces (only its own view triggers it)', () => {
    const { self, setView, bus } = makeSelf('vandaag');
    let runs = 0;
    wsLazyLoad(self, 'sessies', () => { runs += 1; }, bus);
    setView('offertes');
    setView('edities');
    setView('inschrijvingen');
    expect(runs).toBe(0); // never activated sessies → never loaded
  });

  test('REGRESSION: the latch is per-surface even when surfaces share ONE self object', () => {
    // Every per-surface factory inherits the SAME shell scope object in Alpine.
    // A latch stored ON self (self._wsLoaded) would land on that shared object,
    // so the first surface to load would latch ALL of them — and a later
    // activation of a different surface would be wrongly suppressed. The latch is
    // closure-local, so this must NOT happen. Drive two surfaces off one shared
    // self+bus: the second must still load on its own first activation.
    const { self, setView, bus } = makeSelf('vandaag');
    let vandaagRuns = 0;
    let offertesRuns = 0;
    wsLazyLoad(self, 'vandaag', () => { vandaagRuns += 1; }, bus);   // loads on mount
    wsLazyLoad(self, 'offertes', () => { offertesRuns += 1; }, bus); // lazy
    expect(vandaagRuns).toBe(1);   // vandaag cold-loaded
    expect(offertesRuns).toBe(0);  // offertes still cold (NOT latched by vandaag)
    setView('offertes');
    expect(offertesRuns).toBe(1);  // offertes loads on its OWN first activation
  });
});
