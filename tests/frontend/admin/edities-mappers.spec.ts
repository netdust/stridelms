/**
 * Unit: Edities list pure mappers (Cluster F, Tier A).
 *
 * The edities surface is overwhelmingly Tier B (presentational — verified by the
 * cold-landing screenshot gate). The exceptions are the two closed-enum lookups
 * the surface reconstructs CLIENT-SIDE from the OfferingStatus VALUE the endpoint
 * sends (the label is not re-derived from dates — INV-7 — the value already IS
 * the effective status):
 *
 *   - editionStatusLabel(value) — VALUE → Dutch label. This is a verbatim mirror
 *     of OfferingStatus::label() (PHP). The REALISTIC regression: someone edits
 *     OfferingStatus::label() server-side and the JS map silently drifts, so the
 *     workspace renders a stale label. This spec PINS the JS map against the full
 *     closed OfferingStatus enum so any drift fails CI.
 *   - editionBadgeClass(value) — VALUE → ws-badge hue class (a presentation-only
 *     hue, NOT OfferingStatus::badgeClass() which is the WP-admin `badge-*` set).
 *     The contract: every closed-enum value maps to a hue, unknown → neutral.
 *
 * Imported via the UMD tail on edities.js (module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const edities = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/edities.js');

/* The closed OfferingStatus enum, pinned to OfferingStatus::label() (PHP).
   Domain/OfferingStatus.php — keep in lockstep; a drift here OR there fails. */
const OFFERING_STATUS_LABELS: Record<string, string> = {
  draft: 'Concept',
  announcement: 'Vooraankondiging',
  open: 'Open voor inschrijving',
  full: 'Volzet',
  in_progress: 'Lopend',
  postponed: 'Uitgesteld',
  cancelled: 'Geannuleerd',
  completed: 'Afgelopen',
  archived: 'Gearchiveerd',
};

test.describe('editionStatusLabel (pinned to OfferingStatus::label())', () => {
  test('every closed-enum value maps to its exact OfferingStatus label', () => {
    for (const [value, label] of Object.entries(OFFERING_STATUS_LABELS)) {
      expect(edities.editionStatusLabel(value)).toBe(label);
    }
  });

  test('DENIAL: an unknown / empty value falls through to the raw value or em-dash, never a wrong label', () => {
    expect(edities.editionStatusLabel('not_a_status')).toBe('not_a_status');
    expect(edities.editionStatusLabel('')).toBe('—');
    expect(edities.editionStatusLabel(undefined)).toBe('—');
    expect(edities.editionStatusLabel(null)).toBe('—');
  });
});

test.describe('editionBadgeClass (VALUE → ws-badge hue, closed enum)', () => {
  test('every closed-enum value maps to a non-empty hue class', () => {
    for (const value of Object.keys(OFFERING_STATUS_LABELS)) {
      const cls = edities.editionBadgeClass(value);
      expect(typeof cls).toBe('string');
      expect(cls.length).toBeGreaterThan(0);
    }
  });

  test('DENIAL: unknown / empty value → neutral "cancelled" hue (never an arbitrary class)', () => {
    expect(edities.editionBadgeClass('not_a_status')).toBe('cancelled');
    expect(edities.editionBadgeClass('')).toBe('cancelled');
    expect(edities.editionBadgeClass(undefined)).toBe('cancelled');
    expect(edities.editionBadgeClass(null)).toBe('cancelled');
  });
});

/* The factory's pure row helpers + the scope auto-widen rule (F-E1/F-E2).
   The factory needs no browser: construct it and call the methods directly;
   load() is stubbed where a method would otherwise fetch. */
test.describe('edities() factory row helpers', () => {
  const factory = () => edities.edities();

  test('dateText prefers the server Dutch dateLabel, falls back to raw date, empty for dateless', () => {
    const f = factory();
    expect(f.dateText({ dateLabel: '14 juli 2026', date: '2026-07-14' })).toBe('14 juli 2026');
    expect(f.dateText({ date: '2026-07-14' })).toBe('2026-07-14');
    expect(f.dateText({ dateLabel: '', date: null })).toBe('');
    expect(f.dateText(null)).toBe('');
  });

  test('rowKey keeps agenda (session) and list (edition) key spaces disjoint', () => {
    const f = factory();
    // The same underlying edition id must not collide across the view toggle.
    expect(f.rowKey({ sessionId: 7, id: 7 })).toBe('s7');
    expect(f.rowKey({ id: 7 })).toBe('e7');
    expect(f.rowKey({ sessionId: 7, id: 7 })).not.toBe(f.rowKey({ id: 7 }));
    expect(f.rowKey(null)).toBe('e0');
  });

  test('picking an admin-closed status under the upcoming scope auto-widens to all', () => {
    const f = factory();
    f.load = () => {}; // stub the fetch
    f.scope = 'upcoming';
    f.filters.status = 'completed';
    f.onStatusChange();
    expect(f.scope).toBe('all');

    // A live status keeps the scope as-is.
    f.scope = 'upcoming';
    f.filters.status = 'open';
    f.onStatusChange();
    expect(f.scope).toBe('upcoming');
  });

  test('the widen belongs to the STATUS handler only — a tag/filter change never re-overrides a narrowed scope', () => {
    const f = factory();
    f.load = () => {};
    // status=completed auto-widened once; the user narrowed back by hand.
    f.filters.status = 'completed';
    f.scope = 'upcoming';
    f.onFilterChange(); // the Tag dropdown's handler
    expect(f.scope).toBe('upcoming');
  });

  test('clearAllFilters restores the DEFAULT surface: filters empty, scope back to upcoming, one load', () => {
    const f = factory();
    let loads = 0;
    f.load = () => { loads++; };
    f.scope = 'all';
    f.filters = { q: 'x', status: 'completed', tag: '9', dateFrom: '2026-01-01', dateTo: '2026-02-01' };
    // simulate flatpickr's clear() firing the cleared branch, as init wires it
    f._fp = { clear: () => f.onDateChange([]) };
    f.clearAllFilters();
    expect(f.scope).toBe('upcoming');
    expect(f.filters.status).toBe('');
    expect(f.filters.dateFrom).toBe('');
    expect(loads).toBe(1); // the cleared-branch guard: no double fetch
  });

  test('onDateChange([]) is a no-op when both dates are already empty (the clearAllFilters guard)', () => {
    const f = factory();
    let loads = 0;
    f.load = () => { loads++; };
    f.onDateChange([]);
    expect(loads).toBe(0);
  });
});
