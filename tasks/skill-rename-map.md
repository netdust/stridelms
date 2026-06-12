# Skill Rename Map — phase + object convention

**Status:** PROPOSAL — nothing renamed yet. Approve the convention + per-skill decisions, then apply as one atomic pass.
**Decision (2026-06-08):** name a skill by **phase + object** — the name says *when in the lifecycle* it fires and *what it acts on*, so the model disambiguates from the name instead of reading "sibling-to-X-not-Y" prose.
**Why:** the felt problem was "too many overlapping skills." Root cause is naming, not count (~30 skills, only the testing/QA cluster genuinely collides). Names today mix nouns (`dev-stack`), metrics (`test-effectiveness`), and internal jargon (`shake-out`, `harnessed`) — none encode *when* or *what verb*.

---

## The canonical phase spine (from `harnessed-development`)

`brainstorm → plan → execute → premerge → ship`

Prefix legend used below: `plan-` · `write-` (execute, per-task) · `review-`/`premerge-` · `setup-` (env/infra) · bare verb (cross-phase utility).

---

## Reference counts are REAL (measured 2026-06-08)

Counted across cache + marketplace plugin copies + Stride CLAUDE.md + memory, excluding each skill's own dir. This is the rename blast radius — every count is files that name the skill and would break the sequencer / commands / triggers if missed.

| Skill | Cross-refs | Notes |
|---|---|---|
| `shake-out` | 79 | inflated: substring also hits `shake-out-statamic` (14) + `shakeout` command. True unique ~50. |
| `ntdst-architecture` | 69 | woven through wp + statamic + core |
| `ntdst-data` | 51 | |
| `testing-workflow` | 49 | core 27 / wp 12 / statamic 6 — all three plugins |
| `ntdst-patterns` | 41 | |
| `threat-modeling` | 34 | |
| `research` | 33 | collides with `market-research` + global `deep-research` |
| `dev-stack` | 30 | |
| `architecture-invariants` | 28 | |
| `code-audit` | 28 | |
| `harnessed-development` | 25 | the sequencer itself; refs are inbound "entry point" mentions |
| `test-effectiveness` | 22 | |
| `secure-server` | 20 | |
| `feature-acceptance` | 10 | lowest blast radius |

**Implication:** a full rename is a ~300-edit cross-plugin find/replace, not `mv`. Each rename must update: skill dir name, `name:` frontmatter, every `<integration>` cross-ref, `harnessed-development` sequencer body, `/shakeout` + `/integration` + other command dispatch, agent files, CLAUDE.md trigger tables (Stride + plugin), and memory. Miss one inbound ref to a sequenced skill → the gate silently stops firing.

---

## Rename map — netdust-core

| Current | → Proposed | Phase·object rationale | Refs | Verdict |
|---|---|---|---|---|
| `harnessed-development` | **`dev`** | the front-door sequencer; short on purpose | 25 | rename — jargon ("harnessed") |
| `threat-modeling` | **`plan-threats`** | plan-time · attacks/mitigations | 34 | rename |
| `architecture-invariants` | **`plan-invariants`** | plan-time · convergence points | 28 | rename |
| `feature-acceptance` | **`plan-acceptance`** | authored at plan, driven at premerge | 10 | rename — cheapest |
| `testing-workflow` | **`write-tests`** | execute-time · per-task | 49 | rename — but high blast |
| `test-effectiveness` | **`premerge-audit-tests`** | premerge · audit the suite | 22 | rename — kills cluster collision |
| `shake-out` | **`premerge-qa`** | premerge · artifact sweep | ~50 | **DECISION NEEDED** — see below |
| `code-audit` | **`review-code`** | review existing code vs patterns | 28 | rename |
| `dev-stack` | **`setup-env`** | DDEV/git/Makefile conventions | 30 | rename |
| `research` | **`investigate`** | resolves research/market-research/deep-research 3-way | 33 | rename |
| `secure-server` | **`setup-server`** | pairs with `setup-env` | 20 | optional |
| `brand-voice` | *(keep)* | already clear | — | keep |
| `marketing` | *(keep)* | already clear | — | keep |
| `market-research` | *(keep)* | clear once `research`→`investigate` | — | keep |
| `ploi` | *(keep)* | proper noun, unambiguous | — | keep |

**The cluster this dissolves** (the actual pain): `plan-acceptance` (plan) · `write-tests` (execute) · `premerge-audit-tests` (premerge) · `premerge-qa` (premerge sweep) · `review-code` (review). Phase prefix now does the work the disclaimer prose was straining to do.

## Rename map — netdust-wp

| Current | → Proposed | Rationale | Refs | Verdict |
|---|---|---|---|---|
| `ntdst-architecture` | **`wp-architecture`** | match siblings `wp-security`/`wp-database` | 69 | rename — highest blast |
| `ntdst-data` | **`wp-data`** | same | 51 | rename |
| `ntdst-patterns` | **`wp-patterns`** | same | 41 | rename |
| `wp-security` `wp-database` `wp-frontend` `wp-infra` `wp-testing` | *(keep)* | already phase/object-clean | — | keep |
| `bedrock-composer` `ntdst-yootheme` | *(keep / maybe `wp-yootheme`)* | | — | minor |

> ⚠️ `ntdst-*` → `wp-*` is the single largest blast (161 combined refs) AND `ntdst` is a brand token, not just a name. It appears in code (`NTDST_Service_Meta`, `ntdst_get`), namespaces, and the framework identity. Renaming the *skills* to `wp-*` is defensible (they're WP-specific) but verify it doesn't read as renaming the *framework*. Lower priority than the core cluster.

## Rename map — netdust-statamic

| Current | → Proposed | Verdict |
|---|---|---|
| `shake-out-statamic` | **`premerge-qa-statamic`** | follow whatever `shake-out` becomes |
| `statamic-build` | *(keep)* | clear |
| `statamic-mcp` `peak-reference` | *(keep)* | clear |

Commands (`scaffold-plugin`, `setup-tests`, `sync-db`, `new-block`, `new-collection`, `sync-content`) already verb-led — **no change**.

---

## Open decisions before applying

1. **`shake-out` → `premerge-qa`?** Clearer to a cold reader, but "shake-out" is *your* spoken term (memory, commits, "shake it out"). ~50 refs + a `/shakeout` command + a statamic variant. Highest-friction rename for arguably the smallest clarity gain. **Recommend: keep `shake-out`, or rename only if you're willing to retrain your own vocabulary.**
2. **`ntdst-*` → `wp-*`?** Defer to a second pass — highest blast, brand-token risk, and those names are *already* domain-clear even if inconsistent.
3. **Scope of pass 1?** Recommend pass 1 = the 5-skill testing/QA cluster + `harnessed-development`→`dev` + `research`→`investigate`. That's the real pain + the two worst jargon offenders, ~140 refs, one atomic commit. Everything else is cosmetic and can wait.

## Apply procedure (when approved)

1. Branch off `staging` (never main — RULES.md #5).
2. For each approved rename, in one commit per skill (bisectable):
   a. `git mv` the skill dir; b. update `name:` frontmatter; c. grep-replace every inbound ref across all 3 plugin copies (cache **and** marketplace) + commands + agents + CLAUDE.md + memory; d. grep for the OLD name → must return only historical/changelog mentions.
3. After all renames: load `harnessed-development` (now `dev`) and confirm every sequenced skill name resolves. Run `/skill-audit` if it checks name resolution.
4. Update Stride `CLAUDE.md` skill tables + this project's `MEMORY.md` pointers.
