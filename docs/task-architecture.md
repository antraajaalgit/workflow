# Task architecture: final task-write migration

This report supersedes the limitations in the earlier API/frontend reports. Changes remain uncommitted on `api`. No push, commit, merge, deployment, branch switch, normal `.env` edit, SMTP configuration change, schema migration, or operation against the normal database was performed.

## Outcome and audit

**Every identified dashboard task write now uses a targeted server operation. Remaining task-write paths through PUT `/api/state`: NONE.**

GET `/api/state` still returns tasks. PUT `/api/state` still supports legacy non-task data and accepts an old envelope containing tasks, but ignores its task data entirely. Clients/users are upserted without deleting rows or changing primary IDs. Parent deletion is rejected; project/department changes require the focused actions. This prevents foreign-key cascades/nulling from indirectly changing tasks. Message task IDs are checked against database tasks, not submitted tasks. Application writes no longer disable foreign-key checks.

Both app copies, both stores, controllers/services, routes, recurrence, migrations and seeders were audited. In-memory task mutations remain only in Store's authoritative server-response synchronization. Server task writers are TaskService and the existing RecurringTaskGenerator. The existing admin-only POST `/api/state/reset` and maintenance migrations/seeder remain separate server-side operations; none is reachable from PUT. No application reset was executed.

MySQL/MariaDB and browser verification remain pending. Automated SQLite/Node tests are not production verification.

## Files

Created this phase:

- app/Http/Controllers/DashboardTaskController.php
- app/Services/DashboardTaskOperations.php
- tests/Feature/CompoundTaskAssertions.php
- tests/js/compound-transport.test.cjs
- docs/task-architecture.md
- docs/task-architecture-verification.txt

Modified this phase:

- app/Http/Controllers/StateController.php
- app/Services/TaskService.php
- app/Services/StateConcurrency.php
- routes/web.php
- assets/js/app.js and public/assets/js/app.js
- assets/js/store.js and public/assets/js/store.js
- resources/views/app.blade.php
- tests/Feature/TaskApiTest.php
- tests/js/store.test.cjs
- tests/js/task-transport.test.cjs
- tests/js/task-handlers.test.cjs
- docs/task-api.md and docs/task-frontend-migration.md (supersession notices)

Git status includes earlier uncommitted work too, including TaskController, TaskEffects, assignment mail, recurrence changes and phpunit.xml. Those were not all created/modified in this phase.

The view serves public/assets/js. Transport changes are equivalent in both stores. Existing public multiple-assignee controls remain. The source client form was synchronized to the already-served public form, including its existing password field/validation. The served UI/CSS/layout was not redesigned. Script versions are store **17**, app **53**. Already-open pages need reloading.

## Routes and migrated Store.save flows

Existing task routes are retained:

| Method | Route | Use |
| --- | --- | --- |
| GET | /api/tasks/{id} | Retrieval |
| POST | /api/tasks | Normal/team/client brief without voice/recurring task creation |
| PATCH | /api/tasks/{id} | Atomic general or multi-field edit; mark completed |
| PATCH | /api/tasks/{id}/status | Status-only action |
| PATCH | /api/tasks/{id}/progress | Progress-only action |
| PATCH | /api/tasks/{id}/assignees | Assignee-only action |
| DELETE | /api/tasks/{id} | Normal/recurring deletion |

New focused compound actions:

| Method | Route | Former task-writing full-state flow |
| --- | --- | --- |
| POST | /api/projects | Project plus its new task grid |
| PATCH | /api/projects/{id}/tasks | Project details and task-grid additions, edits, deletions, progress, assignment and client linkage |
| DELETE | /api/projects/{id} | Project and task deletion; conversation detachment |
| POST | /api/clients | Client/login creation plus project/task linkage |
| PATCH | /api/clients/{id} | Client/login profile plus project/task client changes |
| DELETE | /api/clients/{id} | Client tasks, projects, messages and client login removal |
| DELETE | /api/team-members/{id} | Member removal and task owner cleanup |
| POST | /api/departments | Department creation |
| PATCH | /api/departments/{id} | Department rename with user/task updates |
| DELETE | /api/departments/{id} | Empty department deletion and task fallback |
| POST | /api/briefs | Voice brief task and message |

