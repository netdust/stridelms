/**
 * Seam: grid URL round-trip WIRING (Tier A — un-mocked chain).
 *
 * grid-mappers.spec.ts proves the two PURE mappers (gridStateToParams /
 * gridStateFromParams) are correct in isolation. This spec proves the WIRING
 * that connects them into the live component: syncStateToUrl() (write half) and
 * hydrateStateFromUrl() (read half), driven against a real URL/history through
 * the actual `grid()` factory instance — NOT the mappers directly. A wiring bug
 * (wrong keys deleted, shell params clobbered, state not copied onto `this`)
 * passes the mapper tests and fails here.
 *
 * We stub only the browser globals grid.js reaches for (window.location/history),
 * with a faithful URL-backed history so replaceState actually mutates the URL the
 * next read sees — the round-trip is un-mocked end to end.
 */
import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const grid = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/grid.js');

/* A minimal window whose history.replaceState really rewrites location.href, so
   a subsequent hydrateStateFromUrl reads what syncStateToUrl wrote — the real
   browser contract, not a mock that always returns the seeded value. */
function fakeWindow(initialUrl: string) {
  const w: any = {
    location: { href: initialUrl, get search() { return new URL(w.location.href).search; } },
    history: {
      replaceState: (_s: unknown, _t: unknown, url: string) => { w.location.href = url; },
    },
  };
  return w;
}

function makeGrid(win: any) {
  const g = grid.grid();               // the real Alpine factory object
  // Bind the methods to the fake window by installing it as the global for the
  // duration of the call — grid.js reads window.* inside its methods.
  (globalThis as any).window = win;
  return g;
}

test.describe('syncStateToUrl (write half, un-mocked history)', () => {
  test('writes the active grid state into the URL, preserving shell + WP params', () => {
    const win = fakeWindow('http://x/wp/wp-admin/admin.php?page=stride-dashboard&view=inschrijvingen&queue=pending');
    const g = makeGrid(win);
    // simulate an in-grid filter/sort/page change. `queue` is GRID-owned once
    // the deep-link arrived (applyQueueDeepLink stamps it) — the sync writes it
    // from grid state, so the fixture mirrors the real flow and sets it.
    g.queue = 'pending';
    g.filters = { status: 'confirmed', edition_id: 42, company_id: 0, trajectory_id: 0, q: 'anna' };
    g.sortKey = 'name'; g.sortDir = 'desc'; g.page = 3; g.perPage = 50; g.groupBy = 'status';

    g.syncStateToUrl();

    const out = new URL(win.location.href).searchParams;
    // WP's routing param SURVIVES INTACT — assert the SLUG, not just "some page=".
    // (The original bug: the grid wrote its own page to `page`, so `page=`
    // existed but pointed at `3`, silently destroying WP's ?page=stride-dashboard
    // and blanking the whole admin screen on reload.)
    expect(out.get('page')).toBe('stride-dashboard');
    // shell params survive
    expect(out.get('view')).toBe('inschrijvingen');
    expect(out.get('queue')).toBe('pending');
    // the grid's OWN pagination lives in `p`, never `page`
    expect(out.get('p')).toBe('3');
    // grid state landed
    expect(out.get('status')).toBe('confirmed');
    expect(out.get('edition_id')).toBe('42');
    expect(out.get('q')).toBe('anna');
    expect(out.get('sort')).toBe('name');
    expect(out.get('order')).toBe('desc');
    expect(out.get('group_by')).toBe('status');
    expect(out.get('per_page')).toBe('50');
  });

  test('REGRESSION: WP ?page=stride-dashboard survives even at grid page 1 (the clobber bug)', () => {
    // The exact failing path: grid on page 1 (so pagination is OMITTED), a filter
    // applied. The delete-then-set must NOT remove WP's `page` — reload depends on it.
    const win = fakeWindow('http://x/wp/wp-admin/admin.php?page=stride-dashboard&view=inschrijvingen');
    const g = makeGrid(win);
    g.filters = { status: 'pending', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
    g.sortKey = ''; g.page = 1; g.perPage = 25; g.groupBy = '';
    g.syncStateToUrl();
    const out = new URL(win.location.href).searchParams;
    expect(out.get('page')).toBe('stride-dashboard');  // WP routing INTACT
    expect(out.get('status')).toBe('pending');
    expect(out.get('p')).toBeNull();                   // page 1 omitted, no stray `p`
  });

  test('NEGATIVE: clearing a filter DROPS its URL key (does not linger)', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&status=confirmed&edition_id=42');
    const g = makeGrid(win);
    // user cleared everything
    g.filters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
    g.sortKey = ''; g.page = 1; g.perPage = 25; g.groupBy = '';

    g.syncStateToUrl();

    const out = new URL(win.location.href).searchParams;
    expect(out.get('status')).toBeNull();       // the cleared key is GONE
    expect(out.get('edition_id')).toBeNull();
    expect(out.get('view')).toBe('inschrijvingen'); // shell param still preserved
  });

  test('edition scope: only the NON-default (all) is URL-written; active drops the key', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&edition_scope=all');
    const g = makeGrid(win);
    g.filters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
    g.sortKey = ''; g.page = 1; g.perPage = 25; g.groupBy = '';

    g.editionScope = 'all';
    g.syncStateToUrl();
    expect(new URL(win.location.href).searchParams.get('edition_scope')).toBe('all');

    g.editionScope = 'active';   // narrowed back to the default
    g.syncStateToUrl();
    expect(new URL(win.location.href).searchParams.get('edition_scope')).toBeNull();
  });

  test('NEGATIVE: dismissing the queue chip DROPS ?queue= (a reload must not resurrect it)', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&queue=nocert');
    const g = makeGrid(win);
    g.queue = '';   // user removed the "Wachtrij:" chip
    g.filters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
    g.sortKey = ''; g.page = 1; g.perPage = 25; g.groupBy = '';

    g.syncStateToUrl();

    const out = new URL(win.location.href).searchParams;
    expect(out.get('queue')).toBeNull();
    expect(out.get('view')).toBe('inschrijvingen');
  });
});

