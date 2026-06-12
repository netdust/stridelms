# Bug Manifest — ntdst-core + stride-core

**Generated:** 2026-03-21
**Build status:** 600 unit tests + 210 integration tests pass (1 warning), all services resolve, all routes work
**Sweep status:** Automated [18 checks]

---

## Summary

0 bugs found in ntdst-core/stride-core.

1 pre-existing third-party warning (LearnDash).

---

## Infrastructure Verification Results

| Check | Result |
|-------|--------|
| Container: NTDST_Theme | OK |
| Container: NTDST_Data_Manager | OK |
| Container: NTDST_Router | OK |
| Container: NTDST_Logger | OK |
| Bootstrap: ntdst/core_ready | Fired (1x) |
| Bootstrap: ntdst/features_ready | Fired (1x) |
| Bootstrap: ntdst/services_registered | Fired (1x) |
| Stride services (9 tested) | All resolve OK |
| Deep DI chains (ChatController, AuditBridge, AuthService) | All resolve OK |
| Router: /login, /register, /auth/logout, / | All correct status codes |
| Data Manager: vad_edition query | 3 results returned |
| CPT registrations (edition, session, trajectory, quote, voucher, mail) | All registered |
| Logger: write log entry | OK (file-based, no DB table) |
| Mailer: send test email | OK — received in Mailpit |
| Response: render, json, redirect methods | All present |
| Sectors: gallery (essential) | Enabled and correct tier |
| REST API: 17 stride routes | All registered |
| PHP errors after page loads | None |
| Admin toolkit CSS | Exists (21KB) |
| Smoke tests (front page + admin) | PASS |

---

## Notes

### Integration test warning (third-party, not actionable)
- LearnDash `ld-course-progress.php:952` — `Undefined array key "activity_status"`
- This is inside LearnDash's own code, triggered during 3 integration tests
- Not an ntdst-core or stride-core issue

---

## Final Status

**Resolved:** N/A
**Deferred:** 0
**Bugs found:** 0
**Final sweep:** PASS — ntdst-core and stride-core infrastructure is solid
