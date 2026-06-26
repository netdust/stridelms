# Course Documents & Onboarding Tracking — Design

**Date:** 2026-06-26
**Status:** Approved (design), pending implementation plan
**Author:** Stefan Vandermeulen + Claude

---

## Problem

LearnDash offers only a WYSIWYG editor for attaching course material — there is no structured, scalable way to add documents to a course, and the learner has no clean place to find and download them. A client wants Stride to be the place where onboarding lives: an employee enrolls in an onboarding, finds the documents, does the e-learning, and takes a quiz — and the organisation can see, per employee: **enrolled · downloaded document X · started/finished e-learning · quiz results.**

Client quote (nl_BE): *"We zoeken een platform waarop we onze onboarding documenten en opleidingen kunnen plaatsen voor medewerkers en tracken wat ze hebben gevolgd en wat nog niet."*

This is **not** a standalone document-library product. It is a structured documents capability on existing Stride entities, plus surfacing of progress that LearnDash already tracks but Stride does not display.

## Settled decisions (do not reopen)

- **Durable course material** (survives every edition) → attaches at **course** level. This is the primary gap to fix.
- **Edition-specific documents** (speaker presentations, dated handouts) → attach at **edition** level. An `edition.documents` attachment-ID field already exists with a working admin media-picker; it gains frontend rendering, secure download, and tracking.
- **Trajectory documents** → show on the trajectory surface; may *also* mirror onto child editions. Lower priority — deferred as a display rule, not a storage concern.
- **Documents are NOT a separate product from courses.** A course (onboarding) is the container: documents + e-learning (LD lessons) + quiz.
- **Data model = A2 (lightweight Document record).** Chosen over a file-centric attachment-ID array because the requirements (per-document metadata, organisation/categories, and the same file surfacing in multiple locations) are exactly what a flat attachment-ID array does badly.

## Data model — A2 (lightweight Document record)

Introduce a lightweight **`vad_document`** entity (CPT, following Stride's CPT/repository conventions — `getFields()` is the source of truth per INV-3). A document holds its own metadata and references the file behind it.

Fields (indicative — finalised at plan time against the CPT pattern):

| Field | Type | Purpose |
|-------|------|---------|
| `title` | string | Display title (independent of the underlying filename) |
| `description` | text | Optional short description shown on the card |
| `category` | string/term | Grouping for organisation once a container has many docs |
| `attachment_id` | int | The WP attachment (the actual file) |
| `owner_type` | enum | `course` \| `edition` \| `trajectory` |
| `owner_id` | int | The course / edition / trajectory it is attached to |
| `order` | int | Manual ordering within its owner / category |
| `visibility` | enum | `enrolled` (default) — reserved for future `public` brochures |

**Scoping rule (day one):** a document is attached to **exactly one owner** (course OR edition OR trajectory). No many-to-many graph on day one. The "same document shown in trajectory and on each child edition" requirement is satisfied as a **display rule** (the edition page additionally queries its trajectory's documents), not by storing the document twice.

**Edition docs:** the existing `edition.documents` attachment-ID field is **not** removed in this iteration. New course/trajectory documents use `vad_document`. Editions may migrate to `vad_document` later for a single model — additive, not a forced rewrite. (Plan must decide whether edition docs read through `vad_document` from the start or stay on the legacy field; default: stay, surface both in the frontend merge.)

## Admin (scales with document volume)

- **Course edit screen:** a structured **Documenten** metabox replacing reliance on the LD WYSIWYG. Each row: title, description, category, file-type/size, drag-reorder, remove. Add via the WP media picker (type-filtered, reusing the existing `edition-admin.js` media-picker logic).
- **Progressive disclosure for scale:** ≤5 documents → flat list; more → grouped by category with collapse. One UI, not two — this is the "UX must change beyond ~5 docs" requirement.
- **Edition:** keep the existing Documenten tab. If/when editions adopt `vad_document`, the two admin surfaces share the same component.
- **Trajectory:** same documents metabox on the trajectory edit screen (owner_type = `trajectory`).

## Frontend (learner)

- **Course / onboarding page:** a clean **"Materialen / Downloads"** section — documents grouped by category, each a card (icon, title, description, type·size, download button). Structured replacement for the WYSIWYG dump.
- **Edition page:** merges **course documents + that edition's documents** into one Materialen section (durable material + speaker slides together), visually grouped so the learner sees one coherent list. (Deferred: also-show trajectory docs here.)
- **Trajectory page:** trajectory-level documents in the existing `templates/trajectory/tab-materialen.php`. The mirror-onto-child-editions rule is deferred.
- **Gating:** download links render only for enrolled users (`hasActiveRegistration` / `existsForTrajectory`); the file itself is protected at the storage layer (below) so the URL cannot leak past the gate.

## Secure download + tracking

- **Storage:** protected uploads via the proven `CompletionProofStorage` pattern — `uploads/`-based directory with `.htaccess` deny, attachments as `post_status = private`, served only through an authenticated streaming endpoint. Public WP media URLs are NOT used for gated documents.
- **Download endpoint = the single tracking choke point.** On a successful authenticated download, log an event: **user · document · timestamp · registration**. This produces the "downloaded document X" signal at exactly one place.
- **Event log:** lightweight — a small table or an audit row via the existing `AuditBridge`. Keyed to user + document + registration so it can be surfaced per enrollment in the dossier.

## Dossier surfacing (the admin tracking gap)

Stride does not surface e-learning / quiz progress in the admin dossier today. Add an **onboarding/progress** section to the user dossier showing, per enrollment:

- **enrolled** — already known
- **documents downloaded** — from the download event log (which document, when)
- **e-learning progress** — LearnDash lesson completion via `LearnDashHelper` (data exists, not surfaced)
- **quiz results** — LearnDash quiz attempts/scores (exists in LD, needs reading + display)

Most of this section is **surfacing existing LearnDash data + the new download events** — not a parallel tracking engine. The same progress data should also be available to the learner in their own dashboard view (secondary).

## Scope & sequencing

**Core (this iteration):**
1. `vad_document` CPT + repository (A2)
2. Course documents metabox (scaling admin UI)
3. Course + edition frontend Materialen section (with the merge)
4. Secure protected-storage download endpoint
5. Download tracking event log
6. Dossier progress section (downloads + LD lessons + quiz results)

**Deferred (later layers):**
- Trajectory → child-edition document mirroring (display rule)
- "Turn a document into e-learning with a quiz" (doc-paired knowledge check)
- Migrating `edition.documents` onto `vad_document` for a single model
- `public` document visibility (lead-facing brochures)

## Open item for plan time

- **Launch timing / housing:** Stride is in ship-mode until launch. Decide whether this is launch-blocking (lands in core) or post-launch client work (a `stride-client-*` mu-plugin over core hooks). Does not change the design; changes where code lands.

## Security surfaces (triggers threat-modeling at plan time)

- New file-download endpoint serving gated files (path traversal via filename, content-type sniffing, enumeration).
- New upload handling on course/trajectory admin.
- Capability boundaries: who may attach/manage documents; cross-enrollment access (an employee must not download another onboarding's gated docs).
- Per-employee audit log (privacy of download tracking).

These mean the implementation plan MUST run `netdust-core:threat-modeling` alongside `writing-plans`.