test.describe('applyQueueDeepLink (the URL is the deep-link contract BOTH ways)', () => {
  test('absorbs ?queue= and clears a leftover status filter', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&queue=pending');
    const g = makeGrid(win);
    g.filters.status = 'confirmed';

    expect(g.applyQueueDeepLink()).toBe(true);
    expect(g.queue).toBe('pending');
    expect(g.filters.status).toBe('');
  });

  test('REGRESSION: a re-activation WITHOUT ?queue= DROPS the stale queue pin', () => {
    // The shell deletes ?queue= on every switchView. Keeping the old pin
    // silently composed it with the new deep-link: Trajecten's "Toon
    // inschrijvingen" set ?trajectory_id=X, and queue=pending AND
    // trajectory_id=X intersected to an empty grid ("Geen resultaten") for a
    // trajectory that has registrations.
    const win = fakeWindow('http://x/?view=inschrijvingen&trajectory_id=5');
    const g = makeGrid(win);
    g.queue = 'pending';   // pinned earlier in the session

    expect(g.applyQueueDeepLink()).toBe(true);
    expect(g.queue).toBe('');                    // stale pin dropped
    expect(g.filters.trajectory_id).toBe(5);     // new deep-link absorbed
  });

  test('repeat activation with the SAME queue is a no-op (no reload, no filter stomp)', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&queue=nocert');
    const g = makeGrid(win);
    g.queue = 'nocert';
    g.filters.q = 'anna';  // the user's in-grid search must survive

    expect(g.applyQueueDeepLink()).toBe(false);
    expect(g.queue).toBe('nocert');
    expect(g.filters.q).toBe('anna');
  });
});

test.describe('hydrateStateFromUrl (read half) + full round-trip through the real methods', () => {
  test('restores the full grid state from a bookmarked URL', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&status=waitlist&edition_id=7&sort=date&order=desc&p=2&per_page=50&group_by=edition_id');
    const g = makeGrid(win);

    g.hydrateStateFromUrl();

    expect(g.filters.status).toBe('waitlist');
    expect(g.filters.edition_id).toBe(7);
    expect(g.sortKey).toBe('date');
    expect(g.sortDir).toBe('desc');
    expect(g.page).toBe(2);
    expect(g.perPage).toBe(50);
    expect(g.groupBy).toBe('edition_id');
  });

  test('round-trip: sync then hydrate a fresh instance reproduces the state', () => {
    const win = fakeWindow('http://x/?page=stride-dashboard&view=inschrijvingen');
    const writer = makeGrid(win);
    writer.filters = { status: 'completed', edition_id: 11, company_id: 3, trajectory_id: 0, q: 'jan' };
    writer.sortKey = 'name'; writer.sortDir = 'asc'; writer.page = 4; writer.perPage = 25; writer.groupBy = 'company_id';
    writer.syncStateToUrl();

    // a NEW instance lands on that written URL (simulates reload)
    const reader = makeGrid(win);
    reader.hydrateStateFromUrl();

    expect(reader.filters).toEqual(writer.filters);
    expect(reader.sortKey).toBe('name');
    expect(reader.sortDir).toBe('asc');
    expect(reader.page).toBe(4);
    expect(reader.groupBy).toBe('company_id');
  });
});
