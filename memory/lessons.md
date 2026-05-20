# Stride — Lessons Learned

Patterns, gotchas, and fixes discovered during development. Search here before debugging.

---

## Test Harness Gotchas

### Silent zero-output failure = fatal at class-load
- If `vendor/bin/phpunit tests/Integration/<file>.php` returns RC=255 with **zero bytes of output**, PHP died loading the test class itself (compile/link error, not an assertion failure).
- Cause we hit 2026-05-20: redeclaring a method that already exists in `IntegrationTestCase` (in `tests/Integration/bootstrap.php`) at a *narrower* visibility than the parent — e.g. defining `private function createTestCourse()` when the parent has `protected function createTestCourse()`. PHP emits `Access level must be protected or weaker` at compile time. No test output, no stack trace from phpunit, just exit 255.
- The `failOnRisky` + `beStrictAboutOutputDuringTests` + `failOnWarning` combo in `phpunit.xml.dist` amplifies this — fatals during file load produce no stream output at all.

**Diagnostic recipe when you see RC=255 + empty output:**
```bash
# Write a wrapper script INSIDE the project root (not /tmp host vs container)
cat > _diag.sh <<'EOF'
#!/bin/bash
cd /var/www/html
php -d display_errors=stderr -r "
require 'vendor/autoload.php';
require 'tests/Integration/bootstrap.php';
register_shutdown_function(function () {
    \$e = error_get_last();
    if (\$e) echo 'FATAL: ' . print_r(\$e, true);
});
require 'tests/Integration/<your-test>.php';
"
EOF
chmod +x _diag.sh
ddev exec /var/www/html/_diag.sh
```
The shutdown handler catches and prints fatals that phpunit swallows.

**Before adding any helper to an integration test:**
- Grep for `protected function <name>` in `tests/Integration/bootstrap.php`. Reuse the parent's helper; don't redeclare.
- Existing protected helpers as of 2026-05-20: `createTestEdition`, `createTestCourse`, `createTestVoucher`, `createTestQuote`, `createTestLtiPlatform`, `actingAs`, `assertUserMeta`, `assertUserMetaEmpty`, `cleanupUserMeta`, `deleteTestRegistration`.

---

## Schema Migrations on Existing Tables

### dbDelta silently refuses ALTERs on `vad_registrations`
- Reproduced 2026-05-20 adding `parent_registration_id` for trajectory cascade. The CREATE TABLE uses `INDEX` (not `KEY`) and other formatting dbDelta's parser dislikes — so on existing DBs, dbDelta returns without errors but applies nothing.
- The migration-option flag flips to `1`, suggesting success. SHOW COLUMNS confirms nothing changed. Easy to miss.
- **Use explicit ALTER TABLE for migrations, not dbDelta.** Keep dbDelta only for first-install CREATE.

### Migration recipe (one-shot ALTER, then clean up)
For a single column / index addition to an existing Stride table:

1. **Add the column to `CREATE TABLE`** in `<Module>/<X>Table.php::create()` so fresh installs get it.
2. **Write a temporary static migration method** on the same class — guarded by `INFORMATION_SCHEMA.COLUMNS` / `INFORMATION_SCHEMA.STATISTICS` so it's idempotent:
   ```php
   $hasColumn = $wpdb->get_var($wpdb->prepare(
       "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = %s
          AND COLUMN_NAME = 'parent_registration_id'",
       $table
   ));
   if ((int) $hasColumn === 0) {
       $wpdb->query("ALTER TABLE {$table} ADD COLUMN parent_registration_id BIGINT UNSIGNED NULL AFTER trajectory_id");
   }
   ```
3. **Add a one-shot shim in `stride-core.php`**, keyed on a new option flag:
   ```php
   if (get_option('stride_tables_created') && !get_option('stride_parent_reg_added')) {
       \Stride\Modules\Enrollment\RegistrationTable::addParentRegistrationColumn();
       update_option('stride_parent_reg_added', '1');
   }
   ```
4. **Trigger it locally** — `ddev exec wp eval 'echo "boot";'` is enough (any `wp` command boots WP and fires init).
5. **Verify** — `ddev exec wp db query "SHOW COLUMNS FROM <prefix>_vad_registrations LIKE 'xxx'"`.
6. **Run the same shim on staging and prod** before merging.
7. **Then clean up.** Once the ALTER is everywhere it needs to be:
   - Remove the migration method from `<X>Table.php`
   - Remove the shim from `stride-core.php`
   - Delete the option flag (`wp option delete stride_xxx_added`) on each env
   - The `CREATE TABLE` keeps the column, so fresh installs stay correct.

