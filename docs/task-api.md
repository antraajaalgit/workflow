# Task API and state compatibility

> Historical implementation report. The completed compound task migration and current state contract are documented in [task-architecture.md](task-architecture.md). Its results supersede the remaining limitations and older test counts below.

Frontend migration update: standalone task writes now use the task APIs. See [task-frontend-migration.md](task-frontend-migration.md) for the exact migrated flows, remaining compound state writes, synchronization behavior and current JavaScript test results.

Implementation remains on `api`, uncommitted. No deployment, branch switch, push, normal database migration, .env edit, or UI/CSS change was performed. MySQL integration verification is still required before calling this production-verified.

## Routes and payloads

Existing web middleware supplies session cookies and CSRF checks. Send `Accept: application/json` and the existing CSRF token on writes. New task payloads/responses use snake_case; `/api/state` keeps its existing camelCase task objects.

| Method | Route | Success |
| --- | --- | --- |
| GET | /api/tasks/{id} | 200, task object |
| POST | /api/tasks | 201, task object and assignmentMailFailures |
| PATCH | /api/tasks/{id} | 200, task object and assignmentMailFailures |
| PATCH | /api/tasks/{id}/status | 200, task object and assignmentMailFailures |
| PATCH | /api/tasks/{id}/progress | 200, task object and assignmentMailFailures |
| PATCH | /api/tasks/{id}/assignees | 200, task object and assignmentMailFailures |
| DELETE | /api/tasks/{id} | 200, `{ "deleted": true }` |

GET /api/state and PUT /api/state remain registered to StateController. Missing tasks return 404; invalid input 422; denied actions 403; missing/stale sessions 401. A stale or missing state revision returns 409; lock timeout returns 503.

## Validation and task behavior

- Only approved fields are persisted: title, description, client_id, project_id, department, owner_id, owner_ids, status, priority, progress, due_date_ms, recurring, next_recurrence_at_ms, attachments. Creation additionally accepts id, created_at_ms, stage_at_ms. Other fields are ignored. PATCH cannot change task ID or creation timestamp.
- Title is required on creation, nonempty string, maximum 255. Description is a nullable string. Department is a nonempty string up to the actual task column's 40-character limit, default General.
- IDs are strings, never assumed integer. Optional creation IDs are unique, up to 40 characters, and limited to letters/digits/underscore/hyphen to prevent injection into existing dashboard attributes. Omitted IDs follow `t_` plus seven lowercase alphanumeric characters. Existing UUID task IDs remain supported.
- Nullable client/project IDs must exist. A linked project's non-null client takes precedence as in the published dashboard. Standalone tasks and projects without clients remain valid.
- Status: new, todo, in_progress, review, done, blocked. Changed status resets stage_at_ms. Setting the same status does not reset it or duplicate completion notifications.
- Progress strings: just_started, 25, 50, 75, completed. Numeric JSON percentages and other values are rejected. Status and progress remain independent, matching the edit form; the dashboard completion action sets both.
- Priority: low, med, high; defaults to med. Staff task status defaults to todo and progress to just_started. Creation/stage timestamps default to now.
- Supplied owner IDs must exist with exact casing and be team members or admins with a nonempty email. Lists must be distinct string IDs. Explicit owner_ids takes precedence and owner_id is synchronized to its first element. A single owner_id replaces the list. Empty list/null supports unassignment. Legacy owner_id-only records are read and normalized on update.
- Recurrence: null, daily, alternate_days, weekly, monthly. Missing schedules are initialized, changed frequencies recalculated from now, and clearing recurrence clears the schedule. Schedule arithmetic is shared with RecurringTaskGenerator, including month-end handling. Existing occurrence generation preserves assignees/attachments, resets status/progress, advances the template schedule and creates no assignment emails, matching its existing behavior.
- Optional due/recurrence timestamps accept null; otherwise milliseconds are nonnegative integers up to JavaScript's safe-integer maximum.
- Attachments are lists of objects with only id, name, type, size, data. IDs use safe characters; name/type strings are limited to 255, size is a nonnegative integer. Data must be a base64 data URI or local `/api/chat-attachment?file=...` URL; arbitrary script/attribute payloads are rejected. No file upload is performed by these endpoints.
- Client briefs use the first alphabetically ordered department or General, new status, their authenticated client association, and a one-day default due date, following the published request form.
- Deletion detaches messages and retains the conversation. Database writes and API history/notification records commit together.

## Shared business logic and effects

