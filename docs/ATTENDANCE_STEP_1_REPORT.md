# Attendance and leave foundation — Step 1

Completed locally on 2026-09-08 in `C:\Users\DELL\Desktop\downloads\antraajaal\job_project`, at starting commit `bb9e629` on `main`. No commit, push, deployment, production migration, or Step 2 work was performed.

## 1. Existing architecture inspected

- Laravel 12 / PHP ^8.3, MySQL by default. Verification used PHP 8.4.25 and PHPUnit 11.5.56.
- Users have `VARCHAR(40)` string primary keys. Existing code uses both prefixed random IDs and UUIDs. New records use UUID strings in matching 40-character columns.
- Existing service code primarily uses Laravel's query builder, explicit transactions, `abort` exceptions and validation exceptions. This foundation follows that pattern; additional Eloquent models and service-provider bindings were unnecessary.
- Dashboard controllers resolve the session key `nagare_user_id` against current users. Passport authenticates MCP's API guard. Dashboard session handling and OAuth configuration remain unchanged.
- Administrators are `role=admin, role_id=1`; employees are `role=team, role_id=2`; clients are excluded. These are role identifiers, not hardcoded individual user IDs.
- Existing `StateConcurrency` takes a database-scoped MySQL advisory lock before starting a transaction. Task/dashboard/MCP writers already use it. The foundation reuses this service unchanged.
- Inspected migrations, user model, app/database/auth/session config, bootstrap/provider registration, web/MCP routes, state/dashboard/task controllers and services, isolated test setup, and existing task/MCP/review test coverage.
- Existing `config/app.php` already uses `Asia/Kolkata`; it was not modified.

## 2–5. Implementation and complete file inventory

New files:

1. `config/attendance.php` — centralized business configuration.
2. `database/migrations/2026_09_08_000000_create_attendance_and_leave_tables.php` — the only new migration; creates four new tables.
3. `app/Services/AttendancePolicy.php` — time, calendar and working-day rules.
4. `app/Services/AttendanceAccess.php` — reusable authorization boundaries.
5. `app/Services/AttendanceOverrideService.php` — authorize/revoke employee-specific overtime.
6. `app/Services/LeaveService.php` — submit, approve, reject and calculate yearly balances.
7. `tests/Feature/AttendanceFoundationTest.php` — 18 backend tests.
8. `tests/attendance-approval-worker.php` — isolated subprocess contention-test helper.
9. `docs/ATTENDANCE_STEP_1_REPORT.md` — this report.
10. `docs/ATTENDANCE_STEP_1_COMMANDS.md` — command transcript and intermediate results.

Existing source/config/test/migration files modified: **none**. `bootstrap/cache/services.php` was already modified before work started; its existing diff remains untouched. Ignored local PHPUnit/cache outputs and Composer autoload files were generated during verification. No dependencies or lockfile were changed.

## 6. Database tables, columns and constraints

All primary keys below are `string(40)`. All user foreign keys match the existing `users.id` type. Laravel creates the necessary supporting foreign-key indexes on MySQL.

### attendance_records

| Columns | Type / behavior |
|---|---|
| id | Primary key |
| user_id | Required FK to users; cascade on employee deletion |
| attendance_date | Required date interpreted in IST |
| check_in_at, check_out_at | Nullable datetime |
| status | Required enum: present, absent, paid_leave, unpaid_leave, weekly_off |
| is_late, is_early_checkout | Boolean, default false |
| worked_minutes | Nullable unsigned integer |
| check_in_latitude, check_in_longitude, check_out_latitude, check_out_longitude | Nullable decimal(10,7) |
| check_in_accuracy, check_out_accuracy | Nullable decimal(10,3), intended metres |
| notes | Nullable text |
| created_at, updated_at | Laravel timestamps |

Unique index: `(user_id, attendance_date)`. Reporting index: `(attendance_date, status)`. `late` is not a status alternative to `present`. No records are automatically created, and there is no check-in API or arrival-time rejection rule.

### attendance_working_day_overrides

| Columns | Type / behavior |
|---|---|
| id | Primary key |
| user_id | Required FK; cascade on employee deletion |
| work_date | Required date |
| type | Enum overtime; default overtime |
| approved_by | Nullable FK to users, null on reviewer deletion |
| notes | Nullable authorization text |
| revoked_at | Nullable datetime |
| revoked_by | Nullable FK to users, null on reviewer deletion |
| revocation_note | Nullable text |
| created_at, updated_at | Laravel timestamps |

Unique index: `(user_id, work_date)`, named `attendance_override_user_date_unique`. Index on `work_date`. Revocation retains the row and original authorization instead of deleting it.

### leave_requests

| Columns | Type / behavior |
|---|---|
| id | Primary key |
| user_id | Required FK; cascade on employee deletion |
| start_date, end_date | Required dates |
| reason | Required text; nonblank validated by service |
| status | Enum pending, approved, rejected; default pending |
| reviewed_by | Nullable FK to users, null on reviewer deletion |
| reviewed_at | Nullable datetime |
| review_note | Nullable text |
| qualifying_days, paid_leave_days, unpaid_leave_days | Unsigned integers, default zero |
| created_at, updated_at | Laravel timestamps |

