# Dashboard task-write migration

> Historical first-phase report. See [task-architecture.md](task-architecture.md) for the completed compound migration, atomic edits, current tests, and MySQL verification instructions. The limitations below describe the earlier phase.

## Scope and files

This continuation changed only:

- assets/js/app.js
- public/assets/js/app.js
- assets/js/store.js
- public/assets/js/store.js
- resources/views/app.blade.php (script versions only: store 16, app 52)
- docs/task-api.md (current migration status)

Added:

- tests/js/task-transport.test.cjs
- tests/js/task-handlers.test.cjs
- docs/task-frontend-migration.md
- docs/task-frontend-verification.txt

Backend controllers, services, authorization, routes, SMTP, .env and database schema were not changed during this continuation. The cumulative Git diff also includes the earlier uncommitted backend work. No commit, push, merge, deployment or branch switch was performed.

The Laravel view serves public/assets/js. The source copies already differed substantially, including older single-assignee forms; there is no build/mirroring configuration in composer.json. This change preserves each copy's existing markup and non-task behavior, applies equivalent task handlers to both, and keeps the added store transport methods identical. It does not overwrite the source with the published app or remove published multiple-assignee support.

## Migrated workflows

| Workflow | Endpoint(s) |
| --- | --- |
| Standalone task creation: openNewTask | POST /api/tasks |
| Team task creation: openTeamTaskForm | POST /api/tasks |
| Client brief without a voice note: submitRequest | POST /api/tasks |
| General fields in task modal: openTask | PATCH /api/tasks/{id}, changed fields only |
| Status change in task modal | PATCH /api/tasks/{id}/status, status only |
| Progress change in task modal | PATCH /api/tasks/{id}/progress, progress only |
| Assignee change in task modal | PATCH /api/tasks/{id}/assignees, owner_ids only |
| Mark completed: completeTask | PATCH status=done, then PATCH progress=completed |
| Task deletion, including recurring task deletion: deleteTask | DELETE /api/tasks/{id} |
| New repeating task: openNewRecurring | POST /api/tasks |
| Repeating-task edits | Changed general fields through PATCH /api/tasks/{id}; progress and assignees through their dedicated routes |

The board uses the same task modal; no separate drag/drop status writer was found in either app copy. Project task-row dropdowns belong to the compound project editor described below.

Migrated callbacks no longer call Store.save(), logActivity(), or Notify.both(). The latter two helpers also issue full-state saves, so simply replacing the direct Store.save call would have been insufficient. Their task-related persisted effects now come from the existing backend. Visual notification toasts remain visual-only, use the existing strings/classes, and do not send mail or persist notifications. Successful creates use server IDs/timestamps. Recurrence schedule calculations also come from the server. Existing form markup, labels, validation messages and normal success toasts are preserved; submit guards prevent duplicate in-flight form submissions without changing their design.

## Store synchronization and concurrent operations

Store.taskPayload maps only supported camelCase task fields to snake_case. It omits JavaScript-generated IDs and creation/stage timestamps. createTask omits null recurrence keys so ordinary team-created tasks do not violate the backend's admin-only recurrence field rule. Updates can still explicitly clear recurrence with null.

Store.taskFromApi converts the authoritative response to the same camelCase task structure used by GET /api/state, including ownerId/ownerIds, dates, recurrence and attachments. Creates upsert that task, updates replace it, and successful deletion removes it and detaches local task-message references. Nothing is optimistically deleted or marked completed before a successful response.

After each write, the store performs only GET /api/state to refresh server history/notifications and the state revision. It never follows task success with PUT /api/state. A baseline comparison preserves unrelated local edits when their corresponding remote field is unchanged. If both changed, the local draft remains but the state revision is invalidated and a warning requests reload. This intentionally avoids silently rebasing a stale global snapshot onto a new revision.

Targeted writes and legacy saves share a request queue. Full-state snapshots queued before a targeted task completes cannot be sent afterward with its new revision: a generation guard rejects them. Later intentional non-task saves use the refreshed task data/revision. Store.load waits for outstanding writes before replacing its baseline. Local task drafts from a pending compound operation must first be saved or reloaded before a targeted task write proceeds.

