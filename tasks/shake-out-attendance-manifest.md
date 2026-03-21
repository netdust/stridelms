# Shake-out Manifest: Attendance Module

**Date:** 2026-03-21
**Scope:** AttendanceService, AttendanceRepository, admin attendance toggle, cascade delete

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 2 |
| IMPORTANT | 2 |
| MINOR | 2 |
| **Total** | **6** |

---

## Bugs

### BUG-A1: Admin attendance toggle bypasses AttendanceService [CRITICAL]

**What was tested:** Code review of admin attendance flow
**Expected:** Admin marking attendance goes through AttendanceService (fires events)
**Actual:** `EditionAdminController.php:656` and `:696` call `$this->attendanceRepository->record()` directly
**Severity:** CRITICAL — `stride/attendance/marked` event never fires, so:
- AuditBridge does not log attendance changes (audit gap)
- EditionCompletion auto-complete check never triggers

**Files:** `EditionAdminController.php:656,696`

---

### BUG-A2: AdminAPIController REST endpoint also bypasses AttendanceService [CRITICAL]

**What was tested:** Code review of REST API attendance endpoint
**Expected:** REST API attendance marking goes through service layer
**Actual:** `AdminAPIController.php:1201` calls repository directly — same consequences as BUG-A1

**Files:** `AdminAPIController.php:1201`

---

### BUG-A3: `marked_by` missing from event payload [IMPORTANT]

**What was tested:** Code review of event dispatch
**Expected:** `stride/attendance/marked` payload includes `marked_by`
**Actual:** Payload omits `marked_by` at `AttendanceService.php:74-80` — AuditBridge falls back to NULL actor

**Files:** `AttendanceService.php:74,106,138`

---

### BUG-A4: Cascade delete doesn't clean up attendance records [IMPORTANT]

**What was tested:** Code review of edition deletion cascade
**Expected:** Deleting an edition also deletes attendance records
**Actual:** `EditionService::onEditionDeleted()` deletes sessions and registrations but not attendance. `deleteBySession()` exists in repo but is never called from cascade path.

**Files:** `EditionService.php:293-336`, `AttendanceRepository.php:272`

---

### BUG-A5: Orphan `stride_vad_session_registrations` table [MINOR]

**What was tested:** Database integrity
**Expected:** All tables have code references
**Actual:** Table exists with 0 records, zero PHP code references anywhere. Dead infrastructure.

---

### BUG-A6: Semantic inconsistency between `isPresent()` and `countAttended()` [MINOR]

**What was tested:** Code review
**Expected:** Consistent attendance counting
**Actual:** `isPresent()` uses `countsAsAttended()` (extensible), but `countAttended()` and `getPresentUserIds()` hardcode `status = 'present'`. Maintenance trap if statuses are extended.

**Files:** `AttendanceRepository.php:194,230,251`