GET/PUT `/api/state`, Store.save, and the existing recurring generation route remain.

## Transactions, business rules, and side effects

DashboardTaskController runs authentication, revision checking, one focused DashboardTaskOperations action, and authoritative state capture under StateConcurrency's transaction/lock. All task portions call TaskService. A failed action rolls back parent records, tasks, messages, history and persisted notifications together.

Project grids verify membership of edited/deleted IDs; a task cannot be edited and deleted together. Deletion IDs are explicit, avoiding deletion of unrelated tasks absent from a form. IDs for new records come from the server. Project/client/department forms carry their opening revision; stale forms receive 409 rather than overwriting newer data.

Project deletion removes its tasks through TaskService before deleting the project and detaches surviving messages. Client deletion explicitly deletes tasks belonging to the client, its conversations/projects/login accounts. Historically inconsistent tasks linked to a removed project but belonging to another client survive with project_id cleared. Member removal preserves remaining owners in order, sets owner_id to the first remaining owner, or sets it null when none remain. Department deletion is denied while team members belong to it; otherwise tasks move to the first remaining department by name or General. Renaming updates user/task departments. Delegation-rule behavior remains unchanged.

Voice briefs accept the existing base64 audio format. Session identity and task rules come from TaskService. Task, history, persisted notifications and voice message commit together. Client requests cannot supply privileged task fields. Ordinary chat messages retain their legacy transport because they do not change tasks.

`saveTaskChanges` sends changed general/status/progress/owner fields in **one PATCH** for a compound edit. Single-purpose actions keep specialized endpoints. Mark completed sends `{status: "done", progress: "completed"}` in one PATCH. TaskService applies the field set and derived stage/recurrence/owner values in one transaction.

TaskService public methods remain find/create/update/delete. Its new private deliverAssignments defers mail if an outer transaction exists. DashboardTaskOperations exposes project/deleteProject/client/deleteClient/deleteMember/department/deleteDepartment/brief and focused private helpers.

TaskEffects remains shared for assignment deltas, task history and persisted simulated email/WhatsApp notifications. Newly added team assignees receive assignment email once; unchanged/remaining owners do not. Standalone mail is sent after commit. Nested task operations use outer after-commit callbacks, discarded on rollback. Compound responses report deferred assignmentMailFailures. Client creation reuses existing welcome-mail logic after commit. Mail transport failure does not undo committed data; durable retries/outbox delivery are not implemented or claimed. Recurrence generation retains its existing behavior, including no assignment email for each generated occurrence. No frontend email/business-rule duplication was added.

## Authorization, validation and Store behavior

Existing session/CSRF conventions remain. Ordinary task authorization is unchanged: authenticated reads; admin management; assigned-team editing; restricted client brief creation. Compound project/client/member/department actions require admin role and role_id 1. Voice briefs require a client session. Rules were not weakened.

Laravel validation and TaskService allowlists are reused; there is no request-all database update. Task enums, string IDs, JSON fields and millisecond values are retained. Department names are limited to 40 characters to fit existing task/user department columns.

Ordinary successful task responses replace the matching local task, then GET refreshes state/effects. Compound responses contain authoritative state captured under the transaction lock; Store adopts it without a follow-up write. Unrelated dirty fields survive when unchanged remotely. Conflicting dirty fields invalidate the local revision and show a reload warning. Failed requests do not optimistically mutate tasks/parents. Laravel validation details and 403/404/409/422 errors reach the existing toasts. Network uncertainty never automatically retries a POST. Requests use same-origin credentials and X-CSRF-TOKEN, with the existing one-time session/token refresh for 419.

Store.save omits tasks from the wire entirely. The task-generation guard blocks queued old snapshots after successful task/compound requests. Revision validation still rejects stale/missing revisions with 409. MySQL's database-scoped advisory lock remains held through commit; nested TaskService operations share the outer transaction/lock.

