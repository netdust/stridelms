# Shake-out Manifest: Partner API

**Date:** 2026-03-21
**Scope:** PartnerAPIController, REST endpoints, company scoping, enrollment creation

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2 |
| IMPORTANT | 2 |
| MINOR | 3 |
| **Total** | **7** |

---

## Bugs

### BUG-P1: Attendance hours always return 0 [CRITICAL]

**What was tested:** Partner API attendance endpoint
**Expected:** Hours calculated from session start/end times
**Actual:** `PartnerAPIController.php:494` reads `$session->fields['duration']` which doesn't exist. Sessions have `start_time`/`end_time`, not `duration`.

**Files:** `PartnerAPIController.php:494`

---

### BUG-P2: Trajectory enrollment via Partner API loses company_id [CRITICAL]

**What was tested:** Partner enrollment creation for trajectories
**Expected:** company_id propagated to registration
**Actual:** `TrajectorySelection::enroll()` doesn't accept company_id — registration gets `company_id = NULL`, invisible to `findByCompany()` queries

**Files:** `PartnerAPIController.php:587-588`, `TrajectorySelection.php`

---

### BUG-P3: Enrollments endpoint doesn't sanitize pagination params [IMPORTANT]

**What was tested:** Enrollment listing pagination
**Expected:** Sanitized page/per_page values
**Actual:** Raw request values passed through. Response echoes unsanitized values (string "999" instead of capped int 100).

**Files:** `PartnerAPIController.php:183-184`

---

### BUG-P4: Orphaned registrations visible in API response [IMPORTANT]

**What was tested:** Enrollment listing data integrity
**Expected:** Orphaned data filtered or flagged
**Actual:** Registrations for deleted users return `user_email: null`, `course_title: null`

**Files:** `PartnerAPIController.php:262-279`

---

### BUG-P5: No `args` schema on route registrations [MINOR]

**Files:** `PartnerAPIController.php:46-85`

---

### BUG-P6: Partner enrollments use `individual` path instead of `partner` [MINOR]

**Files:** `PartnerAPIController.php:583`

---

### BUG-P7: createEnrollment response hardcodes `status: 'confirmed'` [MINOR]

**Files:** `PartnerAPIController.php:617`
