# Single-Price Cleanup — Remove Member-Pricing Drift

**Date:** 2026-07-05
**Decision (Stefan):** One price per offering. Discounts come from the VOUCHER system. There are NO member prices.
**Goal:** Make the code match that model. Collapse the two-price surfaces to one; remove the dead member-price branch; keep the fields dual-written-equal for storage compat.
**Safety:** The 5-CPT admin coverage net (91 tests) guards this refactor — regressions surface immediately.
**Scope class:** A (refactor + tiny data migration). No new attack surface → no threat model.

---

## Ground truth (from scope investigation)

- **Editions already correct:** `handleSave` dual-writes `price == price_non_member` (0/28 rows divergent in dev DB). `EditionService::getPrice()` has a member branch (`price` vs `price_non_member`) that is a **no-op today** because the fields are equal.
- **Trajectory is the drift:** admin has TWO inputs ("Prijs leden" / "Prijs niet-leden", `TrajectoryAdminController.php:290-297`), `handleSave` writes them INDEPENDENTLY (`:1144-1149`, no sync). **2/103 trajectory rows are divergent** (#184605, #184606).
- **No trajectory-level price routing.** Trajectory reads fields raw for display; child billing routes through `EditionService::getPrice`. `TrajectorySelection` has zero price logic.
- **`QuoteAdminController::handleNewQuoteCreation` (`:602-607`)** picks `price` (member) first, falling back to `price_non_member` — a member-first pick that should just be "the price".
- **Membership filter `stride/membership/price` is inert in production** — applied only in `EditionService::getPrice:234`, hooked by NO client, only by tests. It's a documented escape hatch.
- **Existing migration script:** `scripts/migrate-member-prices.php` already rewrites `_ntdst_price` to match `_ntdst_price_non_member` where they differ.

---

## Design decisions

**D1 — Keep both meta keys, dual-written equal (do NOT drop `price_non_member`).**
Rationale: 10 files read `price_non_member`; dropping it is a large blast radius for zero benefit. The Edition pattern (both keys, kept equal) already works and is tested. Trajectory should match it. `price_non_member` stays canonical (it's the form's primary input on Edition); `price` is synced equal. This is the minimal-risk collapse.

**D2 — `price_non_member` is canonical; `price` mirrors it.**
Matches Edition's existing convention. Migration makes the 2 divergent trajectories consistent by setting `price = price_non_member` (the non-member/higher value is the real single price — confirm with Stefan; see Q1).

**D3 — Keep the `stride/membership/price` filter as an inert escape hatch, but simplify `getPrice` to read ONE field.**
`getPrice` stops branching on membership (always reads `price_non_member`, the canonical single price), but still applies the `stride/membership/price` filter so a future client COULD reintroduce per-member pricing via a hook without a core change. This preserves extensibility at zero cost. (Alternative: remove the filter entirely — simpler but loses the hook. Recommend keeping; see Q2.)

**D4 — Relabel, don't restructure, the CPT fields.**
`price` / `price_non_member` field labels currently say "Prijs (leden)" / "Prijs (niet-leden)". Relabel `price_non_member` → "Prijs (€)" and mark `price` as legacy/internal (or keep both fields, one hidden). Keep the field *keys* (D1).

---

## Decisions (confirmed by Stefan 2026-07-05)

- **Q1 — migration direction:** `price_non_member` is the real single price (the higher, non-discounted value; discounts become vouchers). **Migration sets `price = price_non_member`** for the 2 divergent trajectories.
- **Q2 — membership filter:** **KEEP** `stride/membership/price` as an inert escape hatch. `getPrice` reads the single price (`price_non_member`) but still applies the filter so a future client can reintroduce per-member pricing via a hook without a core change.

---

## Tasks

### Task 1 — Trajectory admin: collapse to one price input  `Test-author: split`
- `TrajectoryAdminController`: remove the "Prijs leden" input (`:290-292`); relabel the remaining input "Prijs (€)"; keep `name="ntdst_fields[price_non_member]"`.
- `handleSave` (`:1144-1149`): dual-write like Edition — when `price_non_member` posted, write the same cents to BOTH `price` and `price_non_member`. Remove the independent `price` write.
- **Test:** rewrite `TrajectoryPriceLifecycleSaveTest` price cases (`:133-174`) — they currently assert NON-equality (encode the drift). New contract: `price == price_non_member` after save (mirror `EditionAdminHandleSaveTest`). RED-first: the rewritten assertion fails against current independent-write code, passes after the dual-write change.

### Task 2 — `EditionService::getPrice`: drop the member branch  `Test-author: split`
- Replace `$field = $isMember ? 'price' : 'price_non_member'` with always reading `price_non_member` (canonical single price). Keep the `stride/membership/price` filter apply (D3) unless Q2 says remove.
- Keep `isMember()` (still used elsewhere? verify — if only getPrice used it, mark for removal in Task 5).
- **Test:** update `EditionServicePricingTest` — the member/non-member branch tests (`:95,:109`) collapse to "returns the single price regardless of user"; keep a test that the filter still fires (if D3 keep).

### Task 3 — `QuoteAdminController::handleNewQuoteCreation`: single-price pick  `Test-author: split`
- `:602-607`: replace the member-first pick with `EditionService::getPrice($editionId)` OR a direct read of `price_non_member`. One price, no member preference.
- **Test:** update `QuoteAdminNewQuoteCreationTest:253` (the "must NOT be chosen" member-price assertion) to the single-price contract.

### Task 4 — Data migration for the 2 divergent trajectories  `Test-author: solo`
- Use/adapt `scripts/migrate-member-prices.php` to set `_ntdst_price = _ntdst_price_non_member` for divergent trajectory rows (per Q1). Idempotent, logs affected ids, dry-run flag.
- Run on dev DB; verify the divergent-rows query returns 0 after.
- **Test:** the migration is a script; verify by re-running the divergent-rows check. (Tier B — script, verified by the count check, not a unit test.)

### Task 5 — Field relabel + dead-code sweep  `Test-author: solo`
- Relabel `price`/`price_non_member` labels in `EditionCPT` + `TrajectoryCPT` to reflect single price (D4).
- If `isMember()` / the membership branch is now fully unused in pricing, decide keep (MembershipService stays for the `is_member` filter) vs prune. Do NOT remove MembershipService (it's the documented membership seam).
- Grep for any remaining "Prijs (leden)" / member-price UI copy.

---

## Verification (before merge)
- Full unit + integration suites green (baseline 1303 / 882, only the 2 known AdminTrajectoryOptions flakes).
- Divergent-rows query returns 0 after migration.
- The 2 drift-encoding tests (`TrajectoryPriceLifecycleSaveTest`, `QuoteAdminNewQuoteCreationTest`) rewritten to the single-price contract and green.
- Manual smoke (optional): trajectory admin shows ONE price input; a voucher still applies a discount correctly.

## Out of scope
- Removing `price_non_member` meta key (D1 keeps it).
- Removing MembershipService or the `stride/membership/is_member` filter.
- Any voucher-system change (discounts already live there — that's the whole point).
