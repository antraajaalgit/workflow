# Completed-task retention

## Safety and scope

Implemented on `api`, without committing, pushing, switching branches, deploying, changing `.env`/SMTP/UI/CSS, or running cleanup/migrations against the normal database. Purge commands were executed only inside automated tests using isolated SQLite and a fake public storage disk. MySQL integration remains unverified pending the existing dedicated test database setup.

## Exact file inventory

Created:

- `app/Console/Commands/PurgeCompletedTasks.php`
- `app/Services/CompletedTaskPurger.php`
- `app/Services/TaskAttachmentCleanup.php`
- `database/migrations/2026_09_04_000000_create_task_attachment_deletions_table.php`
- `tests/Feature/CompletedTaskCleanupAssertions.php`
- `docs/completed-task-cleanup.md`
- `docs/completed-task-cleanup-verification.txt`

Modified:

- `app/Services/TaskService.php`
- `routes/console.php`
- `tests/Feature/TaskApiTest.php`

TaskController, DashboardTaskOperations, StateController, TaskEffects, RecurringTaskGenerator, routes/web.php, attachment upload/download logic, schema foreign keys and frontend code were inspected. No frontend changes were necessary.

## Completion and eligibility

Completion time uses `stage_at_ms`, never `created_at_ms`.

The purge requires all of:

```text
status = done
progress = completed
stage_at_ms <= server_now_ms - 2,592,000,000
recurring IS NULL or empty
next_recurrence_at_ms IS NULL
```

The extra progress check follows the business requirement's two completion markers: a done task whose progress is still partial is retained. Recurring template/schedule records are excluded because they are required to generate future occurrences.

Thirty days means exactly 30 × 24 hours, calculated by millisecond subtraction, independent of calendar months/DST. Exactly 30 days is eligible; even one millisecond under is not. The daily job deletes eligible tasks on the next run, so actual retention can be almost 31 days.

Existing status transitions already set stage_at_ms from server time. Reopening excludes the task immediately; entering done again resets the timer. If progress becomes completed later while status is already done, the timestamp also resets to give the fully completed task 30 days. Re-saving unchanged completion fields does not reset it. Newly created done tasks cannot backdate completion using a supplied stage_at_ms. Historical records use their existing stage_at_ms; no speculative timestamp backfill was performed.

## Commands and schedule

```sh
php artisan tasks:purge-completed --dry-run
php artisan tasks:purge-completed
```

Dry run counts eligible tasks and changes no tasks, relationships, files or retry entries. It does not drain pending file deletions. Normal runs retry existing file-deletion intents, then process eligible tasks in batches of 100 string IDs. Each task is rechecked under the existing transaction/advisory lock before deletion, protecting tasks reopened after candidate selection. No integer-ID assumption is made.

Laravel 12 scheduling remains in routes/console.php:

```php
Schedule::command('tasks:purge-completed')
    ->dailyAt('03:00')
    ->withoutOverlapping();
```

This uses Laravel's configured scheduling timezone. The recurring generation job is unchanged and still runs every minute with withoutOverlapping. No long-running queue worker is required.

Observed schedule listing:

```text
* * * * *  php artisan nagare:generate-recurring-tasks
0 3 * * *  php artisan tasks:purge-completed
```

## Hostinger configuration to perform later

In hPanel, use a **Custom** cron job scheduled **every minute** (`* * * * *`). The command field should contain:

```sh
/usr/bin/php /ABSOLUTE/PATH/TO/LARAVEL/artisan schedule:run
```

Replace `/ABSOLUTE/PATH/TO/LARAVEL` with the hosting directory that actually contains artisan. For example, if artisan is in the website root:

```sh
/usr/bin/php /home/YOUR_HOSTINGER_USER/domains/YOUR_DOMAIN/public_html/artisan schedule:run
```

The account, domain and deployed Laravel directory are not present in this repository, so these are explicit placeholders, not a guessed executable production path. Confirm that the selected CLI PHP binary is compatible with this project's PHP >=8.3 requirement. The cron runs the scheduler every minute; Laravel runs cleanup only at 03:00 and preserves recurring generation every minute. No redirect/shell chaining is needed in the hPanel command field. No cron was configured here. Hostinger documents Custom commands and UTC cron scheduling in its [cron setup guide](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/); its [PHP command example](https://www.hostinger.com/support/5646919-how-to-set-up-a-cron-job-with-special-characters-at-hostinger/) uses /usr/bin/php.

Before enabling hosting cleanup, review/apply the new migration through your normal deployment process and run the dry run there. The migration adds a durable file-deletion queue and a task eligibility index; it has not been applied to your normal database during this task. First run the complete tests against the dedicated MySQL/MariaDB test database described in task-architecture.md.

## Shared deletion behavior

TaskService now has one private deleteRecords implementation used by authorized manual deletion and the maintenance-only purgeCompleted entry point. HTTP authorization is unchanged, and no purge HTTP route was added. Purging does not require or impersonate an admin account; its audit actor is Scheduled cleanup. Manual deletion continues to permit the same authorized actions as before, independent of automatic retention.

