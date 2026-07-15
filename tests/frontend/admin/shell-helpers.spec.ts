/**
 * Unit: shared WS helpers (Phase 3d) — wsPageList + wsNonceExpired.
 *
 * wsPageList is THE compact pager model (1 … cur-1 cur cur+1 … last) that
 * replaced five identical per-surface copies (grid/trajecten/edities/
 * offertes/gebruikers). Its contract already carries a bug history: the two
 * '…' entries are IDENTICAL strings, so pager templates must key by index —
 * this spec pins the shape those templates render.
 *
 * wsNonceExpired is the F-S5 gate: the ONE place that recognizes the
 * expired-nonce REST failure (rest_cookie_invalid_nonce), dispatches the
 * ws-nonce-expired event the shell's Vernieuwen banner latches on, and hands
 * the caller the Dutch message. Anything else returns null so every caller
 * keeps its own error copy.
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const shell = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/shell.js');

test.describe('wsPageList (the shared pager model)', () => {
  test('7 pages or fewer render verbatim — no ellipsis', () => {
    expect(shell.wsPageList(1, 1)).toEqual([1]);
    expect(shell.wsPageList(3, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  test('a middle page gets BOTH ellipses around the cur-1..cur+1 window', () => {
    expect(shell.wsPageList(5, 9)).toEqual([1, '…', 4, 5, 6, '…', 9]);
  });

  test('near the front only the tail ellipsis renders (window touches page 1)', () => {
    expect(shell.wsPageList(2, 9)).toEqual([1, 2, 3, '…', 9]);
    expect(shell.wsPageList(3, 9)).toEqual([1, 2, 3, 4, '…', 9]);
  });

  test('near the back only the head ellipsis renders (window touches the last page)', () => {
    expect(shell.wsPageList(8, 9)).toEqual([1, '…', 7, 8, 9]);
    expect(shell.wsPageList(9, 9)).toEqual([1, '…', 8, 9]);
  });

  test('CONTRACT: the two ellipsis entries are identical strings — templates must key by INDEX', () => {
    const out = shell.wsPageList(50, 100);
    const dots = out.filter((p: unknown) => p === '…');
    expect(dots.length).toBe(2); // duplicate values → :key="pi", never :key="p"
  });
});

test.describe('wsNonceExpired (F-S5 expired-nonce gate)', () => {
  test('any non-nonce failure returns null — callers keep their own message', () => {
    expect(shell.wsNonceExpired(null)).toBeNull();
    expect(shell.wsNonceExpired(undefined)).toBeNull();
    expect(shell.wsNonceExpired({})).toBeNull();
    expect(shell.wsNonceExpired({ code: 'rest_forbidden', message: 'nee' })).toBeNull();
  });

  test('rest_cookie_invalid_nonce returns the Dutch message AND dispatches ws-nonce-expired', () => {
    const events: string[] = [];
    (global as any).window = {
      dispatchEvent: (e: any) => { events.push(e.type); },
    };
    try {
      const msg = shell.wsNonceExpired({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' });
      expect(msg).toBe('Je sessie is verlopen. Vernieuw de pagina om verder te werken.');
      expect(events).toEqual(['ws-nonce-expired']);
    } finally {
      delete (global as any).window;
    }
  });
});
