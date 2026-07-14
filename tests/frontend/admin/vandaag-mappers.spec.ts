/**
 * Unit: Vandaag Acties-nodig bucketing (mapActionBuckets).
 *
 * Tier A — this is the branching data-mapping helper that buckets
 * /admin/pending-approvals items into the "Wacht op mij" (mij) and
 * "Wacht op gebruiker" (gebruiker) sub-queues by `type`, and derives the
 * default-active tab from the counts. It has a real, falsifiable contract:
 *   - type ∈ {approval, post_approval} → mij
 *   - type === 'stale_user'            → gebruiker
 *   - default tab priority: (approval+post_approval) > stale_user > meldingen
 *   - the EMPTY-input branch (no items / no payload) must not crash and must
 *     fall through to 'meldingen'.
 * A wrong filter or wrong priority ships the exact bug the abandoned attempt
 * shipped: a populated panel rendering the wrong / empty bucket.
 *
 * No browser, no DDEV — the mappers are imported directly (UMD tail on
 * vandaag.js exposes module.exports under Node).
 */

import { test, expect } from '@playwright/test';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const mappers = require('../../../web/app/mu-plugins/stride-core/assets/js/admin/vandaag.js');

const sample = {
  items: [
    { id: 101, type: 'approval', user_id: 11, user_name: 'Lotte', edition_title: 'MI', registered_at: '2026-06-09 10:00:00' },
    { id: 102, type: 'post_approval', user_id: 12, user_name: 'Sander', edition_title: 'MI', registered_at: '2026-06-11 10:00:00' },
    { id: 103, type: 'stale_user', user_id: 13, user_name: 'Imane', edition_title: 'Cannabis', open_task_label: 'Intake', days_idle: 9, registered_at: '2026-06-01 10:00:00' },
  ],
  counts: { approval: 1, post_approval: 1, stale_user: 1 },
};

/**
 * Task 6.2 — deadline badge on stale_user rows. Reads the days_left /
 * days_overdue keys Task 6.1 derives server-side (buildDeadlineCountdown()).
 * Tier B (presentational badge) per testing-workflow, but toRow()'s branch
 * selection (overdue > due-soon > none) is cheap to pin here since the
 * mapper is already under test and importable without a browser.
 */
test.describe('mapActionBuckets — deadline badge (Task 6.2)', () => {
  test('days_overdue present → red "overdue" badge on the stale_user row', () => {
    const payload = {
      items: [{ id: 201, type: 'stale_user', user_id: 21, user_name: 'Bram', days_idle: 12, days_overdue: 3 }],
      counts: { stale_user: 1 },
    };
    const { gebruiker } = mappers.mapActionBuckets(payload, 0);
    expect(gebruiker[0].deadline).toEqual({ kind: 'overdue', label: '3 dagen te laat' });
  });

  test('days_overdue === 0 → "vandaag verlopen"', () => {
    const payload = {
      items: [{ id: 202, type: 'stale_user', user_id: 22, user_name: 'Nora', days_idle: 5, days_overdue: 0 }],
      counts: { stale_user: 1 },
    };
    const { gebruiker } = mappers.mapActionBuckets(payload, 0);
    expect(gebruiker[0].deadline).toEqual({ kind: 'overdue', label: 'vandaag verlopen' });
  });

  test('no days_overdue but days_left present → neutral "due soon" badge', () => {
    const payload = {
      items: [{ id: 203, type: 'stale_user', user_id: 23, user_name: 'Karel', days_idle: 2, days_left: 4 }],
      counts: { stale_user: 1 },
    };
    const { gebruiker } = mappers.mapActionBuckets(payload, 0);
    expect(gebruiker[0].deadline).toEqual({ kind: 'due-soon', label: 'nog 4 dagen' });
  });

  test('neither key (no activeDeadline) → no badge (denial path)', () => {
    const payload = {
      items: [{ id: 204, type: 'stale_user', user_id: 24, user_name: 'Ilse', days_idle: 6 }],
      counts: { stale_user: 1 },
    };
    const { gebruiker } = mappers.mapActionBuckets(payload, 0);
    expect(gebruiker[0].deadline).toBeNull();
  });

  test('approval/post_approval rows never get a deadline badge, even if the keys leak in', () => {
    const payload = {
      items: [{ id: 205, type: 'approval', user_id: 25, user_name: 'Wout', days_overdue: 2, days_left: 1 }],
      counts: { approval: 1 },
    };
    const { mij } = mappers.mapActionBuckets(payload, 0);
    expect(mij[0].deadline).toBeNull();
  });

  test('explicit days_overdue: null (future-contract drift) → no badge, not "vandaag verlopen"', () => {
    const payload = {
      items: [{ id: 206, type: 'stale_user', user_id: 26, user_name: 'Freya', days_idle: 4, days_overdue: null, days_left: null }],
      counts: { stale_user: 1 },
    };
    const { gebruiker } = mappers.mapActionBuckets(payload, 0);
    expect(gebruiker[0].deadline).toBeNull();
  });
});

