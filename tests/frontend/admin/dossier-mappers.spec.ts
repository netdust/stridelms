/**
 * Unit: Dossier pure mappers (Cluster D, Tier A).
 *
 * The dossier surface is overwhelmingly Tier B (presentational — verified by
 * the cold-landing screenshot gate). These three helpers are the exceptions:
 * each carries real branching logic with a falsifiable contract, so each gets a
 * RED-first behavioural test incl. its empty/edge + denial branch (plan §10).
 *
 *   1. auditToTimelineEvent(entry) — maps the REAL D.1 audit_trail entry
 *      { id, type, text, target_url, actor_name, timestamp } to the mockup
 *      timeline-event shape { dot, icon, title, actor, when }. `title`←text,
 *      `actor`←actor_name, `when`←a Dutch-formatted timestamp, `icon`←a CONSTANT
 *      map keyed by `type`, `dot`←a CONSTANT map keyed by `type`. The DENIAL /
 *      fallback branch is an UNKNOWN type → the default icon ('clock') + default
 *      dot ('default'), never a leaked/echoed data value (INV-5: icon names are
 *      constants, never data).
 *
 *   2. timelineForReg(events, reg) — the per-registration filter the
 *      `timelineReg` <select> drives. An event is attributed to a registration
 *      by the edition id encoded in its target_url (the only reg signal the
 *      emitted entry exposes — the entry carries NO top-level edition_id /
 *      registration_id; D.1 surfaces edition only via target_url
 *      `post.php?post={editionId}`). Events with NO resolvable edition (auth,
 *      user, edition-management) are reg-agnostic → they show for EVERY reg.
 *      The DENIAL / scoping branch: an event scoped to reg A's edition must NOT
 *      appear when reg B is selected.
 *
 *   3. completionChecklist(reg) — derives the 4-item completion checklist from
 *      data already on the registration: approval (status !== 'pending'), intake
 *      submitted (!!stages.intake.submitted_at), attendance ≥ 80%
 *      (present / total_sessions >= 0.8), final evaluation submitted
 *      (!!stages.evaluation.submitted_at). The EMPTY branch: a reg with no
 *      stages and no attendance → intake/attendance/evaluation all done:false.
 *
 * No browser, no DDEV — the mappers are imported directly (UMD tail on
 * dossier.js exposes module.exports under Node, exactly like vandaag.js/grid.js).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const dossier = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/dossier.js');

/* a realistic D.1 audit_trail entry (the emitted mapper shape). target_url for
   a registration/attendance event encodes the edition id. */
const regEvent = {
  id: 9001,
  type: 'enrollment',
  text: 'Imane El Amrani heeft zich ingeschreven voor Vroeginterventie bij cannabisgebruik',
  target_url: 'https://stride.ddev.site/wp/wp-admin/post.php?post=512&action=edit',
  actor_name: 'Imane El Amrani',
  timestamp: 1748332800, // 2025-05-27 (epoch seconds)
};

test.describe('auditToTimelineEvent', () => {
  test('maps a known type to the right CONSTANT icon + dot, title←text, actor←actor_name', () => {
    const ev = dossier.auditToTimelineEvent(regEvent);
    expect(ev.title).toBe(regEvent.text);
    expect(ev.actor).toBe('Imane El Amrani');
    // enrollment → 'route' icon + 'primary' dot (closed-enum, by type)
    expect(ev.icon).toBe('route');
    expect(ev.dot).toBe('primary');
    // `when` is a formatted Dutch date string, NOT the raw epoch
    expect(typeof ev.when).toBe('string');
    expect(ev.when).not.toBe(String(regEvent.timestamp));
    expect(ev.when.length).toBeGreaterThan(0);
  });

  test('maps each known type to its dot kind (attendance/quote/completion)', () => {
    expect(dossier.auditToTimelineEvent({ ...regEvent, type: 'attendance' }).icon).toBe('userCheck');
    expect(dossier.auditToTimelineEvent({ ...regEvent, type: 'quote' }).icon).toBe('receipt');
    expect(dossier.auditToTimelineEvent({ ...regEvent, type: 'completion' }).icon).toBe('award');
    expect(dossier.auditToTimelineEvent({ ...regEvent, type: 'completion' }).dot).toBe('success');
  });

  test('DENIAL/fallback: an UNKNOWN type → default icon "clock" + dot "default" (never an echoed data value)', () => {
    const ev = dossier.auditToTimelineEvent({ ...regEvent, type: 'totally-made-up-type' });
    expect(ev.icon).toBe('clock');
    expect(ev.dot).toBe('default');
    // the unknown type must NOT leak through as the icon name (INV-5)
    expect(ev.icon).not.toBe('totally-made-up-type');
  });

  test('EMPTY branch: a null/garbled entry does not crash, yields safe defaults', () => {
    const ev = dossier.auditToTimelineEvent(undefined);
    expect(ev.icon).toBe('clock');
    expect(ev.dot).toBe('default');
    expect(ev.title).toBe('');
    expect(ev.actor).toBe('');
  });
});