TaskService public methods: find, create, update, delete. Private helpers: freshActor, authorize, validated, relationships, encode. The repository has no Eloquent models; DB::table remains the persistence convention.

TaskEffects owns assignment-delta detection and delivery. TaskAssignment is a shared plain-text Mailable extracted from StateController's existing task email. Its sender, chat_smtp mailer, subject, task/project/status/due-date/description content remain consistent with the previous implementation. Both state saves and task API saves call this implementation.

| Event | New API behavior | Existing dashboard path |
| --- | --- | --- |
| Creation with team assignees | Email each assigned team member with an email; write creation history | Uses the same mail service; existing frontend history stays intact |
| Assignment/reassignment | Email only newly added team assignees; write reassignment history | Now also uses the same delta-based emails, including team-initiated assignments |
| Title/description/general update | Write update history; no invented client email | Existing state/frontend behavior retained |
| Status change | Refresh stage time, record move; completion adds client notification entries | Existing frontend move/history and notification entries retained |
| Progress change | Record progress change; no invented email | Existing progress persistence retained |
| Client brief | Record brief and the existing agency/client simulated notification entries | Existing frontend brief/history/notification entries retained |
| Delete | Keep messages with null task_id, record deletion | Existing dashboard deletion remains intact |
| Recurring generation | Existing generator behavior, now serialized with state writes | Same generator and recurrence arithmetic |

Assignment messages are sent after the task/state transaction commits and releases its lock. Sending failures return assignmentMailFailures and are logged; the successful write is retained. Unchanged assignees do not receive duplicate messages on subsequent saves. Admins remain assignable but do not receive these team-only emails, matching the original recipient rule.

No client completion/brief SMTP or real WhatsApp delivery existed in the dashboard's Notify helper. Its entries were simulated rows in notifications; the new API records equivalent rows without pretending to deliver messages. Existing frontend-generated history/notification arrays are not duplicated on state saves. API history adds visibility for updates/reassignment/deletion where the frontend did not consistently log an event.

Email delivery remains best-effort: there is no durable outbox/retry worker. A process crash between commit and send can lose an email; transport failures require operational follow-up. An outbox would be a separate reliability improvement and is not claimed as implemented.

## Authorization

The existing nagare_user_id session resolves against users. New API writes re-read the actor after acquiring the lock so account deletion/role changes while waiting cannot use stale permissions.

- Admins can create/manage tasks.
- Team members can create tasks, and manage/delete tasks where their ID appears anywhere in the assignee list. They can reassign as the published modal permits. Client/project/department/recurrence changes on an existing task are admin-only; recurrence creation is admin-only.
- Clients can create only their own unassigned briefs using id/title/description/priority/due date/attachments/optional matching client_id. They cannot patch, assign or delete tasks.
- GET retains the state API's existing authenticated-wide task visibility; per-client read isolation has not been introduced.
- StateController's existing task permissions are broader than the frontend's. They were not weakened or redesigned in this change. A separate authorization hardening effort is needed if the legacy state endpoint is exposed to untrusted clients.

## Concurrency protection and its boundary

Inspection confirmed that replaceState deletes and recreates every state table. The solution therefore protects the entire snapshot, including tasks, users, projects, messages, activity and notifications, rather than comparing second-resolution updated_at timestamps.

1. GET /api/state returns an opaque HMAC `_revision` computed over the current stored snapshot, under a shared lock/transaction.
2. PUT /api/state must echo that revision. Missing, malformed or stale revisions receive 409 before any destructive replacement or mail effect.
3. On MySQL/MariaDB, StateConcurrency uses a database-scoped GET_LOCK on the write connection, held across revision validation and transaction commit. State reads/writes/reset, targeted task writes and recurring generation participate. Failure to acquire it within ten seconds returns 503, never proceeds unlocked. A finally block releases it on exceptions.
4. A successful PUT returns the new revision. Store.save() remains in both asset copies, serializes overlapping saves, captures each requested snapshot and updates only its revision after a successful predecessor. A conflict/network uncertainty invalidates the local token and blocks queued stale writes until reload; conflicts are never automatically retried with a fresh token.
5. The served store script cache version changes from 14 to 15. Already open or cached old clients must reload; they fail closed instead of overwriting state.

**Supported application writes are protected against stale state replacement, including API-created tasks disappearing and API-deleted tasks being resurrected.** This is not a universal concurrency guarantee: direct SQL, external tools or future writers that bypass StateConcurrency can race inside the critical section. GET_LOCK assumes one MySQL/MariaDB primary; independently writable cluster nodes require a different shared lock/version strategy. MySQL execution of this mechanism is still unverified locally pending dedicated test credentials.