Index: `(user_id, status)`. Strict ISO date validation and end-before-start rejection occur in the domain service. Approval recomputes qualifying days and saves totals with allocations in one transaction.

### leave_request_days

| Columns | Type / behavior |
|---|---|
| id | Primary key |
| leave_request_id | Required FK to request, cascade on request deletion |
| leave_date | Required qualifying IST calendar date |
| type | Required enum paid or unpaid |

Unique index: `(leave_request_id, leave_date)`. Balance-reporting index: `(leave_date, type)`. Request review metadata audits these immutable allocations, so separate allocation timestamps and mutable yearly balance rows are unnecessary.

Migration `up()` creates only these four tables. `down()` removes only these tables in dependency order. Historical migrations were not edited. Migrations were executed only against isolated test databases, not the configured application database.

## 7–9. Configuration and IST enforcement

```php
'timezone' => 'Asia/Kolkata',
'shift_start' => '09:30',
'shift_end' => '18:30',
'grace_minutes' => 15,
'annual_paid_leave_days' => 12,
```

`AttendancePolicy` explicitly passes the configured IANA timezone to CarbonImmutable. Today's date comes from Laravel/server time in that timezone. Shift times and leave calendar dates are constructed in it. Offset-aware timestamps are converted to IST while preserving their instant; naive server timestamps are interpreted as IST. Literal leave dates must be valid `YYYY-MM-DD` dates, without relative-date parsing.

Business calculations do not depend on PHP's default timezone, `app.timezone` changing later, a browser clock, or manually added offsets. Audit timestamps retain the application's existing `now()` storage convention (currently IST). Future check-in writers must use server timestamps and this normalization service; no timestamp-writing HTTP API exists yet.

## 10–12. Policy methods and weekly offs

Public methods: `timezone`, `normalize`, `today`, `date`, `isSunday`, `isSecondSaturday`, `isWeeklyOff`, `hasOverride`, `isWorkingDay`, `shiftStart`, `shiftEnd`, `graceCutoff`, `isLate`, `qualifyingDates`.

Sunday detection uses Carbon's `isSunday()` on the IST calendar date.

Second Saturday detection first verifies Saturday, then checks that the preceding Saturday is in the same month and the Saturday two weeks earlier is outside that month. This calculates the actual second occurrence rather than assuming a fixed date. Other Saturdays remain working days.

`isLate` compares the normalized instant to that local date's configured shift start plus grace. Exactly 09:45:00 IST is on time; 09:45:01 is after the cutoff and late. Later arrivals remain eligible for `present` with `is_late=true`.

## 13–15. Overrides, attendance and leave requests

`AttendanceOverrideService::authorize(actorId, userId, date, notes)` verifies a current admin, a distinct target team member and a weekly-off date. It records the approving admin and rejects duplicate user/date overrides. An active override affects only that employee/date. `revoke` records the admin, time and note; revoked rows no longer authorize work. Reauthorization of a revoked row is deliberately not provided in Step 1.

Attendance supports one normal record per employee/date, independent late and early-checkout flags, work duration and future GPS data. No geofencing, location collection, absent generation or attendance write endpoint was implemented.

`LeaveService::submit(actorId, start, end, reason)` creates leave only for the authenticated team member. Pending requests record qualifying days but allocate zero paid/unpaid days. Admin review is explicit through `approve` or `reject`. Only pending requests can be reviewed; duplicate review returns a conflict. Review operations re-read the current actor role from the database.

## 16–18. Entitlement, historical use and mixed allocation

`balance(actorId, userId, year)` returns `year`, `entitlement`, `paid_used`, `paid_remaining`, `unpaid_used`. It joins daily allocations to **approved** requests and counts only dates within the selected calendar year. Pending and rejected requests consume nothing. Remaining paid leave is `max(0, entitlement - approved paid days)`.

During approval, qualifying dates are traversed chronologically. Each year's remaining allowance is loaded, decremented locally for each paid date, and remaining qualifying dates become unpaid. A single request can therefore contain both types. With seven already used, seven more qualifying days yield five paid and two unpaid. Request totals and daily rows are committed together.

No mutable annual-balance counter is maintained. Historical approved per-date rows are authoritative; manually importing request totals alone would not populate a balance and is not a supported import path.

## 19. Month/year boundaries and overtime decision

Inclusive date iteration naturally crosses weeks, months, leap years and calendar years. Every daily allocation has its own leave date. December uses December's calendar-year allowance; January independently uses the next year's allowance. Request totals summarize both without losing the yearly split.

Normal Sundays and second Saturdays never consume annual leave, **even if the employee has an overtime override**. Leave uses the normal office schedule, not optional overtime. An all-weekly-off request may be approved with zero qualifying/paid/unpaid days.

## 20. Concurrency and integrity

