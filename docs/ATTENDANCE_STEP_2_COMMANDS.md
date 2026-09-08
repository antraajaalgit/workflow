# Step 2 commands and verification results

Working directory: `C:\Users\DELL\Desktop\downloads\antraajaal\job_project`. Shell: PowerShell. All edits used the patch tool. No migration, commit, push or deployment commands ran. Ignored isolated Laravel cache paths avoid modifying the pre-existing tracked cache.

## Inspection commands

```powershell
git status --short; Get-Content docs/ATTENDANCE_STEP_1_REPORT.md,docs/ATTENDANCE_STEP_1_COMMANDS.md,app/Services/AttendancePolicy.php,app/Services/AttendanceAccess.php,app/Services/AttendanceOverrideService.php,app/Services/LeaveService.php,config/attendance.php,database/migrations/2026_09_08_000000_create_attendance_and_leave_tables.php
```

```powershell
Get-Content tests/Feature/AttendanceFoundationTest.php,tests/attendance-approval-worker.php,app/Services/StateConcurrency.php,app/Http/Controllers/TaskController.php,routes/web.php,bootstrap/app.php,tests/IsolatedDatabase.php,config/database.php,phpunit.xml; Get-Content docs/ATTENDANCE_STEP_1_COMMANDS.md
```

## First API test run

```powershell
$env:APP_PACKAGES_CACHE = 'storage/framework/cache/attendance-test-packages.php'; $env:APP_SERVICES_CACHE = 'storage/framework/cache/attendance-test-services.php'; php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --filter AttendanceApiTest --no-progress
```

Exit 0. `OK (17 tests, 178 assertions)`. 2.170 seconds; 44.00 MB.

## Follow-up inspection

```powershell
Get-Content 'C:/Users/DELL/.codex/attachments/058a5e71-d550-4cf6-9fde-4bbd1add4762/pasted-text.txt' -TotalCount 45; Get-Content app/Services/StateConcurrency.php; rg -n 'function test_mysql|markTestSkipped' tests/Feature/TaskApiTest.php tests/Feature/CompoundTaskAssertions.php; php -m; git diff -- routes/web.php
```

## Normal LOCAL MySQL — read only

```powershell
$env:APP_PACKAGES_CACHE = 'storage/framework/cache/attendance-test-packages.php'; $env:APP_SERVICES_CACHE = 'storage/framework/cache/attendance-test-services.php'; php tests/verify-attendance-local.php
```

Exit 0. Exact result:

```json
{
    "connection": "local_mysql",
    "migration_applied": true,
    "tables_present": {
        "attendance_records": true,
        "attendance_working_day_overrides": true,
        "leave_requests": true,
        "leave_request_days": true
    },
    "attendance_user_date_unique": true,
    "today_service_checked": true,
    "timezone": "Asia\/Kolkata",
    "attendance_count_unchanged": true,
    "dedicated_mysql_test_credentials_available": false
}
```

The script uses `SET TRANSACTION READ ONLY` before beginning its transaction, performs only schema/migration/attendance SELECTs, calls the read-only today service, and rolls back. No normal-local-database fixture or attendance writes occurred.

## Full regression run

```powershell
$env:APP_PACKAGES_CACHE = 'storage/framework/cache/attendance-test-packages.php'; $env:APP_SERVICES_CACHE = 'storage/framework/cache/attendance-test-services.php'; php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-progress --display-skipped
```

Exit 0. 109 tests, 2214 assertions, 2 skips, zero failures/errors. 10.719 seconds; 78.00 MB.

```powershell
Get-Content tests/attendance-approval-worker.php; Get-Content 'C:/Users/DELL/.codex/attachments/058a5e71-d550-4cf6-9fde-4bbd1add4762/pasted-text.txt' | Select-Object -Last 50; git diff --check
```

Exit 0. Diff check passed; Git printed CRLF notices only.

## Formatting and final verification

```powershell
php vendor/laravel/pint/builds/pint app/Services/AttendancePolicy.php app/Services/AttendanceService.php app/Http/Controllers/AttendanceController.php tests/Feature/AttendanceApiTest.php tests/attendance-api-worker.php tests/verify-attendance-local.php
```

Exit 0. Pint formatted test/helper files; other listed files already conformed.

```powershell
$env:APP_PACKAGES_CACHE = 'storage/framework/cache/attendance-test-packages.php'; $env:APP_SERVICES_CACHE = 'storage/framework/cache/attendance-test-services.php'; php artisan route:list --path=api/attendance -v; php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-progress --display-skipped
```

Route output confirmed exactly three attendance routes, all with `web` middleware:

```text
POST      api/attendance/check-in   AttendanceController@checkIn
POST      api/attendance/check-out  AttendanceController@checkOut
GET|HEAD  api/attendance/today      AttendanceController@today
```

Final test output:

```text
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime: PHP 8.4.25
Time: 00:10.024, Memory: 78.00 MB

There were 2 skipped tests:
1) Tests\Feature\TaskApiTest::test_mysql_mutex_blocks_other_connections_and_releases_after_rollback
Requires the guarded dedicated MySQL test configuration.
2) Tests\Feature\TaskApiTest::test_mysql_json_milliseconds_and_nested_advisory_lock
Requires the guarded dedicated MySQL test configuration.

OK, but some tests were skipped!
Tests: 109, Assertions: 2214, Skipped: 2.
```

```powershell
git branch --show-current; git diff --check; git status --short; Get-Content app/Services/AttendanceService.php,app/Http/Controllers/AttendanceController.php
```

Exit 0. Branch `main`; diff check passed. Status shows the pre-existing Step 1 files/cache change plus the new Step 2 files and route modification.

The concurrency test launches two independent workers per operation using command arrays rather than shell interpolation:

```text
C:\php-8.4\php.exe -d extension=pdo_sqlite -d extension=sqlite3 C:\Users\DELL\Desktop\downloads\antraajaal\job_project\tests\attendance-api-worker.php <isolated-temp-db> <isolated-temp-db>.start checkIn
C:\php-8.4\php.exe -d extension=pdo_sqlite -d extension=sqlite3 C:\Users\DELL\Desktop\downloads\antraajaal\job_project\tests\attendance-api-worker.php <isolated-temp-db> <isolated-temp-db>.start checkOut
```

Each pair uses a shared start signal and frozen IST clock. The test asserts one exit 0 success and one exit 9 handled HTTP-409 conflict per pair, one attendance row, preserved timestamps and 540 worked minutes. Only generated temporary SQLite files are removed. The unchanged Step 1 suite also launches its existing leave-approval worker.

Final documentation check:

```powershell
git diff --check; git status --short
```
