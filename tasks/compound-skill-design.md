# Design: `compounding` — the phase-end knowledge gate

**Status:** DRAFT for approval. Not installed into the plugin yet (rename/install is the high-blast-radius step — do it once shape is approved).
**Decisions locked (2026-06-08):** report-only (never auto-edit), fires as a phase-end gate inside `harnessed-development` Stage 3.
**Origin:** Compound Engineering's "Compound" step (write codebase knowledge back to the repo) + your own instinct to apply the same loop to the *skills*. Your memory/lessons loop is the third instance of the same shape.

---

## The one principle, three objects

A session generates knowledge about three different things. Today only one of the three reliably compounds.

| Object the session taught about | Where it should compound | Today |
|---|---|---|
| **Decisions / risks / project state** | `memory/STATE.md`, `lessons.md` | ✅ Stop hook + `DECISION:`/`RISK:`/`LESSON:` tags |
| **The codebase's structure** | `docs/architecture/CODE-MAP.md` | ❌ **nothing — the gap** |
| **The tools (skills) themselves** | skill `lessons.md` / SKILL.md body | ⚠️ tooling exists (`/skill-audit`, `SKILL-EDGE:`) but **open-loop** — fires only when you remember |

`compounding` closes objects 2 and 3 by making them a **structural phase-end gate**, the same way the spine already makes threat-modeling and test-effectiveness structural instead of honor-system. It does NOT replace the memory loop (object 1) — it sits beside it.

> This is the convergence-point philosophy turned on knowledge itself: "what did this phase teach, and where does it get written so it compounds instead of evaporating?" — answered in ONE named gate instead of re-decided each session.

---

## What the gate does (report-only)

Fires at **Stage 3, step 6 — after `finishing-a-development-branch`** (you compound what *landed*, not what's in flight). Two passes, both emit a **proposed-deltas manifest** you approve before any file changes — matching test-effectiveness's `covered/blind/fixed` manifest idiom.

### Pass A — Codebase compound → `docs/architecture/CODE-MAP.md`
Over the phase diff, answer: *what did we learn about the SYSTEM this phase that a future session shouldn't have to re-derive?*
- New/changed: modules, entry points, convergence points, data flows, cross-cutting seams.
- Diff the reality against the current `CODE-MAP.md`. If it's missing or stale, propose the delta.
- **Output:** a proposed patch to `CODE-MAP.md` — NOT applied. You approve.
- Ties into `architecture-invariants`: if the phase touched a convergence point, the CODE-MAP entry references the invariant. (lace — obra's own runtime — keeps exactly this `docs/architecture/CODE-MAP.md`.)

### Pass B — Skill compound → skill `lessons.md` (report-only)
Reuses the machinery you ALREADY have — this pass is a *trigger + scoping* layer over `/skill-audit`, not new logic.
- Scope: only skills **touched this phase** (invoked in the transcript or named in commits), not all 30 — keeps it cheap and relevant.
- For each touched skill, run the `/skill-audit` checks (stale lessons, body-vs-reality drift, description quality) **plus** harvest any `SKILL-EDGE:` deltas raised this phase.
- **Output:** proposed `lessons.md` appends + flagged body-staleness — NOT applied. You approve.
- Split by blast radius is already respected: `lessons.md` appends are low-risk proposals; SKILL.md body edits are always report-only.

---

## Manifest shape (what the gate emits)

```
Compound — <phase name> — YYYY-MM-DD
====================================

A. CODEBASE  → docs/architecture/CODE-MAP.md
   proposed:  3 deltas
   1. + Module: PartnerAPI/RateLimiter  (new convergence point — auth invariant #4)
   2. ~ EnrollmentService: removed 3 pass-through methods (call sites now repo-direct)
   3. ! STALE: CODE-MAP still lists vad_quote under Invoicing/ — moved 2 phases ago

B. SKILLS  (touched this phase: testing-workflow, threat-modeling, wp-security)
   proposed:  2 lessons appends · 1 body flag
   1. + testing-workflow/lessons.md: "seam test missed wp_ajax nonce refresh race" (SKILL-EDGE this phase)
   2. + threat-modeling/lessons.md: "partner BYOK URL needed SSRF guard not in catalog"
   3. ! wp-security body: high-usage this phase, body untouched 90d — review

Nothing written. Approve items to apply: ____
```

---

## Why report-only is correct here (not timidity)

Editing a skill changes behavior for **every future session in every project**. That blast radius is exactly why `/skill-audit` and `/pattern-miner` are already `**Do not auto-edit**`. The compound gate inherits that rule — it makes the *evaluation* structural (fires every phase) without making the *mutation* automatic. You get the compounding cadence; you keep the editorial veto.

CODE-MAP is lower-risk (project-local doc, not behavior) — but kept report-only too for one reason: a wrong auto-written map is worse than no map (it gets trusted). One approval step is cheap insurance.

---

## Naming (per the superpowers idiom we confirmed current @ v5.1.0)

superpowers names processes verb-first/gerund (`writing-plans`, `executing-plans`, `finishing-a-development-branch`). This is a process → **`compounding`**. Reads native next to `finishing-a-development-branch` as the Stage 3 closer. (Alt if you want the object explicit: `compounding-knowledge`.)

---

## Integration point (one-line plan)

`harnessed-development` Stage 3, add step 6:

> **6. Compound** — invoke `netdust-core:compounding`. After the branch is finished, harvest phase knowledge into proposals: a `CODE-MAP.md` patch (codebase) + scoped `/skill-audit` over skills touched this phase (tools). Report-only — emits a proposed-deltas manifest; you approve what's written. Closes the knowledge loop the same way memory/STATE closes the decision loop.

Cross-refs to add: `<integration>` table rows for `architecture-invariants` (CODE-MAP references invariants), `skill-audit`/`pattern-miner` (Pass B reuses them), and a note in `dev`/CLAUDE.md.

---

## Open questions for you

1. **CODE-MAP location** — `docs/architecture/CODE-MAP.md` (matches lace) or somewhere you already keep arch docs (`docs/ARCHITECTURE-V4-PROPOSAL.md` exists — but that's a proposal, not a living map; recommend a fresh living CODE-MAP).
2. **Pass B scope** — only skills touched this phase (recommended, cheap) vs. full `/skill-audit` every phase (thorough, noisy).
3. **Cadence** — every phase close (could be noisy on small phases) vs. only spec-close / `/shakeout`-level boundaries. Recommend: spec-close, not every sub-phase.
4. Name: `compounding` vs `compounding-knowledge`.