- Review must start outside an existing transaction. This prevents callers from bringing an old repeatable-read snapshot into allocation.
- Existing `StateConcurrency` obtains its MySQL advisory writer lock before opening the transaction. Concurrent Karya approval calls therefore read after the preceding approval commits.
- The employee row and leave request are locked with `lockForUpdate`; status is rechecked under the lock.
- Allocation, totals and review status are one transaction. Any insertion/update failure rolls back all changes.
- An approved qualifying date cannot be approved again through another overlapping request. Pending/rejected overlaps do not reserve dates.
- Unique indexes enforce duplicate attendance, overrides and request/date allocation protection. Foreign keys reject invalid employee/reviewer references.
- Direct SQL writes bypass domain authorization, range checks and aggregate allocation rules. Future endpoints must call these services rather than directly editing allocation rows.
- On SQLite, a competing transaction can receive a database-locked error; a fresh retry recalculates the committed balance. The subprocess test covers this and asserts that both final approvals together never exceed twelve paid days.

## 21. Authorization boundary

Future controllers must resolve the authenticated identity from the existing session/guard and pass that identity as `actorId`; never accept an actor ID from request input. `AttendanceAccess::view` allows self-team access or admin review. `selfAttendance` allows team members to change only their own attendance. `employee` excludes clients and admins from employee-only operations. `admin` requires both current admin role fields and forbids self-authorization.

No routes, controllers, frontend permissions or MCP tools were added in this step.

## 22. Tests added

Eighteen tests cover:

1. IST config, server midnight/year rollover, foreign PHP/app timezone independence and UTC-to-IST cutoff conversion.
2. All supplied on-time/late examples, second-level grace boundary and configurable grace/shift values.
3. Every calendar day across 2026–2028, including all Saturday occurrences and Sundays.
4. Employee-specific override, audit, date isolation, revocation and unchanged leave qualification.
5. Database uniqueness for overrides.
6. Present + Late, six GPS fields with positive/negative precision, and attendance uniqueness.
7. Pending/rejected leave consumes zero entitlement.
8. Seven used followed by three paid days.
9. Seven used followed by five paid/two unpaid; subsequent unpaid leave; employee balance isolation.
10. Normal/second Saturdays, Sundays, month boundaries and zero-day leave.
11. December–January per-year mixed allocation.
12. Invalid/reversed dates, relative dates and blank reasons.
13. Current-role authorization, self/cross-user restrictions, clients, missing users and role changes.
14. Repeated/overlapping approval protection.
15. Outer-transaction rejection and partial-allocation rollback.
16. Invalid employee/reviewer foreign keys.
17. Two independently bootstrapped concurrent approval processes using a temporary isolated SQLite database.
18. New-migration rollback and compatibility with existing user-deletion semantics.

## 23–25. Commands, results and existing tests

Exact shell commands and intermediate outcomes are recorded in `ATTENDANCE_STEP_1_COMMANDS.md`.

Final full-suite command:

```powershell
$env:APP_PACKAGES_CACHE = 'storage/framework/cache/attendance-test-packages.php'; $env:APP_SERVICES_CACHE = 'storage/framework/cache/attendance-test-services.php'; php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-progress --display-skipped
```

Final result: **92 tests, 2,036 assertions, zero failures, zero errors, two skipped**. Runtime 8.480 seconds; memory 74.00 MB. Thus 90 tests passed. New-test-only run: **18 tests, 1,215 assertions, all passed**.

Skipped existing tests:

- `test_mysql_mutex_blocks_other_connections_and_releases_after_rollback`
- `test_mysql_json_milliseconds_and_nested_advisory_lock`

Both require the guarded dedicated MySQL test configuration. No application database credentials were used. Existing task, project, MCP and email tests were not edited or weakened. Pint formatted only the eight new PHP files. `git diff --check` passed; Git emitted only an existing cache file line-ending notice.

## 26. Assumptions and remaining verification limits

- Actual MySQL execution/locking was not exercised in this environment. The service uses the existing MySQL serializer, and concurrent SQLite workers were exercised. Run the dedicated MySQL tests before eventual deployment; no production database was accessed.
- Team-member deletion retains Karya's current behavior: employee attendance/leave/override data cascades on deleting the user. Reviewer deletion nulls reviewer references. This avoids blocking existing deletion functionality, but permanent audit retention after user deletion would require a separate retention/soft-delete requirement.
- New domain tables are not included in the legacy state payload/revision, because no attendance state UI is being introduced. New writes still share the existing writer lock.
- Changes to annual entitlement apply to balance calculations using the configured current value; there is no historical entitlement schedule or accrual/pro-rating/carry-forward system in Step 1.
- No re-review/cancellation of approved leave, reauthorization of revoked overrides, holiday calendar, overnight shift handling or historical import workflow is included.
- Check-in/out datetimes and GPS fields are schema preparation. Future write APIs must normalize server timestamps and validate geographic ranges, accuracy and check-out ordering.
- CLI SQLite extensions and package caches were handled per command; no PHP configuration or tracked Laravel cache was changed. Composer reported an existing composer.json/lock mismatch and unavailable Packagist access; it installed/updated no dependencies and only regenerated ignored autoload files.

## 27. Scope confirmation

Only new Step 1 backend/database/test/documentation files were added. No unrelated source functionality was changed. No frontend, final check-in/out APIs, geolocation, geofence settings, attendance MCP tools, production modification, commit, push or deployment occurred. Work stops here for review.