GET /api/state, PUT /api/state and Store.save remain. The backend state revision/lock mechanism has not been removed or weakened.

## Errors and limits

- 403/404/409/422 messages reach existing toast handling; Laravel validation field messages are flattened rather than discarded.
- Failed task requests preserve the previous local task rather than reporting optimistic success. Missing/conflicting records invalidate the global revision; users may need to reload stale data.
- A successful write followed by failed state refresh keeps the authoritative returned task, reports a refresh warning and blocks unsafe legacy saves. It does not retry a successful POST and create a duplicate.
- Only a definite 419 CSRF failure is retried once, after refreshing the existing session/token. Requests use credentials: same-origin, JSON/Accept headers and the existing CSRF meta token. There is no bearer-token authentication.
- Modal edits spanning general fields, status, progress and assignees involve separate requests, not one atomic transaction. Assignees are changed last so a team member can finish the edit before assigning it away. If a later request fails, earlier confirmed server changes remain locally visible; the error is shown and no fake rollback occurs. Completion can therefore leave done status with an earlier progress value if its second request fails; the task modal can correct progress. Atomic combined updates would require an approved change to the endpoint-use requirements or a coordinated backend operation.
- Each successful targeted request currently refetches the full state. This is safe but adds read traffic; it does not yet replace the legacy loading architecture.
- Network loss during a POST can leave its outcome uncertain; there is no automatic retry/idempotency key. Reload/check before resubmitting an uncertain creation.
- Browser rendering against live application data was not exercised. Existing markup is retained; tests execute the store transport and real task-form callbacks with mocked UI/network dependencies.

## Remaining full-state calls and task mutations

| Remaining flow | Why retained |
| --- | --- |
| openProjectForm task grid (create/edit/remove task rows) | Project fields and multiple task records are saved together, including new projects with IDs not yet persisted. Splitting this would change the project transaction and partial-failure behavior. |
| deleteProject | Deletes the project and its task relationships together. No project endpoint migration was authorized. |
| openClientForm / deleteClient | Client/account/project relationships use the state flow; client deletion also removes tasks and messages. |
| openTeamMember / deleteTeamMember | User data remains on state saves; removal changes task assignee lists. |
| openDepartmentForm / deleteDepartment | Department rename/removal updates dependent task department values. |
| submitRequest with pendingVoice | Creates both a task and its voice-note message. The task API has no message-create operation; a POST followed immediately by Store.save would defeat the requested migration. The existing compound voice-brief flow is retained rather than dropping the message or migrating messages. |
| sendMessage, Notify.fire, logActivity outside migrated task callbacks | Message, notification and general activity features were explicitly outside this migration. |
| Settings threshold save | Non-task state feature, retained. |

These remaining operations still send tasks as part of the legacy state snapshot and remain subject to its revision checks. In particular, task changes inside the project editor are not migrated. This is a migration of standalone task workflows, not a claim that every dashboard task mutation now avoids PUT /api/state.

To migrate the remaining compound operations, add coordinated project/client/user/department/message operations or another explicitly approved transaction design. Do not strip tasks from state replacement while these callers still depend on it.

## Verification

- node --test tests/js/*.test.cjs: 62 passed. Covers both store copies, all task endpoints/payloads, camelCase synchronization, no follow-up PUT, errors, CSRF refresh, pending-save protection, local/remote conflicts, retained non-task saves, partial multi-request failure, team recurrence-key compatibility, and actual task-form callbacks.
- php artisan test: 17 passed, 177 assertions; one MySQL-only test skipped, using isolated SQLite with pdo_sqlite enabled only in the test process. No live database was used.
- php artisan route:list: requested task routes and both state routes remain.
- node --check: both app/store copies parse successfully.
- git diff --check: no whitespace errors; only the repository's existing LF/CRLF conversion notices.

Full command output and cumulative Git status/stat are in task-frontend-verification.txt. MySQL setup remains pending as documented in task-api.md; this frontend change does not claim MySQL or live-browser verification.
