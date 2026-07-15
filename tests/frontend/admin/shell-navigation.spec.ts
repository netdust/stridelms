/**
 * Unit: shell navigation history contract (Phase 3b, F-S2).
 *
 * The workspace's back-button story rests on three rules:
 *  1. a REAL view switch pushes a history entry carrying the ORIGIN view
 *     ({ wsFrom }) — browser Back returns to where the admin came from,
 *     URL params (their filters/page/search) included;
 *  2. a same-view switchView (deep-link param seeding only) must NOT grow
 *     history — replaceState, or every queue-card click adds a Back step;
 *  3. writeViewToUrl (the $watch URL sync) must PRESERVE history.state —
 *     replaceState(null) would wipe the wsFrom origin the navigation just
 *     recorded, breaking the dossier's origin-aware Terug.
 *
 * Driven against a stubbed URL-backed window/history (the grid-url-sync
 * pattern); wsShell() reads window at call time.
 */

import { test, expect } from '@playwright/test';

function makeWindow(href: string) {
  const w: any = {
    StrideConfig: { defaultView: 'vandaag', nonce: 'n', apiUrl: '' },
    location: { href, search: new URL(href).search },
    pushes: [] as Array<{ state: any; url: string }>,
    history: {
      state: null as any,
      pushState(state: any, _t: string, url: string) {
        this.state = state;
        w.location.href = url;
        w.location.search = new URL(url).search;
        w.pushes.push({ state, url });
      },
      replaceState(state: any, _t: string, url: string) {
        this.state = state;
        w.location.href = url;
        w.location.search = new URL(url).search;
      },
    },
    addEventListener() {},
    dispatchEvent() {},
    CustomEvent: class { constructor(public type: string, public init: any) {} },
  };
  return w;
}

const shellFactory = (w: any) => {
  (global as any).window = w;
  (global as any).URL = URL;
  // eslint-disable-next-line @typescript-eslint/no-var-requires
  const shell = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/shell.js');
  const s = shell.wsShell();
  s.view = 'vandaag';
  return s;
};

test.describe('shell switchView history contract', () => {
  test('a real view switch PUSHES an entry carrying the origin view', () => {
    const w = makeWindow('https://x.test/wp-admin/admin.php?page=stride-dashboard&view=vandaag');
    const s = shellFactory(w);

    s.switchView('inschrijvingen', { queue: 'pending' });

    expect(w.pushes.length).toBe(1);
    expect(w.pushes[0].state).toEqual({ wsFrom: 'vandaag' });
    expect(w.location.href).toContain('queue=pending');
    expect(w.location.href).toContain('view=inschrijvingen');
    expect(w.location.href).toContain('page=stride-dashboard'); // WP param preserved
  });

  test('a same-view param seed replaces — history must not grow per queue click', () => {
    const w = makeWindow('https://x.test/wp-admin/admin.php?page=stride-dashboard&view=inschrijvingen');
    const s = shellFactory(w);
    s.view = 'inschrijvingen';

    s.switchView('inschrijvingen', { queue: 'waitlist' });

    expect(w.pushes.length).toBe(0);
    expect(w.location.href).toContain('queue=waitlist');
  });

  test('writeViewToUrl preserves history.state (the wsFrom origin survives the $watch sync)', () => {
    const w = makeWindow('https://x.test/wp-admin/admin.php?page=stride-dashboard&view=vandaag');
    const s = shellFactory(w);

    s.switchView('dossier', { user: 9 });
    expect(w.history.state).toEqual({ wsFrom: 'vandaag' });

    // The $watch fires writeViewToUrl after the switch — state must survive.
    s.writeViewToUrl('dossier');
    expect(w.history.state).toEqual({ wsFrom: 'vandaag' });
    expect(w.location.href).toContain('view=dossier');
  });

  test('an unknown view never navigates or touches history', () => {
    const w = makeWindow('https://x.test/wp-admin/admin.php?page=stride-dashboard&view=vandaag');
    const s = shellFactory(w);

    s.switchView('evil-view', { user: 1 });

    expect(w.pushes.length).toBe(0);
    expect(s.view).toBe('vandaag');
  });
});
