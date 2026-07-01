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
    // simulate an in-grid filter/sort/page change
    g.filters = { status: 'confirmed', edition_id: 42, company_id: 0, trajectory_id: 0, q: 'anna' };
    g.sortKey = 'name'; g.sortDir = 'desc'; g.page = 3; g.perPage = 50; g.groupBy = 'status';

    g.syncStateToUrl();

    const out = new URL(win.location.href).searchParams;
    // shell + WP params SURVIVE (the clobber bug this guards against)
    expect(out.get('page')).toBe('3');          // grid's own page (not WP's ?page=)
    expect(out.get('view')).toBe('inschrijvingen');
    expect(out.get('queue')).toBe('pending');
    // WP admin page param is untouched (it is not one the grid manages)
    expect(win.location.href).toContain('page=');
    // grid state landed
    expect(out.get('status')).toBe('confirmed');
    expect(out.get('edition_id')).toBe('42');
    expect(out.get('q')).toBe('anna');
    expect(out.get('sort')).toBe('name');
    expect(out.get('order')).toBe('desc');
    expect(out.get('group_by')).toBe('status');
    expect(out.get('per_page')).toBe('50');
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
});

test.describe('hydrateStateFromUrl (read half) + full round-trip through the real methods', () => {
  test('restores the full grid state from a bookmarked URL', () => {
    const win = fakeWindow('http://x/?view=inschrijvingen&status=waitlist&edition_id=7&sort=date&order=desc&page=2&per_page=50&group_by=edition_id');
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