The migration code is throwaway scaffolding. Once the column exists in every env, the runtime code shouldn't carry the migration forever.

---

## Architecture Patterns

### Service Registration Rules
- Only top-level feature domains go in `plugin-config.php`
- Sub-components (admin controllers, settings pages, API controllers) are owned by parent services
- DI does NOT require `NTDST_Service_Meta` — any class in the services array gets autowired
- Test: "Would this make sense as a standalone WordPress plugin?" → service. Otherwise → sub-component.

### Service vs Handler vs Business Logic
- **Service:** Feature orchestrator — lifecycle, config, DI, hooks. Delegates logic.
- **Handler:** Thin routing — catches WP events (AJAX/REST), validates, delegates. No constructor DI — use `ntdst_get()`.
- **Business class:** Pure domain logic — rules, calculations. No hooks. WP-free testable.
- **Repository:** Data access. No business events.
- Key insight: "If a class has hooks AND business logic, split into thin handler + business class."

### Sub-component Ownership
- Needs `ntdst_get()` access? → `ntdst_set()` as singleton in parent's `init()`
- Admin UI / handler? → `new` directly (registers own hooks in constructor)
- CPT registration → parent service's `init()`, not sub-component

### Event Dispatch Ownership
- Business events (`do_action`) belong in the **service layer**, NOT repositories
- Repos firing events + services firing events = duplicate side effects (e.g., duplicate emails)

### Plain Class DI
- Same-module deps → constructor injection
- Cross-module deps → lazy `ntdst_get()` in methods that need them

### Admin Handlers Must Route Through Services
- Admin AJAX/REST must call service methods, not repository methods directly
- Services fire events (audit, auto-complete, notifications). Bypassing = silent failures.

### Mail Template Recipient Routing
- Admin notification templates should NOT use auto-dispatch triggers
- Use explicit `ndmail_send()` with `['to' => adminEmail]` from a bridge handler
- Auto-dispatch defaults to the user from context → admin notifications go to wrong recipient

---

## Known Gotchas

### Data Manager silently destroys arrays for undefined fields
If a field isn't in `CPT::getFields()`, `Data::sanitizeField()` falls back to `sanitize_text_field()` which converts arrays to string "Array". Always define fields that store arrays (use type `relation` for ID arrays).

### Data Manager meta keys have _ntdst_ prefix in getPostsFast()
`Data::getPostsFast()` returns meta with `_ntdst_date`, `_ntdst_start_time` etc. `formatSessionArray()` strips the prefix for consumers. When sorting/filtering raw meta, use prefixed keys.

### stride_format_money() expects cents, edition price stores euros
`stride_format_money()` expects int cents. Edition CPT `price` is float euros. Always: `stride_format_money((int) ($price * 100))`.

### Admin list filter params must NOT match taxonomy slugs
WordPress `parse_tax_query` auto-adds a tax_query when a GET param matches a public taxonomy slug. Produces 0 results silently. Renamed `stride_format` → `edition_format` for edition list filter.

### WordPress magic quotes corrupt JSON in $_POST
`wp_magic_quotes()` adds backslashes. JSON in hidden fields arrives escaped. Always `wp_unslash()` before `json_decode()`.

### MetaboxGenerator nonce missing for custom metaboxes
NTDST MetaboxGenerator's `save_metabox_data()` requires `ntdst_{post_type}_nonce` — only output by auto-generated metaboxes. Custom metaboxes must handle save explicitly.

### Quote billing field is `company`, not `organisation`
Enrollment form saves billing company as `company`. Quote meta stores as `billing.company`. Never use `billing['organisation']`.

### Sidebar price must pass user's member status
`EditionService::getPrice($editionId)` without `$userId` defaults to member pricing. Always pass `$userId`.

### WordPress locale affects all date_i18n() output
WPLANG `nl_BE` makes all `date_i18n()` output Dutch. Tests asserting English text will break.

### Seed user emails don't match CLAUDE.md docs
CLAUDE.md says `seed_student1@seed.test` but actual email is `student1@seed.test`. Login field is `seed_student1`, email is `student1@seed.test`.

### Dual renderSessionRow() implementations must stay in sync
`EditionSessionsMetabox::renderSessionRow()` (server) and `EditionAdminController::renderSessionRow()` (AJAX) must output identical column structure.

### JS lesson cache race condition
`loadLessonsForSelect()` is async first call, synchronous from cache after. Code that sets state must run BEFORE the load call to work for both paths.

### Partner API session duration field doesn't exist
Sessions have `_ntdst_start_time` and `_ntdst_end_time`, NOT `duration`. Calculate hours from time difference.