test.describe('mapActionBuckets', () => {
  test('buckets approval + post_approval into "mij" (per-person rows)', () => {
    const { mij } = mappers.mapActionBuckets(sample, 0);
    expect(mij.map((r: any) => r.regId)).toEqual([101, 102]);
    expect(mij[0].name).toBe('Lotte');
    // edition title is carried into the meta line
    expect(mij[0].meta).toContain('MI');
    // post_approval gets its own microcopy, NOT the plain approval one
    expect(mij[1].meta).toContain('na cursus');
  });

  test('buckets stale_user into "gebruiker" with the open-task label + idle age', () => {
    const { gebruiker } = mappers.mapActionBuckets(sample, 0);
    expect(gebruiker).toHaveLength(1);
    expect(gebruiker[0].regId).toBe(103);
    expect(gebruiker[0].meta).toContain('Intake');
    expect(gebruiker[0].age).toBe('sinds 9d');
  });

  test('does NOT leak a stale_user into "mij" or an approval into "gebruiker" (denial path)', () => {
    const { mij, gebruiker } = mappers.mapActionBuckets(sample, 0);
    expect(mij.some((r: any) => r.regId === 103)).toBe(false);
    expect(gebruiker.some((r: any) => r.regId === 101 || r.regId === 102)).toBe(false);
  });

  test('default tab priority: admin-action present → "mij"', () => {
    expect(mappers.mapActionBuckets(sample, 5).defaultTab).toBe('mij');
  });

  test('default tab priority: only stale_user present → "gebruiker"', () => {
    const onlyStale = {
      items: [{ id: 9, type: 'stale_user', user_id: 1, user_name: 'X', days_idle: 3 }],
      counts: { approval: 0, post_approval: 0, stale_user: 1 },
    };
    expect(mappers.mapActionBuckets(onlyStale, 2).defaultTab).toBe('gebruiker');
  });

  test('empty-input branch: no payload → empty buckets, default "meldingen", no crash', () => {
    const empty = mappers.mapActionBuckets(undefined, 0);
    expect(empty.mij).toEqual([]);
    expect(empty.gebruiker).toEqual([]);
    expect(empty.defaultTab).toBe('meldingen');
  });

  test('empty items but counts all zero → default "meldingen"', () => {
    const zero = { items: [], counts: { approval: 0, post_approval: 0, stale_user: 0 } };
    expect(mappers.mapActionBuckets(zero, 0).defaultTab).toBe('meldingen');
  });
});

test.describe('mapQueues', () => {
  test('maps the mockup queue keys to the real worklistQueues keys', () => {
    const wq = { pending: 7, waitlist_open: 3, offerte_opvolging: 12, nocert: 2, oldinterest: 0, interest_to_invite: 5 };
    const queues = mappers.mapQueues(wq);
    expect(queues.map((q: any) => q.key)).toEqual(['pending', 'waitlist', 'offerte', 'nocert', 'oldinterest', 'interest_to_invite']);
    expect(queues.find((q: any) => q.key === 'waitlist').count).toBe(3);
    expect(queues.find((q: any) => q.key === 'offerte').count).toBe(12);
  });

  test('surfaces the interest_to_invite queue with its count from worklistQueues', () => {
    // "Interesse — editie nu gepland": count flows generically from
    // worklistQueues.interest_to_invite via the countKey mapping.
    const wq = { pending: 0, waitlist_open: 0, offerte_opvolging: 0, nocert: 0, oldinterest: 0, interest_to_invite: 20 };
    const queues = mappers.mapQueues(wq);
    const invite = queues.find((q: any) => q.key === 'interest_to_invite');
    expect(invite).toBeTruthy();
    expect(invite.count).toBe(20);
    expect(invite.label).toBe('Interesse — editie nu gepland');
  });

  test('missing worklistQueues → all counts 0, no crash (empty branch)', () => {
    const queues = mappers.mapQueues(undefined);
    expect(queues).toHaveLength(6);
    expect(queues.every((q: any) => q.count === 0)).toBe(true);
  });
});

test.describe('mapStats', () => {
  test('derives a +N delta only for active registrations when this week > last week', () => {
    const cards = mappers.mapStats({ totalRegistrations: 247, registrationsThisWeek: 18, registrationsLastWeek: 0 });
    const active = cards.find((c: any) => c.label === 'Actieve inschrijvingen');
    expect(active.num).toBe(247);
    expect(active.delta).toBe('+18 deze week');
    expect(active.kind).toBe('up');
  });

  test('does NOT fabricate a delta for the other three cards', () => {
    const cards = mappers.mapStats({ upcomingEditions: 7, pendingQuotes: 12, todaySessions: 3 });
    for (const label of ['Komende edities', 'Openstaande offertes', 'Sessies vandaag']) {
      expect(cards.find((c: any) => c.label === label).delta).toBe('');
    }
  });
});

