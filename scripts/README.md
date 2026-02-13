# Stride Development Scripts

## Seed Data

Create test data for development:

```bash
ddev exec wp eval-file scripts/seed.php
```

This creates:
- **11 users**: 1 admin, 2 instructors, 5 students, 3 corporate users
- **8 courses**: In-person, online, cancelled, postponed variants
- **3 groups**: Learning trajectories
- **7 vouchers**: All discount types (full, percentage, fixed)
- **10-20 quotes**: Draft, sent, and exported statuses
- **Enrollments**: Students enrolled in random courses

### Test Credentials

All seed users have password: `seedpass123`

| User | Email | Role |
|------|-------|------|
| seed_admin | admin@seed.test | Administrator |
| seed_instructor1 | instructor1@seed.test | Group Leader |
| seed_student1-5 | student1@seed.test | Subscriber |
| seed_corp1-3 | corp1@company-a.test | Subscriber |

### Voucher Codes

| Code | Discount | Type |
|------|----------|------|
| SEED-FREE-100 | 100% off | Single-use |
| SEED-20PERCENT | 20% off | Multi-use (50) |
| SEED-50EURO | €50 off | Multi-use (100) |
| SEED-MEMBER2024 | 15% off | Unlimited |
| SEED-COURSE-ONLY | 25% off | Courses only |
| SEED-EXPIRED | 100% off | Expired |
| SEED-USED-UP | €30 off | Exhausted |

## Remove Seed Data

Clean up all seed data:

```bash
# Preview what will be removed
ddev exec wp eval-file scripts/unseed.php

# Actually remove the data
ddev exec wp eval-file scripts/unseed.php -- --force
```

## How It Works

Both scripts use the `_stride_seed_data` meta key to track what was created. This ensures only seed data is removed during cleanup, never your manually created content.

A manifest is stored in `wp_options` (`stride_seed_manifest`) for reference.
