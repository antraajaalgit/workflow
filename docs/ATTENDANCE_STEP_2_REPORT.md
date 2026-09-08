# Attendance Step 2 — backend self-attendance APIs

Completed locally on `main`, based on `bb9e629` plus the existing uncommitted Step 1 foundation. No commit, push, deployment, frontend, geofence or Step 3 work was performed.

## 1. Step 1 architecture reused

Read both Step 1 reports and inspected its migration, configuration, services, tests and worker. Reused `AttendancePolicy`, `AttendanceAccess`, the existing attendance/override/leave tables, and `StateConcurrency`. Existing query-builder, string-ID, session, HTTP error and transaction conventions are retained. No duplicate leave allocator, override authorizer, role system or schema was introduced.

## 2–3. Complete Step 2 file inventory

Created:

- `app/Http/Controllers/AttendanceController.php`
- `app/Services/AttendanceService.php`
- `tests/Feature/AttendanceApiTest.php`
- `tests/attendance-api-worker.php`
- `tests/verify-attendance-local.php`
- `docs/ATTENDANCE_STEP_2_REPORT.md`
- `docs/ATTENDANCE_STEP_2_COMMANDS.md`

Modified:

- `app/Services/AttendancePolicy.php`: added the reusable server-side `now()` method; `today()` delegates to it.
- `routes/web.php`: one controller import and three attendance routes.

All other Step 1 files and reports are unchanged. Step 1 files still appear untracked because neither step has been committed. The already-modified `bootstrap/cache/services.php` was not changed by Step 2. Test-generated ignored cache/result files are local verification outputs.

## 4. Routes

| Method | Endpoint | Success |
|---|---|---|
| GET | `/api/attendance/today` | 200 |
| POST | `/api/attendance/check-in` | 201 |
| POST | `/api/attendance/check-out` | 200 |

All three routes use the existing `web` middleware group, like Karya's other dashboard APIs. Route-list verification confirmed the three mappings and middleware. No new API authentication guard was introduced.

## 5. Authentication and authorization

The controller obtains only `nagare_user_id` from the authenticated Karya session. The service queries the current user and requires `role=team` and `role_id=2`. Missing/deleted sessions receive 401; admins, clients and inconsistent roles receive 403. These are existing role values, not individual IDs.

No request body or query fields are consumed. Supplied `user_id`, date, timestamp, check-in/out time, status, flags, worked minutes and coordinates are ignored. The caller cannot target another employee or historical date. Session and CSRF configuration were not modified or bypassed. A test explicitly disables Laravel's testing-only CSRF bypass and verifies 419 for missing tokens and success with a valid token.

## 6. Check-in workflow

1. Start the existing serialized write operation and lock the employee row.
2. Revalidate the current employee role.
3. Capture one server-side IST timestamp after acquiring the lock.
4. Resolve that date's weekly-off status, active employee override, approved leave allocation and existing attendance.
5. Reject unavailable/duplicate attendance with a clear 409 message.
6. Insert a UUID-backed attendance record for that session employee/date with `status=present`, server check-in, policy-derived late flag and false early-checkout flag.
7. Leave checkout, worked minutes and all six location fields null.
8. Return the updated attendance state with a success message.

## 7. Check-out workflow

Under the same serializer/transaction and employee lock, capture server IST time and lock today's attendance row. Require a present record with a check-in and no checkout. Enforce the current working-day/override rule. Reject a backwards clock rather than storing a negative duration. Save server checkout, actual whole worked minutes and the early-checkout flag atomically. Only today's row is selected; checkout never creates a missing row or closes yesterday's record.

## 8. Server-side Asia/Kolkata time

`AttendancePolicy::now()` uses `CarbonImmutable::now(config('attendance.timezone'))`, at second precision to match the existing datetime columns. `today()` and the new operations use this clock. The timestamp is captured once per operation, after write-lock acquisition, so its date, flags, persisted time and response are consistent.