test.describe('timelineForReg', () => {
  // edition 512 = reg A; edition 777 = reg B. A reg-agnostic event has no
  // edition in its target_url (e.g. an auth/login event).
  const events = [
    { id: 1, type: 'enrollment', text: 'A: ingeschreven', actor_name: 'X', timestamp: 1748332800, target_url: 'https://x/wp/wp-admin/post.php?post=512&action=edit' },
    { id: 2, type: 'attendance', text: 'A: aanwezig',     actor_name: 'Y', timestamp: 1748332801, target_url: 'https://x/wp/wp-admin/post.php?post=512&action=edit' },
    { id: 3, type: 'enrollment', text: 'B: ingeschreven', actor_name: 'X', timestamp: 1748332802, target_url: 'https://x/wp/wp-admin/post.php?post=777&action=edit' },
    { id: 4, type: 'auth',       text: 'X: ingelogd',     actor_name: 'X', timestamp: 1748332803, target_url: '' },
  ];
  const regA = { id: 103, edition_id: 512 };
  const regB = { id: 211, edition_id: 777 };

  test('shows events scoped to the selected reg PLUS the reg-agnostic ones', () => {
    const out = dossier.timelineForReg(events, regA);
    const ids = out.map((e: any) => e.id).sort();
    // reg A's two edition-512 events + the reg-agnostic auth event (id 4); NOT id 3
    expect(ids).toEqual([1, 2, 4]);
  });

  test('DENIAL/scoping: an event scoped to reg A does NOT appear when reg B is selected', () => {
    const out = dossier.timelineForReg(events, regB);
    const ids = out.map((e: any) => e.id).sort();
    // reg B's edition-777 event + the reg-agnostic auth event; NOT 1 or 2 (reg A's)
    expect(ids).toEqual([3, 4]);
    expect(ids).not.toContain(1);
    expect(ids).not.toContain(2);
  });

  test('returns mapped timeline-event shape (not raw audit entries)', () => {
    const out = dossier.timelineForReg(events, regA);
    expect(out[0]).toHaveProperty('icon');
    expect(out[0]).toHaveProperty('dot');
    expect(out[0]).toHaveProperty('when');
    expect(out[0]).toHaveProperty('title');
  });

  test('EMPTY branch: no events / no reg → [] (no crash)', () => {
    expect(dossier.timelineForReg([], regA)).toEqual([]);
    expect(dossier.timelineForReg(undefined, regA)).toEqual([]);
    expect(dossier.timelineForReg(events, undefined)).toEqual([]);
  });
});

test.describe('completionChecklist', () => {
  test('intake submitted + attendance < 80% → intake done, attendance NOT done', () => {
    const reg = {
      status: 'confirmed',
      stages: {
        intake: { submitted_at: '22 mei 2026 · 16:08', submitted_by: 'Imane', data: { q: 'a' } },
        evaluation: null,
      },
      attendance: { present: 2, absent: 1, excused: 0, total_sessions: 3, hours: 6 }, // 2/3 = 66%
    };
    const items = dossier.completionChecklist(reg);
    const by = (label: string) => items.find((i: any) => i.label.includes(label));
    expect(by('Goedkeuring').done).toBe(true);   // status !== pending
    expect(by('Intake').done).toBe(true);          // intake.submitted_at present
    expect(by('Aanwezigheid').done).toBe(false);   // 66% < 80%
    expect(by('Eindevaluatie').done).toBe(false);  // evaluation null
  });

  test('attendance ≥ 80% → attendance done (boundary: exactly 80%)', () => {
    const reg = {
      status: 'completed',
      stages: { intake: { submitted_at: 'x', data: { q: 'a' } }, evaluation: { submitted_at: 'y', data: { q: 'b' } } },
      attendance: { present: 4, absent: 1, excused: 0, total_sessions: 5, hours: 8 }, // 4/5 = 80%
    };
    const items = dossier.completionChecklist(reg);
    const by = (label: string) => items.find((i: any) => i.label.includes(label));
    expect(by('Aanwezigheid').done).toBe(true);
    expect(by('Eindevaluatie').done).toBe(true);
  });

  test('pending status → approval NOT done (the denial path on the first item)', () => {
    const reg = { status: 'pending', stages: {}, attendance: null };
    const items = dossier.completionChecklist(reg);
    expect(items.find((i: any) => i.label.includes('Goedkeuring')).done).toBe(false);
  });

  test('EMPTY branch: no stages, no attendance → only approval can be true, rest false', () => {
    const reg = { status: 'confirmed', stages: {}, attendance: null };
    const items = dossier.completionChecklist(reg);
    const by = (label: string) => items.find((i: any) => i.label.includes(label));
    expect(by('Goedkeuring').done).toBe(true);
    expect(by('Intake').done).toBe(false);
    expect(by('Aanwezigheid').done).toBe(false);   // no attendance summary → not met
    expect(by('Eindevaluatie').done).toBe(false);
    // shape: a stable 4-item checklist regardless of data presence
    expect(items).toHaveLength(4);
  });

  test('fully empty reg ({}) → no crash, 4 items', () => {
    const items = dossier.completionChecklist({});
    expect(items).toHaveLength(4);
    expect(items.every((i: any) => typeof i.done === 'boolean' && typeof i.label === 'string')).toBe(true);
  });
});