/**
 * F-V1/F-V13 — meldingen navigation contract. A melding carries at most one
 * affordance: a workspace `target` ({view, params}, routed through
 * switchView), a wp-admin `url` (quotes), or neither (informational — the
 * old code linked post.php?post=0 for sessions without edition meta).
 */
test.describe('mapMeldingen — navigation targets', () => {
  test('passes a workspace target through and keeps url empty', () => {
    const rows = mappers.mapMeldingen([
      { rule: 'capacity_threshold', priority: 'amber', text: 'Excel: 19/20', subject_id: 9, url: '', target: { view: 'inschrijvingen', params: { edition_id: 9 } } },
    ]);
    expect(rows[0].target).toEqual({ view: 'inschrijvingen', params: { edition_id: 9 } });
    expect(rows[0].url).toBe('');
    expect(rows[0].isMelding).toBe(true);
  });

  test('a target without a view is normalized to null (no half-affordance)', () => {
    const rows = mappers.mapMeldingen([
      { rule: 'session_approaching', priority: 'blue', text: 'Zwevende sessie', subject_id: 32, url: '', target: null },
      { rule: 'weird', priority: 'blue', text: 'x', subject_id: 1, url: '', target: { params: {} } },
    ]);
    expect(rows[0].target).toBeNull();
    expect(rows[1].target).toBeNull();
  });

  test('quote meldingen keep their wp-admin url and no target', () => {
    const rows = mappers.mapMeldingen([
      { rule: 'stale_quote', priority: 'amber', text: 'Offerte Q-1 wacht', subject_id: 201, url: '/wp/wp-admin/post.php?post=201&action=edit' },
    ]);
    expect(rows[0].url).toContain('post.php?post=201');
    expect(rows[0].target).toBeNull();
  });
});

/**
 * Decision 7a — the approval card's ready/blocked split. Server-derived
 * (worklistQueues.pending_ready, same definition as the count); the card
 * renders it instead of the static def line. Older cached payloads within
 * the stats TTL may lack the key → no split, no NaN.
 */
test.describe('mapQueues — pending split (7a)', () => {
  test('renders the split line when pending_ready is present', () => {
    const queues = mappers.mapQueues({ pending: 5, pending_ready: 2 });
    const pending = queues.find((q: any) => q.key === 'pending');
    expect(pending.sub).toBe('2 klaar voor goedkeuring · 3 wacht op deelnemer');
    expect(pending.count).toBe(5);
  });

  test('no split without the payload key (stale cache) or at count 0', () => {
    expect(mappers.mapQueues({ pending: 5 }).find((q: any) => q.key === 'pending').sub).toBe('');
    expect(mappers.mapQueues({ pending: 0, pending_ready: 0 }).find((q: any) => q.key === 'pending').sub).toBe('');
  });

  test('a ready count above the total never renders a negative blocked number', () => {
    const pending = mappers.mapQueues({ pending: 2, pending_ready: 9 }).find((q: any) => q.key === 'pending');
    expect(pending.sub).toContain('0 wacht op deelnemer');
  });
});

/**
 * 6a — per-row melding dismissal (F-V7: the endpoint shipped with zero JS
 * consumers, so aggregate alerts reappeared forever). Optimistic removal,
 * restore on failure. The factory method only touches this.api/this.aq, so
 * it is testable without a browser.
 */
test.describe('dismissMelding (6a)', () => {
  const rows = () => [
    { regId: 'stale_quote-0', rule: 'stale_quote', subjectId: 0 },
    { regId: 'capacity_threshold-9', rule: 'capacity_threshold', subjectId: 9 },
  ];

  test('optimistically removes the row and POSTs rule + subject_id', async () => {
    const f = mappers.vandaag();
    const calls: any[] = [];
    f.api = async (url: string, opts: any) => { calls.push([url, opts]); return {}; };
    f.aq = { mij: [], gebruiker: [], meldingen: rows() };

    await f.dismissMelding(f.aq.meldingen[1]);

    expect(f.aq.meldingen.map((m: any) => m.regId)).toEqual(['stale_quote-0']);
    expect(calls[0][0]).toBe('/admin/action-queue/dismiss');
    expect(JSON.parse(calls[0][1].body)).toEqual({ rule: 'capacity_threshold', subject_id: 9 });
  });

  test('restores the row when the write fails', async () => {
    const f = mappers.vandaag();
    f.api = async () => { throw new Error('nope'); };
    f.aq = { mij: [], gebruiker: [], meldingen: rows() };

    await f.dismissMelding(f.aq.meldingen[0]);

    expect(f.aq.meldingen.length).toBe(2);
  });
});