Configuration remains `Asia/Kolkata`, shift start 09:30, end 18:30 and 15-minute grace. Persisted attendance datetimes are IST wall-clock values; responses explicitly include `+05:30`. Existing `normalize()` interprets stored values in the attendance timezone. Browser/device time never participates. Carbon's normal frozen test clock is used without production-specific testing hooks.

## 9–10. Late attendance

`AttendancePolicy::isLate()` remains the authority. 09:45:00 is on time, 09:45:01 is late. No late-arrival time blocks check-in: 10:30 successfully produces `status=present, is_late=true`. The flags use second-resolution timestamps, matching storage. Late is never converted to absent.

## 11–12. Early checkout and worked minutes

Checkout strictly before `AttendancePolicy::shiftEnd()` sets `is_early_checkout=true`; at exactly 18:30 or later it is false. Early checkout is accepted.

Worked minutes are `intdiv(checkout Unix seconds - checkin Unix seconds, 60)`: completed elapsed minutes, with partial final minutes rounded down. No lunch/break deduction and no shift-duration cap. Examples verified: 09:40–18:35 = 535 minutes; 09:40–20:00 = 620 minutes. A backwards clock produces a clean conflict and leaves the row unchanged.

## 13–14. Duplicate and concurrency protection

- Service-level existing-record and checkout checks preserve original timestamps.
- Step 1's unique `(user_id, attendance_date)` index remains the database backstop.
- `StateConcurrency` acquires the existing MySQL advisory writer lock before the transaction. This also serializes attendance against existing leave approval and overtime changes.
- Employee and checkout-record row locks protect read/write decisions.
- Operations own their transaction and reject callers with an already-open transaction, avoiding stale outer snapshots.
- Unique violations become a clear 409 conflict. SQLite lock contention and MySQL deadlocks/lock timeouts become retryable 409 messages.
- Other database failures are reported server-side and returned as generic 503 JSON without SQL/stack traces.
- Two-process tests race both check-in and checkout: each operation has exactly one success and one handled conflict, leaving one record with the original times and correct minutes.

## 15–17. Weekly offs, overtime and leave

Sunday/second-Saturday calculations are reused unchanged from Step 1. Without an active override, both availability flags are false and check-in returns `Office Closed – Weekly Off`. Other Saturdays are working days.

An override changes eligibility only for its user/date. `is_weekly_off` remains true to describe the normal calendar, while `day_type=overtime` and `is_working_day=true` describe the authorized employee's schedule. Normal shift timing is used for late/early flags on overtime days. No overtime-pay calculation exists.

Check-in joins `leave_request_days` to approved `leave_requests` for the current employee/date. Paid/unpaid allocation produces `Approved Paid Leave` or `Approved Unpaid Leave` and blocks check-in. Pending/rejected leave does not block it. No leave records are changed.

Decisions: if leave is approved after check-in, checkout may close the existing present record. If an overtime override is revoked on a weekly off, both attendance actions become unavailable, including checkout; the row is retained. This follows the requested active-override rule. No correction or reconciliation UI was added.

## 18. API response contract

Successful responses share this shape:

```json
{
  "attendance_date": "2026-09-08",
  "timezone": "Asia/Kolkata",
  "server_timestamp": "2026-09-08T09:30:00+05:30",
  "day_type": "working_day",
  "is_working_day": true,
  "is_weekly_off": false,
  "has_overtime_override": false,
  "approved_leave_type": null,
  "attendance": {
    "id": "<uuid>",
    "attendance_date": "2026-09-08",
    "status": "present",
    "check_in_at": "2026-09-08T09:30:00+05:30",
    "check_out_at": null,
    "is_late": false,
    "is_early_checkout": false,
    "worked_minutes": null
  },
  "can_check_in": false,
  "can_check_out": true,
  "message": "Checked in successfully."
}
```

Before check-in, `attendance` is null. After checkout both action flags are false. Day types are `working_day`, `weekly_off`, `overtime`; approved-leave type is null, `paid` or `unpaid`. `is_working_day` describes the schedule, while approved leave independently disables check-in.

