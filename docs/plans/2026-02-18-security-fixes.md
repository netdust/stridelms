# Security Fixes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix 4 low-severity security findings from the security audit to ensure code consistency and follow best practices.

**Architecture:** Standardize all SQL table existence checks to use `$wpdb->prepare()`, add safety comments where identifiers can't be parameterized, and host CDN assets locally.

**Tech Stack:** PHP 8.3, WordPress `$wpdb`, Select2 library

---

## Summary of Findings

| ID | Finding | Files |
|----|---------|-------|
| L-01 | Table existence checks use direct interpolation | 4 files |
| L-02 | ALTER TABLE uses direct table name | 1 file |
| L-03 | maybe_unserialize on DB values | 1 file (document only) |
| L-04 | CDN assets without SRI | 1 file |

---

### Task 1: Fix table existence check in RegistrationTable

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php:56`

**Step 1: Write the fix**

Change line 56 from direct interpolation to prepared statement:

```php
// Before (line 56):
return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

// After:
return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
```

**Step 2: Add safety comment to ALTER TABLE**

Add comment at line 78 explaining why direct interpolation is safe:

```php
if (!$indexExists) {
    // Table name from constant - safe from injection (identifiers can't use prepare())
    $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_edition_status (edition_id, status)");
}
```

**Step 3: Verify PHP syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php`
Expected: No syntax errors

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
git commit -m "security(db): use prepare() for table existence check in RegistrationTable"
```

---

### Task 2: Fix table existence check in SessionRegistrationTable

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/SessionRegistrationTable.php:52`

**Step 1: Write the fix**

Change line 52 from direct interpolation to prepared statement:

```php
// Before (line 52):
return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;

// After:
return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
```

**Step 2: Verify PHP syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Edition/SessionRegistrationTable.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionRegistrationTable.php
git commit -m "security(db): use prepare() for table existence check in SessionRegistrationTable"
```

---

### Task 3: Fix table existence checks in BatchQueryHelper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Infrastructure/BatchQueryHelper.php:71,209`

**Step 1: Fix batchGetRegistrationCounts (line 71)**

```php
// Before (line 71):
if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {

// After:
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
```

**Step 2: Fix batchGetAttendance (line 209)**

```php
// Before (line 209):
if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {

// After:
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
```

**Step 3: Add docblock note about maybe_unserialize**

Add comment at line 47:

```php
foreach ($results as $row) {
    // Note: maybe_unserialize follows WordPress core pattern for post meta.
    // Data comes from database, not user input. Risk is controlled by DB write capabilities.
    $meta[(int) $row->post_id][$row->meta_key] = maybe_unserialize($row->meta_value);
}
```

**Step 4: Verify PHP syntax**

Run: `php -l web/app/mu-plugins/stride-core/Infrastructure/BatchQueryHelper.php`
Expected: No syntax errors

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Infrastructure/BatchQueryHelper.php
git commit -m "security(db): use prepare() for table existence checks in BatchQueryHelper"
```

---

### Task 4: Fix table existence check in AdminAPIController

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:1440`

**Step 1: Write the fix**

Change line 1440 from direct interpolation to prepared statement:

```php
// Before (line 1440):
$enrollmentTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$enrollmentTable}'") === $enrollmentTable;

// After:
$enrollmentTableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $enrollmentTable)) === $enrollmentTable;
```

**Step 2: Verify PHP syntax**

Run: `php -l web/app/mu-plugins/stride-core/Admin/AdminAPIController.php`
Expected: No syntax errors

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "security(db): use prepare() for table existence check in AdminAPIController"
```

---

### Task 5: Host Select2 locally instead of CDN

**Files:**
- Create: `web/app/themes/stride/assets/vendor/select2/select2.min.css`
- Create: `web/app/themes/stride/assets/vendor/select2/select2.min.js`
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php:111-124`

**Step 1: Create vendor directory**

```bash
mkdir -p web/app/themes/stride/assets/vendor/select2
```

**Step 2: Download Select2 assets**

```bash
curl -o web/app/themes/stride/assets/vendor/select2/select2.min.css \
  https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css

curl -o web/app/themes/stride/assets/vendor/select2/select2.min.js \
  https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js
```

**Step 3: Update QuoteAdminController to use local assets**

Replace lines 111-124:

```php
// Before:
wp_enqueue_style(
    'select2',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    [],
    '4.1.0'
);
wp_enqueue_script(
    'select2',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
    ['jquery'],
    '4.1.0',
    true
);

// After:
wp_enqueue_style(
    'select2',
    get_stylesheet_directory_uri() . '/assets/vendor/select2/select2.min.css',
    [],
    '4.1.0'
);
wp_enqueue_script(
    'select2',
    get_stylesheet_directory_uri() . '/assets/vendor/select2/select2.min.js',
    ['jquery'],
    '4.1.0',
    true
);
```

**Step 4: Verify PHP syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php`
Expected: No syntax errors

**Step 5: Commit**

```bash
git add web/app/themes/stride/assets/vendor/select2/
git add web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php
git commit -m "security(assets): host Select2 locally instead of CDN"
```

---

## Verification

After all tasks complete:

1. Run PHP syntax check on all modified files
2. Verify the site loads correctly (if DDEV is running)
3. Check that admin quote pages still have Select2 functionality

---

## Files Modified Summary

| File | Changes |
|------|---------|
| `Modules/Enrollment/RegistrationTable.php` | prepare() + safety comment |
| `Modules/Edition/SessionRegistrationTable.php` | prepare() |
| `Infrastructure/BatchQueryHelper.php` | prepare() x2 + docblock note |
| `Admin/AdminAPIController.php` | prepare() |
| `Modules/Invoicing/Admin/QuoteAdminController.php` | Local Select2 path |
| `themes/stride/assets/vendor/select2/*` | New files |
