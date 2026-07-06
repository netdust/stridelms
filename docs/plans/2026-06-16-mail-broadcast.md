# Mail Broadcast — Implementation Plan

**Status:** Plan only (not approved for build). Scope post-launch per ship-mode.
**Date:** 2026-06-16
**Author decisions:** see memory `project_mail_broadcast_feature.md`.

## Goal

Add **manual broadcast sending** to the `netdust-mail` plugin: an admin builds a recipient list, picks a template, adjusts subject/body for this send only, and sends. Distinct from the existing trigger-driven transactional mail (`ndmail_send()` on events).

Lives **entirely in `netdust-mail`** (`web/app/plugins/netdust-mail/`). Stride stays decoupled and plugs recipient sources in through a filter.

## Non-goals (explicit deferrals)

- No scheduling / send-later (send is immediate or cron-drained, not calendar-scheduled).
- No open/click tracking, no unsubscribe management (FluentCRM territory; out of scope).
- No editing of saved templates from this flow — adjustments are one-off only.
- No A/B, no segmentation builder beyond the registered recipient sources.
- No new email *transport* — `wp_mail()` via FluentSMTP, unchanged.

## Architecture

Three reused pillars (no changes): `ndmail_template` CPT, `SmartCodeParser`/`SmartCodeRegistry`, the `ntdst_mail()` builder.

Four new pieces, all in `netdust-mail`:

1. **`MailService::sendRaw()`** — send an arbitrary subject/body (the one-off override) reusing the existing parser + unparsed-guard + `ntdst_mail()` builder. `send()` stays template-bound; `sendRaw()` is its sibling for content not in the CPT.
2. **`ndmail_recipient_sources` filter** — extension seam mirroring `ndmail_smartcodes`/`ndmail_triggers`. A source declares `{ id, label, resolve(params): RecipientSet }`. Generic sources ship in-plugin; Stride registers its registration/edition/trajectory sources from `StrideMailBridge`.
3. **`BroadcastService`** — orchestrates: resolve recipients from chosen sources → dedupe → parse per-recipient → send inline (≤ threshold) or enqueue + cron-drain.
4. **Admin UI** — a new Alpine tab + REST routes on the existing `AdminController` (namespace `netdust-mail/v1`).

### Recipient source contract

```
Source = [
  'id'    => 'stride_edition',
  'label' => 'Editie-inschrijvingen',
  'params'=> [ /* field schema the UI renders: edition picker, status select */ ],
  'resolve' => fn(array $params): array  // returns [ ['email'=>, 'user_id'=>, 'context'=>[...]], ... ]
]
```

- **Generic, in-plugin:** `individual` (one address), `manual_list` (paste emails / textarea), `wp_users` (role/meta filter).
- **Stride, via filter from `StrideMailBridge`:** `stride_edition` (enrolled in edition X, status filter), `stride_trajectory` (enrolled in trajectory Y), `stride_status` (all registrations with status Z). Resolve through `RegistrationRepository` — never raw `$wpdb` (INV / repositories-only).
- Each resolved recipient carries its own `context` (`user_id`, `edition_id`, …) so SmartCodes parse **per recipient**.

### Send mechanics

- Recipients deduped by email (case-insensitive) into one set.
- **Threshold (default ~25, filterable):** ≤ threshold → send inline in the REST request, return per-recipient result. > threshold → persist a **broadcast job** + recipient rows, schedule `ndmail/broadcast/drain` on wp-cron, drain in chunks (e.g. 25/tick), update per-row sent/failed. A timeout never loses the campaign; re-drain is idempotent (skip rows already `sent`).
- Each send is `sendRaw($to, $parsedSubject, $parsedBody, $context)`. FluentSMTP transports.
- **Storage:** custom table `wp_ndmail_broadcasts` (job: id, subject snapshot, body snapshot, template_slug, status, counts, created_by, created_at) + `wp_ndmail_broadcast_recipients` (job_id, email, user_id, context json, status, error, sent_at). Custom table over CPT — high row count, status churn, queue semantics. (Decision point: confirm table vs CPT at build.)

## Threat model

New surface: an admin page that accepts **user-supplied recipient input** and **sends mail**. Assets: the send capability (spam/abuse vector), recipient PII, the SMTP reputation of the domain.

