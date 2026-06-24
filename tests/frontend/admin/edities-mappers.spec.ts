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