---

## Solved Bugs (Reference)

### DI Container: Interface bindings create duplicate instances
`Container::resolve()` called `resolveClass($service)` for string→class mappings instead of `$this->get($service)`. Fix: use `$this->get($service)` to go through singleton cache.

### SessionRepository sorts by wrong meta keys
Sorted on `$a['meta']['date']` (WP creation date) instead of `$a['meta']['_ntdst_date']` (scheduled date).

### SessionRepository overwrites user-set post_title
Both `create()` and `update()` unconditionally set `post_title`. Fix: only auto-generate if empty/not set.

### Session lesson_ids saved as string "Array"
`SessionCPT::getFields()` missing `lesson_ids`, `description`, `webinar_link`. Fix: add field definitions.

### Open courses sidebar shows wrong state after starting
`LearnDashHelper::isEnrolled()` only checked `course_{id}_access_from` meta. Open courses don't set this. Fix: also check `getProgress() > 0`.

### LearnDash getAccessMode returns empty for single-key lookup
`learndash_get_setting($id, 'course_price_type')` can return empty. Fix: fallback to full settings array.

### Quote billing update doesn't persist
Handler reads `$params['billing']` but frontend sends flat fields. Fix: fallback to `$params` when no `billing` key.

### Vite dev mode breaks strideConfig
`wp_add_inline_script` needs a registered handle. Fix: register dummy `stridence-main` handle in dev mode.

### Brave browser: stale pages from speculation rules
WP 6.9 Speculation Rules + Brave cookie stripping = unauthenticated prefetched pages. Fix: disable speculation rules, block prefetch requests, add SameSite=Lax to cookies.

---

## Git Workflow
- `staging` is the primary working branch
- `main` is production-only
- Feature branches → staging → main
- Remote: `origin` → `github.com:netdust/stridelms`

---

## Working Discipline

### `memory/STATE.md` and `memory/lessons.md` are MY responsibility to update
CLAUDE.md says "Memory and tasks are managed automatically by global hooks" but in practice the hooks don't always fire / aren't reliable for project-local memory. Stefan called this out 2026-05-16: STATE.md was 3 days stale and lessons.md was 2 months stale during an active session. The auto-memory at `~/.claude/projects/.../memory/` is a SEPARATE store from this project-local `memory/` directory — don't conflate them.

**Rule:** At the end of any session with meaningful work (design docs, decisions, refactors, debugging breakthroughs), update `memory/STATE.md` AND `memory/lessons.md` directly before considering the session done. Don't assume a hook handled it. The "Last refresh:" line at the top of STATE.md is the canary — if it's older than today, the file is stale.

### Project-local memory vs auto-memory
Two distinct stores exist:
- **`memory/STATE.md` + `memory/lessons.md`** — project-checked-in, project-scoped, source-of-truth for project continuity. Updated by hand.
- **`~/.claude/projects/-home-ntdst-Sites-stride/memory/`** — Claude's auto-memory (cross-session, includes `MEMORY.md` index + named topic files). Useful but separate.
The project memory is what survives in git for the team. Auto-memory survives for me across sessions on this machine.

---

## API Design Decisions

### WordPress REST API is the wrong choice for a public consumption API
Enabling `show_in_rest => true` on a CPT seems like a free win but: (a) the response shape leaks `_ntdst_` prefixes and serves prices as strings, (b) every meta field becomes publicly readable unless individually gated (security footgun), (c) computed/joined data (capacity remaining, edition+sessions+course in one trip) needs custom controllers anyway, (d) mixes inconsistently with the existing `stride/v1/...` namespace pattern.

**Rule:** For any *public consumption* API, hand-shape a `stride/v1/public/*` namespace. Use WP REST only for what it's designed for (Gutenberg authoring, internal tooling). This mirrors how WooCommerce coexists: `wp/v2/products` exists, but `wc/v3/products` is the actual product.

### Core vs Capability split
PartnerAPI was sitting inside `stride-core/Modules/PartnerAPI/` despite being outward-facing and per-client optional. Same trap would happen if Conference API or LTI got added to core. **Rule:** "Capability plugin = anything outward-facing or optional per client." If a client without partners shouldn't load partner code, it's not core. Outward-facing capabilities become their own mu-plugins, depending on `stride-core` via the DI container and public interfaces only.

### Extract before invent
When establishing a new architectural pattern (e.g. "capability plugin extends core"), refactor existing working code into the new shape FIRST (the refactor is low-risk and validates the pattern with a known-good test suite), then build the second instance against the proven shape. Inventing the pattern on greenfield code is harder to verify.