Failures use `{"message":"..."}` with 401/403/409/503 as appropriate; existing CSRF failures remain 419. Responses use `Cache-Control: no-store, private`. User names, emails, reviewer identities, leave reasons, notes and location fields are not exposed.

GET is read-only: it inserts no attendance or absence records and updates no timestamps. State is advisory for the UI; POST always rechecks eligibility under its lock.

## 19. Migrations

**None added or modified.** Step 1 already contains every required column and index. The applied local schema was neither recreated nor replaced. No migration command was run in Step 2.

## 20. Automated tests added

Seventeen tests in `AttendanceApiTest` cover authentication/current roles, read-only today state, complete lifecycle, spoofed IDs/dates/times/flags/coordinates, on-time/late boundaries, duplicate preservation, Sunday/second-Saturday/normal Saturday, per-employee overtime, revocation, approved paid/unpaid leave, pending/rejected leave, leave approved after check-in, early-checkout and actual elapsed-minute boundaries, missing/historical/non-present attendance, UTC/IST midnight and environment timezone differences, backwards clock handling, real CSRF enforcement, and simultaneous submissions in independent processes.

All Step 1 and existing project tests were retained unchanged.

## 21–22. Commands and results

See `ATTENDANCE_STEP_2_COMMANDS.md` for exact commands, including inspection, formatting, route checks, local MySQL verification and test runs.

- Initial Step 2 API suite: **17 tests, 178 assertions, all passed**, 2.170 seconds, 44 MB.
- Full suite before formatting: **109 tests, 2,214 assertions, zero failures/errors, 2 skipped**, 10.719 seconds, 78 MB.
- Final full suite after formatting: **109 tests, 2,214 assertions, zero failures/errors, 2 skipped**, 10.024 seconds, 78 MB. Therefore 107 tests passed.
- PHPUnit 11.5.56, PHP 8.4.25.
- Skipped existing tests: `test_mysql_mutex_blocks_other_connections_and_releases_after_rollback`, `test_mysql_json_milliseconds_and_nested_advisory_lock`. Both require dedicated MySQL test credentials, which are not available.
- Pint passed for the six relevant PHP files; routes were not reformatted to avoid unrelated formatting churn.
- `git diff --check` passed, with only Git CRLF notices for existing tracked files.

## 23. Safe normal-local-MySQL verification

`tests/verify-attendance-local.php` verifies that the configured MySQL host is loopback and rejects read/write split connections. It opens a **MySQL-enforced read-only transaction**, uses only reads, and rolls back. Verified:

- Step 1 migration is recorded as applied.
- All four attendance/leave tables exist.
- Attendance user/date unique index exists.
- Today's service runs successfully for an existing team member.
- Attendance timezone is Asia/Kolkata.
- Attendance record count is unchanged.

No user IDs, credentials or attendance details were printed. No test users, check-ins, checkouts or other fixtures were written to the normal local database. Write/concurrency tests ran against isolated SQLite databases; the production-specific MySQL lock tests remain unexecuted. No production connection or operation occurred.

## 24. Assumptions and limitations

- Second precision follows the existing schema; completed minutes are rounded down.
- Overnight/yesterday checkout and attendance corrections remain outside Step 2. An unclosed historical record is not silently edited.
- Revoking overtime after check-in on a weekly off disables checkout until authorization is resolved; no automatic correction is attempted.
- Leave approval after check-in is not prevented or reconciled by this step; checkout remains possible on an eligible working day.
- Client fields are ignored rather than rejected; none can alter owner/time/date/flags/location.
- Local MySQL read integration was verified, but MySQL write contention was not exercised without the dedicated test credentials.
- Existing global app/auth/session/CSRF configuration, Step 1 leave rules and schema remain unchanged.

## 25–27. Scope confirmation

No geofence, location enforcement, office coordinates, browser geolocation, frontend card/buttons, admin UI, leave UI, MCP attendance tools, payroll, automatic absence scheduler or Step 3 work was implemented. No unrelated Karya functionality was intentionally changed; existing task/project/MCP/email tests pass. No commit, push or deployment was performed. Stop after Step 2 for review.