SQLite tests verify revisions and transaction behavior, but its in-memory connection does not prove MySQL multi-connection locking. Targeted PATCH requests themselves remain last-write-wins for overlapping fields; no per-task If-Match API is introduced.

The legacy snapshot architecture still has coarse conflicts and whole-state replacement costs. Users may need to reload/reapply changes. Existing fire-and-forget frontend calls can show optimistic UI before a rejected save; the server protects stored data but there is no new conflict-resolution UI. The normal browser UI has not been manually exercised; transport and task form callbacks are covered by Node tests.

To finish retiring task snapshot replacement, migrate the remaining compound workflows: project task grids, project/client deletion cascades, member/department changes affecting tasks, and briefs with voice-note messages. Standalone create/edit/status/progress/assignee/delete and recurring task forms have now migrated. Related entity endpoints or a coordinated server operation are needed to replace the remaining compound transactions safely. Stop replacing tasks through PUT /api/state only after those dependencies are removed. Per-task If-Match versions remain a separate API-to-API concurrency improvement.

## Schema review

No migrations were added. Existing schema uses VARCHAR(40) primary/foreign IDs, JSON owner_ids, nullable LONGTEXT JSON attachments, nullable client/project/owner/due/recurrence fields, unsigned BIGINT millisecond timestamps, VARCHAR progress/recurrence and conventional created_at/updated_at timestamps. Arrays are JSON-encoded on persistence and decoded on reads. Project-client mapping and FK-compatible task deletion are covered by integration tests. MySQL foreign-key toggling in the legacy clear method is restored in finally; SQLite uses deferred constraints so a successful legacy PUT is also exercised.

## Test execution

The original baseline lacked PHPUnit configuration. The initial implementation added it; the continuation expands the tests to meaningful state, side effects and transport behavior. Tests never use normal DB_* credentials. Test bootstrap overrides cached connection settings before queries and skips the existing migration that seeds real account credentials.

SQLite requires pdo_sqlite. On this machine it is installed but disabled in the normal PHP configuration. Enable it only in the process:

```powershell
$taskIniDir = Join-Path $env:TEMP 'workflow-task-api-php'
New-Item -ItemType Directory -Force $taskIniDir | Out-Null
Set-Content (Join-Path $taskIniDir 'sqlite.ini') 'extension=pdo_sqlite'
$previousScan = $env:PHP_INI_SCAN_DIR
try {
    $env:PHP_INI_SCAN_DIR = $taskIniDir
    php artisan test
} finally {
    $env:PHP_INI_SCAN_DIR = $previousScan
}
node --test tests/js/store.test.cjs
```

Result: 17 Laravel tests passed, 177 assertions; one MySQL-only mutex test skipped. Six Node tests passed. Mail::fake verifies assignment recipients/counts; a transport failure test verifies the saved task/history survives with a reported failure. State tests include successful PUT, subsequent fresh saves, API create/update/delete and recurrence conflicts. See task-api-verification.txt for final command output.

## Dedicated MySQL/MariaDB setup required

A local MySQL service was found, but no dedicated test DB credentials/configuration were available. No attempt was made to use normal credentials or connect to/migrate the development database. MySQL tests have NOT run successfully; schema review is not a substitute for execution.

Have a local database administrator create a separate database/account (choose a real password locally; the placeholder below is not a credential):

```sql
CREATE DATABASE workflow_task_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'workflow_task_api_test'@'127.0.0.1' IDENTIFIED BY '<choose-a-test-only-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON workflow_task_api_test.* TO 'workflow_task_api_test'@'127.0.0.1';
```

Use an account restricted to this test database. In your local terminal set process-only KARYA_MYSQL_TEST=1, KARYA_MYSQL_TEST_USERNAME=workflow_task_api_test and KARYA_MYSQL_TEST_PASSWORD to that password; optionally set KARYA_MYSQL_TEST_PORT (defaults to 3306), then run `php artisan test`. Do not put the password in normal .env or commit it. Clear these variables afterwards to return to SQLite mode.

The guarded test helper fixes host to 127.0.0.1 and database/account name to workflow_task_api_test, supplies explicit connection settings, and refuses missing test credentials. Each test uses a random table prefix, runs the real migrations except the credential-seeding data migration, and removes only its own prefixed tables after validating database and prefix. It never drops the database or truncates normal tables. The extra MySQL test checks lock exclusion from a second connection and lock release after rollback.
