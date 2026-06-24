/**
 * Unit: Trajecten surface mappers (mapTrajectories + groupCourses).
 *
 * Tier A — these are the branching data-mapping helpers that adapt the REAL
 * (frozen) trajecten endpoints to the slide-over markup. They carry a real,
 * falsifiable contract that, if wrong, ships a visible bug:
 *
 *   mapTrajectories(payload):
 *     - reads payload.ITEMS (the real list key) — NOT payload.trajectories
 *       (the brief's mistaken key, which is actually the dossier endpoint's).
 *       A wrong key → an empty list against a populated API.
 *     - status/mode LABELS are passed through AS RECEIVED (INV-7) — never
 *       re-derived client-side.
 *     - a closed status→badge-class lookup (INV-5 styling) maps the status
 *       VALUE to a hue class; an unknown status falls back, never crashes.
 *     - empty / missing payload → [] (no crash).
 *
 *   groupCourses(courses):
 *     - the keystone divergence handler. The real detail endpoint emits a FLAT
 *       courses:[{type,editionId,title}] array (type ∈ {edition, online, …}),
 *       NOT the mockup's required[] + electiveGroups[] structure. This groups
 *       by type into { editions, online } so the markup can render two blocks.
 *     - a course with an empty title (the real `online` rows) gets a fallback
 *       label, never a blank line.
 *     - an unknown / missing type does not crash and lands in `online`
 *       (the catch-all non-edition bucket).
 *
 * No browser, no DDEV — the mappers are imported directly (UMD tail on
 * trajecten.js exposes module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const mappers = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/trajecten.js');

// Mirrors the REAL GET /admin/trajectories envelope (ground-truthed against
// AdminTrajectoryService::getTrajectories on 2026-06-24).
const listPayload = {
  items: [
    {
      id: 57344,
      title: 'Traject Jeugdgezondheidswerker',
      description: 'Beschrijving…',
      status: 'open',
      statusLabel: 'Open',
      mode: 'self_paced',
      modeLabel: 'Self_paced',
      capacity: 0,
      enrolledCount: 0,
      courseCount: 5,
    },
    {
      id: 57346,
      title: 'test-trajectory',
      description: '',
      status: 'closed',
      statusLabel: 'Gesloten',
      mode: 'cohort',
      modeLabel: 'Cohort',
      capacity: 24,
      enrolledCount: 3,
      courseCount: 2,
    },
  ],
  total: 2,
  page: 1,
  perPage: 20,
  totalPages: 1,
};

test.describe('mapTrajectories', () => {
  test('reads the real `items` key (not `trajectories`) and preserves order', () => {
    const rows = mappers.mapTrajectories(listPayload);
    expect(rows.map((r: any) => r.id)).toEqual([57344, 57346]);
  });

  test('passes status + mode LABELS through AS RECEIVED (INV-7, no re-derivation)', () => {
    const [a] = mappers.mapTrajectories(listPayload);
    expect(a.statusLabel).toBe('Open');
    // self_paced is NOT in the mockup TRAJ_MODE table — the API label wins.
    expect(a.modeLabel).toBe('Self_paced');
  });

  test('derives a closed status→badge-class lookup (INV-5 styling), unknown falls back', () => {
    const [open, closed] = mappers.mapTrajectories(listPayload);
    expect(open.badgeClass).toBe('confirmed'); // open → green
    expect(closed.badgeClass).toBe('pending'); // closed → amber
    const unknown = mappers.mapTrajectories({ items: [{ id: 1, status: 'weird', statusLabel: 'X', modeLabel: '' }] });
    expect(unknown[0].badgeClass).toBe('completed'); // safe slate fallback, no crash
  });

  test('empty / missing payload → [] (no crash)', () => {
    expect(mappers.mapTrajectories(undefined)).toEqual([]);
    expect(mappers.mapTrajectories({})).toEqual([]);
    expect(mappers.mapTrajectories({ items: [] })).toEqual([]);
  });
});

test.describe('groupCourses', () => {
  // Mirrors the REAL detail endpoint's flat courses array.
  const courses = [
    { type: 'online', editionId: 0, title: '' },
    { type: 'edition', editionId: 57259, title: 'Gezonde Tussendoortjes - 27 Jul 2026' },
    { type: 'edition', editionId: 57235, title: 'E-learning: Beweegbeleid - 6 Jul 2026' },
  ];

  test('groups the FLAT courses array by type into { editions, online }', () => {
    const g = mappers.groupCourses(courses);
    expect(g.editions.map((c: any) => c.editionId)).toEqual([57259, 57235]);
    expect(g.online).toHaveLength(1);
  });

  test('an empty-title (online) course gets a fallback label, never blank', () => {
    const g = mappers.groupCourses(courses);
    expect(g.online[0].label.length).toBeGreaterThan(0);
    // an edition course keeps its real title as the label
    expect(g.editions[0].label).toBe('Gezonde Tussendoortjes - 27 Jul 2026');
  });

  test('unknown / missing type lands in the online catch-all, no crash (denial path)', () => {
    const g = mappers.groupCourses([{ editionId: 0, title: '' }, { type: 'mystery', editionId: 0, title: 'Z' }]);
    expect(g.editions).toHaveLength(0);
    expect(g.online).toHaveLength(2);
  });

  test('empty / missing input → empty groups (no crash)', () => {
    expect(mappers.groupCourses(undefined)).toEqual({ editions: [], online: [] });
    expect(mappers.groupCourses([])).toEqual({ editions: [], online: [] });
  });
});