Inside one transaction per task:

1. Queue candidate task-owned physical attachment paths durably.
2. Set surviving messages' task_id to null.
3. Permanently delete the task row, including assignment JSON, inline attachment JSON/base64 data, timestamps and occurrence metadata.
4. Record deletion using the existing TaskEffects activity implementation.

Existing conversations/messages (including voice and attachments), client/project/user records, global activity history, and persisted simulated notifications survive. Activities/notifications are plaintext global logs without a task_id foreign key; matching/deleting them by task title would risk unrelated data, so they are intentionally retained. No assignment email is generated by deletion. Task owners are columns/JSON on the task, not a separate join table.

Recurring templates remain because RecurringTaskGenerator reads their recurring and next_recurrence_at_ms fields. Generated occurrences have both fields null, so completed old occurrences can expire without stopping future generation. Shared attachment references from a surviving template also prevent deleting its files. Invalid/legacy nonempty recurrence markers or schedule values are conservatively retained for review.

## Attachment safety and failure behavior

Task attachments can be inline base64 data in LONGTEXT JSON or links to files on Laravel's local public disk. Inline data disappears with the task row. Physical deletion only considers this application's UUID-named files inside the public disk's `chat-attachments` directory. Current `/api/chat-attachment?file=...` links and legacy `/api/chat-attachments/...` and `/storage/chat-attachments/...` links are recognized; configured same-origin absolute URLs are supported.

URL decoding is controlled, names must match the application's generated UUID format, and filesystem realpath/symlink checks reject traversal or targets outside managed storage. No client-supplied arbitrary path is passed to filesystem deletion. Unknown/external URLs, other directories and unrelated files are never swept or deleted.

Before unlinking, the cleanup checks remaining tasks/messages, user image references, project descriptions, activities and notification text in bounded chunks. It handles URL encoding and JSON encoding, and conservatively preserves any filename match. Thus it may retain a file for a plain-text reference, rather than risk breaking a reference. It never deletes an unrelated chat attachment simply because a message's task expired.

Database and filesystem changes cannot share one transaction. The `task_attachment_deletions` table closes that gap: intent commits with task deletion; physical unlink runs only after the outermost transaction commits. Compound-operation rollback discards both the intent and its callback. Physical processing uses the existing StateConcurrency lock and rechecks references immediately before deleting.

If unlink fails, the task remains permanently deleted, but its file path remains in the durable queue for the next daily/manual run. If unlink succeeds and acknowledging the queue entry fails or the process crashes, retry safely treats the missing file as already removed. A shared-file intent is cleared without deleting the file; a later deletion of its final owning task can enqueue it again. Only task-owned candidates are considered, not a global orphan-file sweep.

One failed database task is logged and does not prevent the other selected tasks from being attempted. Its transaction rolls back completely. File failures are isolated per queued path. Logs identify task/cleanup IDs, error class/code and aggregate results, without recording file contents, credentials or full SQL exception payloads. File safety error codes: 100 invalid queued path; 101 unsafe/missing root or symlink; 102 outside managed directory; 103 storage deletion failure. Normal command exit is nonzero if tasks failed or file deletions remain pending; the summary still reports the count of committed task deletions.

## Verification and remaining risks

- `php artisan test`: **50 passed, 459 assertions; 2 MySQL-only tests skipped**.
- `node --test tests/js/*.test.cjs`: **112 passed, 0 failed**.
- `php artisan schedule:list`: both expected jobs present; no jobs were executed by this listing.
- Relevant PHP syntax checks: all passed.
- git diff --check: no whitespace errors; informational LF/CRLF notices only.

Tests freeze server time, use an isolated in-memory database, and use fake public storage. Coverage includes 1/29/just-under-30/exactly-30/60 days, incomplete and reopened tasks, re-completion, backdate protection, unchanged completion saves, messages/history/notifications, exclusive/shared/unsafe files, after-commit ordering, outer rollback, storage failure/retry, unlink-before-ack failure, recurring generation, more than two string-ID batches, dry run including pending files, locked eligibility rechecks, scheduler registration, manual deletion reuse, existing API tests, and protection against state-based task resurrection.

MySQL/MariaDB runtime verification, hosting paths/PHP binary and live hosting filesystem permissions remain to be verified. The new migration must be applied before enabling cleanup. Large attachment datasets can make reference checks time-consuming; task selection and file checks are bounded in memory, and committed deletions/retry intents survive an interrupted hosting process. Shared/referenced files and recurring templates intentionally retain storage. Unknown legacy attachment formats are intentionally not deleted automatically. Existing historical completion timestamps should be reviewed using dry run before activation. Database deletion frees reusable database space; it does not itself request tablespace compaction or guarantee an immediate reduction in the database file's OS size. No database optimization/compaction was performed.

Full test/schedule/lint output plus git status and git diff --stat are saved in completed-task-cleanup-verification.txt. Git diff --stat omits untracked additions; the inventory/status include them. All changes remain uncommitted.
