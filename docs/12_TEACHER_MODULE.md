# Teacher Module

## Dashboard
- Assigned classes
- Today's schedule
- Attendance tasks
- Grade tasks
- Notifications

## Classes
Teacher can access only assigned subjects/classes.

## Attendance
Statuses:
- PRESENT
- ABSENT
- LATE
- EXCUSED

Attendance must record:
- Student
- Class
- Date
- Status
- Recorded by
- Timestamp

## Grades
Teacher can enter grades only for assigned classes and within permitted grading periods.

Grade changes should be auditable.

## Restrictions
Teacher cannot access cashier functions, registrar approval functions, or system administration.

---

## As implemented (Phase 4)

### Access
- Teacher routes require the **Teacher role and a permission**: `classes.view`,
  `attendance.record`, `grades.record`, `schedule.view`. No other role, including Super
  Admin, passes them (`tests/teacher_access_test.php` walks the real route table).
- Every class page loads the class through `GradingService::ownedClass()`. A class
  assigned to another teacher returns **404**, exactly like one that does not exist, so a
  teacher cannot even confirm it exists.

### Pages
| Page | Route |
|---|---|
| Dashboard: today's classes, open grading periods, grade sheets still incomplete | `/teacher/dashboard` |
| My classes | `/teacher/classes` |
| Roster, grading progress, recent attendance | `/teacher/classes/{id}` |
| Attendance sheet | `/teacher/classes/{id}/attendance?date=` |
| Grade sheet | `/teacher/classes/{id}/grades?period=` |
| Attendance and grading overviews | `/teacher/attendance`, `/teacher/grades` |
| Weekly schedule | `/teacher/schedule` |

### Attendance (`AttendanceService`)
- One row per student per date. Saving again **updates** the row; it never duplicates it.
- Refused, with the reason shown on the sheet:
  - a **future** date;
  - a date **outside the class's term**;
  - a day the class **does not meet**.
- Refused and nothing saved: a status that is not PRESENT, ABSENT, LATE, or EXCUSED, or a
  student not enlisted in the class.
- "Mark all present" only fills the sheet in the browser; the teacher still saves it.
- Each save that changes something writes **one** `attendance.recorded` audit entry for
  the whole sheet, with the changed statuses. This keeps a full roster every day from
  flooding the log.

### Grades
Defined in **`docs/27_GRADING_POLICY.md`**: the 1.00–5.00 scale, Prelim/Midterm/Finals
weights, the final-grade rule, period locking, and the reopen procedure for corrections.

### Not yet implemented
- Teacher profiles and teacher account management (Phase 7). Teacher accounts are
  created with `database/create_demo_users.php` until then.
- Class reports and exports (reporting phase).