**PUT task-overwrite protection is complete, including parent FK effects.** This does not add per-task If-Match checks between targeted PATCH requests: overlapping targeted edits retain last-write-wins semantics. External direct SQL and independently writable database primaries are outside the existing advisory-lock guarantee.

## Every remaining Store.save usage

Each app copy has five call sites:

| Function | Non-task responsibility |
| --- | --- |
| Notify.fire | Simulated notification log for remaining messaging behavior |
| logActivity | Non-task activity log, currently messaging |
| openTeamMember submit | Create/edit team profile, password and image reference; no task reassignment or task department update |
| sendMessage | Conversation text/voice/attachments; task reference only |
| bindView threshold handler | amberMin/redMin settings |

No migrated task or compound handler invokes these save helpers after success. Task-linked chat is still non-task persistence: it creates a message, not a task mutation.

## Tests and outstanding verification

- PHP: **34 passed, 335 assertions; 2 MySQL-only tests skipped**.
- JavaScript: **112 passed, 0 failed**, both copies.
- Full route/test/git output: task-architecture-verification.txt.
- Syntax checks cover changed PHP controllers/services and both app/store copies.
- git diff --check is clean except informational LF/CRLF warnings.

Tests cover ordinary task operations, multi-field atomic updates, completion, owners, recurrence, assignment mail/idempotency, history/notifications, compound success and rollback, client login/linking, role failures, stale PUT rejection, fresh forged/empty/omitted task snapshots, parent FK preservation, frontend callbacks/transport, draft preservation and queued stale saves. Tests use isolated SQLite and real migrations, excluding the existing credential-seeding data migration. Two old expectations that PUT could edit tasks were deliberately replaced. A grid nested-validation issue found during implementation was fixed. No known failing SQLite/JavaScript tests remain.

MySQL was **not run or verified**: no dedicated KARYA_MYSQL_TEST credentials were available. Reviewed schema: VARCHAR(40) IDs, JSON owner_ids, nullable LONGTEXT attachments JSON, nullable foreign keys, unsigned BIGINT milliseconds, recurrence strings/schedule, transactions and cascading/nulling foreign keys. Application code adds no SQLite-specific SQL.

A database administrator must create a dedicated local database/account with privileges limited to it:

```sql
CREATE DATABASE workflow_task_api_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'workflow_task_api_test'@'127.0.0.1' IDENTIFIED BY '<choose-a-test-only-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
  ON workflow_task_api_test.* TO 'workflow_task_api_test'@'127.0.0.1';
```

Set process-only variables in a local terminal, never normal .env:

```powershell
$env:KARYA_MYSQL_TEST = '1'
$env:KARYA_MYSQL_TEST_USERNAME = 'workflow_task_api_test'
# Privately set KARYA_MYSQL_TEST_PASSWORD to the test-only password.
# Optional KARYA_MYSQL_TEST_PORT; default 3306.
php artisan test
Remove-Item Env:KARYA_MYSQL_TEST, Env:KARYA_MYSQL_TEST_USERNAME, Env:KARYA_MYSQL_TEST_PASSWORD -ErrorAction SilentlyContinue
```

Tests fix the host to 127.0.0.1 and database/account to workflow_task_api_test and never fall back to normal DB_* credentials. Each test migrates randomly prefixed tables and cleans up only its own verified prefix; it never drops the database. Run the entire suite on the intended MySQL/MariaDB version. Both skipped tests must pass: independent-connection lock exclusion/release, and native JSON/millisecond round trips with lock retention across nested task operations. Compound rollback, FK and state-safety tests also run in this mode.

After MySQL passes, browser-test an isolated instance: task forms, grids, client relinking/deletion, member removal, department rename/delete, voice recording, multiple owners, recurrence, validation errors and two-tab stale-state behavior. Verify in browser network tools that task actions send no PUT `/api/state`. Browser, microphone and real SMTP delivery were not exercised in this phase. These are remaining verification steps, not task-snapshot code dependencies.

The verification file includes git status and git diff --stat. Diff statistics omit untracked files; the inventory/status include new additions. All work remains uncommitted.