| # | Actor / attack | Surface | Mitigation (REQUIRED in tasks) |
|---|---|---|---|
| T1 | Low-priv user reaches send | REST `/broadcast/*` | `permission_callback` = `current_user_can('manage_options')` on **every** route (match existing controller). No nopriv. |
| T2 | CSRF-triggered broadcast | REST POST | WP REST nonce (`X-WP-Nonce`) enforced; Alpine sends `wpApiSettings.nonce`. |
| T3 | Recipient injection / header injection | `manual_list`, `individual` params | `sanitize_email()` + `is_email()` per address; drop invalids (report count). Reject newlines in any address. Subject through `sanitize_text_field` before parse. |
| T4 | Stored XSS via one-off body | body override → email HTML | Body is email HTML by design, but the **admin preview** renders in wp-admin → escape on preview render, or sandbox. Persist raw, escape at preview output. |
| T5 | SmartCode info-leak / unparsed leak | per-recipient parse | Reuse `findUnparsed()` guard from `send()` — block any recipient whose context leaves a code unparsed; log + skip, don't send a broken/leaky mail. |
| T6 | Mass-mail abuse / runaway | send loop | Hard cap per job (e.g. 1000, filterable) aligned to the ~300 real ceiling; cron chunk cap; log job + actor to audit. |
| T7 | Capability bypass on Stride sources | `resolve()` reading registrations | Source resolve runs server-side under the same cap gate; resolved data scoped to what an admin may already see (admins see all registrations — no new exposure). |
| T8 | PII at rest in job rows | `wp_ndmail_broadcast_recipients` | Context JSON stores ids, not copies of PII where avoidable; purge job rows after N days (cleanup cron). |

Out of scope (accepted): unsubscribe/consent tracking (not a marketing tool; transactional/admin broadcast to known users), rate-limiting beyond the job cap (FluentSMTP + small lists make this low-risk).

## Tasks (with test tiers)

> `netdust-mail` currently has **no PHPUnit setup**. Task 0 stands up a minimal one in the plugin (or routes these unit tests through Stride's suite — decide at build). Tier-A tasks below assume a runner exists.

- **T0 — Test harness** *(no bespoke test)*: minimal PHPUnit + WP stubs for the plugin, or wire into Stride's Unit suite.
- **T1 — `MailService::sendRaw()`** *(Tier A: parse + unparsed-guard + recipient validation are logic/denial paths)*: RED first — unparsed code blocks the send; invalid `to` returns `WP_Error`; valid path builds + sends. Reuse parser, do not duplicate it.
- **T2 — `ndmail_recipient_sources` filter + registry** *(Tier A: registry resolution + dedupe)*: register, resolve, dedupe-by-email, drop-invalid. RED for dedupe + invalid-drop.
- **T3 — Generic sources** (`individual`, `manual_list`, `wp_users`) *(Tier A for `manual_list` parsing/validation; glue for the others)*.
- **T4 — `BroadcastService` inline path** *(Tier A: threshold branch, per-recipient context, partial-failure accounting)*.
- **T5 — `BroadcastService` queued path + cron drain** *(Tier A: idempotent re-drain, chunk cap, status transitions)*. Seam test: enqueue → drain → all rows terminal.
- **T6 — Storage** (tables + migration) *(Tier A: migration runs, idempotent)*.
- **T7 — REST routes** `/broadcast/sources`, `/broadcast/preview`, `/broadcast/send` *(Tier A: every route has the cap + nonce permission_callback — T1/T2 of threat model)*.
- **T8 — Admin Alpine tab** (list builder, template picker, override editor, preview, send) *(UI — no bespoke unit test; covered at shake-out via feature-acceptance flows)*.
- **T9 — Stride sources** registered from `StrideMailBridge` (`stride_edition`, `stride_trajectory`, `stride_status`) via `RegistrationRepository` *(Tier A: resolve returns correct scoped recipients)*.
- **T10 — Cleanup cron** (purge old job rows — T8) *(glue)*.

## Acceptance flows (drive at shake-out)

| Flow | Steps | Edge/error cases |
|---|---|---|
| F1 small inline | pick edition source (8 regs) → pick template → tweak subject → send | 0 recipients; all-invalid emails; one unparsed code |
| F2 large queued | manual_list 120 emails → send → cron drains | mid-drain re-trigger (idempotent); some addresses bounce/fail |
| F3 individual | type one address → send | malformed address rejected |
| F4 override isolation | adjust body, send → confirm CPT template unchanged | — |
| F5 permission | non-admin hits REST route | 403 |

## Open decisions for build-time

1. Custom tables vs CPT for jobs/recipients (plan leans tables).
2. Plugin-local PHPUnit vs Stride suite for the new tests.
3. Inline threshold value (default 25) + per-job hard cap (default 1000, real ceiling ~300).
4. Broadcast UI as a tab on the existing Mail page vs a second submenu.
