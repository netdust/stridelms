## Domain Model
- Course (sfwd-courses): LearnDash content only (lessons, quizzes, certificates).
- Edition (vad_edition): A scheduled offering of a course (dates, price, venue, capacity).
- Session (vad_session): Individual meeting days within an edition.
- Registration: User enrollment in an edition (not a course directly).
- Users enroll in EDITIONS, not courses. Editions belong to courses.

## Business Rules
- An edition has a capacity. Never enroll beyond capacity.
- Registration statuses: pending → confirmed → completed | cancelled.
- A cancelled registration cannot be re-confirmed — create a new one.
- Unenrolling revokes LearnDash access. Always mention this side effect.
- Enrollment grants LearnDash access. Always mention this.
