<?php

namespace Tests\Feature;

use App\Services\CompletedTaskPurger;
use App\Services\RecurringTaskGenerator;
use App\Services\StateConcurrency;
use App\Services\TaskAttachmentCleanup;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CompletedTaskCleanupAssertions
{
    private function cleanupClock(): int
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00.000', 'UTC'));
        Storage::fake('public');
        return now()->getTimestampMs();
    }

    private function expiredTask(string $id, int $stage, array $extra = []): string
    {
        $id = $this->task(['id' => $id, 'owner_ids' => [], 'status' => 'done', 'progress' => 'completed'] + $extra);
        // Historic fixtures only. API creation must never accept a backdated completion time.
        DB::table('tasks')->where('id', $id)->update(['stage_at_ms' => $stage] + $extra);
        return $id;
    }

    private function fileAttachment(string $name): array
    {
        return ['id' => 'attachment', 'name' => 'document.txt', 'type' => 'text/plain', 'size' => 3,
            'data' => '/api/chat-attachment?file='.rawurlencode($name)];
    }

    public function test_cleanup_exact_elapsed_30_day_boundaries_and_mixed_tasks(): void
    {
        $now = $this->cleanupClock(); $day = 86400000;
        foreach (['one_day' => $day, 'twenty_nine' => 29*$day, 'one_minute_under' => 30*$day-60000,
            'one_ms_under' => 30*$day-1, 'exactly_thirty' => 30*$day, 'sixty' => 60*$day] as $id => $age) $this->expiredTask($id, $now-$age);
        $active = $this->expiredTask('active', $now-60*$day, ['status' => 'in_progress']);
        $partial = $this->expiredTask('partial', $now-60*$day, ['progress' => '75']);
        $this->artisan('tasks:purge-completed')->expectsOutput('Completed task cleanup finished: 2 tasks deleted.')->assertSuccessful();
        foreach (['one_day','twenty_nine','one_minute_under','one_ms_under',$active,$partial] as $id) $this->assertDatabaseHas('tasks', ['id' => $id]);
        foreach (['exactly_thirty','sixty'] as $id) $this->assertDatabaseMissing('tasks', ['id' => $id]);
    }

    public function test_cleanup_reopened_and_recompleted_task_restart_server_timer(): void
    {
        $now = $this->cleanupClock(); $id = $this->expiredTask('reopen', $now-60*86400000);
        foreach (['todo', 'in_progress', 'review'] as $status) {
            $this->patchJson('/api/tasks/'.$id, ['status' => $status])->assertOk()->assertJsonPath('stage_at_ms', $now);
            $this->assertSame(0, app(CompletedTaskPurger::class)->run()['deleted']);
        }
        $this->patchJson('/api/tasks/'.$id, ['status' => 'done', 'progress' => 'completed', 'stage_at_ms' => 1])->assertOk()->assertJsonPath('stage_at_ms', $now);
        $this->travelTo(Carbon::createFromTimestampMs($now+30*86400000-1));
        $this->assertSame(0, app(CompletedTaskPurger::class)->run()['deleted']);
        $this->travelTo(Carbon::createFromTimestampMs($now+30*86400000));
        $this->assertSame(1, app(CompletedTaskPurger::class)->run()['deleted']);
    }

    public function test_cleanup_new_completed_tasks_and_late_progress_receive_full_retention(): void
    {
        $now = $this->cleanupClock();
        $id = $this->task(['status' => 'done', 'progress' => 'completed', 'stage_at_ms' => 1]);
        $this->assertDatabaseHas('tasks', ['id' => $id, 'stage_at_ms' => $now]);
        $late = $this->expiredTask('late_progress', $now-60*86400000, ['progress' => '75']);
        $this->patchJson('/api/tasks/'.$late.'/progress', ['progress' => 'completed'])->assertOk()->assertJsonPath('stage_at_ms', $now);
        $this->assertSame(0, app(CompletedTaskPurger::class)->run()['deleted']);
        $this->travel(1)->days();
        $this->patchJson('/api/tasks/'.$late, ['title' => 'Still completed', 'status' => 'done', 'progress' => 'completed'])->assertOk()->assertJsonPath('stage_at_ms', $now);
    }

    public function test_cleanup_preserves_detached_conversations_history_and_notifications(): void
    {
        $now = $this->cleanupClock(); $id = $this->expiredTask('conversation', $now-30*86400000);
        DB::table('messages')->insert(['id' => 'survive', 'task_id' => $id, 'client_id' => 'c_test', 'from_id' => 'client',
            'from_role' => 'client', 'text' => 'Keep the conversation', 'voice' => 'data:audio/webm;base64,YWJj', 'sent_at_ms' => $now]);
        DB::table('notifications')->insert(['id' => 'keep_notification', 'channel' => 'email', 'recipient' => 'client@example.test', 'text' => 'Historic notice', 'sent_at_ms' => $now]);
        $history = DB::table('activities')->pluck('id')->all();
        $this->assertSame(1, app(CompletedTaskPurger::class)->run()['deleted']);
        $this->assertDatabaseHas('messages', ['id' => 'survive', 'task_id' => null, 'text' => 'Keep the conversation']);
        $this->assertDatabaseHas('notifications', ['id' => 'keep_notification']);
        foreach ($history as $event) $this->assertDatabaseHas('activities', ['id' => $event]);
        $this->assertDatabaseHas('activities', ['text' => 'Scheduled cleanup deleted "Test task"']);
        $this->assertDatabaseHas('clients', ['id' => 'c_test']);
    }

    public function test_cleanup_deletes_exclusive_files_and_inline_metadata_only_after_commit(): void
    {
        $now = $this->cleanupClock(); $name = '11111111-1111-1111-1111-111111111111.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->expiredTask('files', $now-30*86400000);
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode([$this->fileAttachment($name), ['data' => 'data:text/plain;base64,YWJj']])]);
        app(StateConcurrency::class)->run(function () use ($id, $now, $name) {
            $this->assertTrue(app(TaskService::class)->purgeCompleted($id, $now-30*86400000));
            Storage::disk('public')->assertExists('chat-attachments/'.$name);
            $this->assertDatabaseCount('task_attachment_deletions', 1);
        });
        Storage::disk('public')->assertMissing('chat-attachments/'.$name);
        $this->assertDatabaseCount('task_attachment_deletions', 0);
        $this->assertDatabaseMissing('tasks', ['id' => $id]);
    }

    public function test_cleanup_preserves_files_shared_by_task_message_or_other_entity(): void
    {
        $now = $this->cleanupClock();
        $names = ['22222222-2222-2222-2222-222222222222.txt', '33333333-3333-3333-3333-333333333333.txt', '44444444-4444-4444-4444-444444444444.txt'];
        foreach ($names as $name) Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->expiredTask('shared', $now-30*86400000);
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode(array_map(fn ($name) => $this->fileAttachment($name), $names))]);
        $this->task(['attachments' => [$this->fileAttachment($names[0])]]);
        DB::table('messages')->insert(['id' => 'message_shared', 'task_id' => $id, 'from_id' => 'client', 'from_role' => 'client', 'sent_at_ms' => $now,
            'attachments' => json_encode([['data' => '/api/chat-attachments/'.$names[1]]])]);
        DB::table('users')->where('id', 'one')->update(['image' => '/storage/chat-attachments/'.$names[2]]);
        app(CompletedTaskPurger::class)->run();
        foreach ($names as $name) Storage::disk('public')->assertExists('chat-attachments/'.$name);
        $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_path_traversal_and_unrelated_files_are_never_deleted(): void
    {
        $now = $this->cleanupClock(); Storage::disk('public')->put('team-member-images/keep.txt', 'safe');
        Storage::disk('public')->put('chat-attachments/unrelated.txt', 'safe');
        $id = $this->expiredTask('unsafe', $now-30*86400000);
        $values = ['/api/chat-attachment?file=..%2Fteam-member-images%2Fkeep.txt', '/storage/../team-member-images/keep.txt',
            'https://evil.example/storage/chat-attachments/11111111-1111-1111-1111-111111111111.txt', '/api/chat-attachment?file[]=bad', '/storage/chat-attachments/unrelated.txt'];
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode(array_map(fn ($value) => ['data' => $value], $values))]);
        app(CompletedTaskPurger::class)->run();
        Storage::disk('public')->assertExists('team-member-images/keep.txt'); Storage::disk('public')->assertExists('chat-attachments/unrelated.txt');
        $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_filesystem_failure_remains_durable_and_retries_without_worker(): void
    {
        $now = $this->cleanupClock(); $name = '55555555-5555-5555-5555-555555555555.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->expiredTask('retry', $now-30*86400000);
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode([$this->fileAttachment($name)])]);
        $this->app->instance(TaskAttachmentCleanup::class, new class extends TaskAttachmentCleanup {
            protected function deleteFile(string $path): void { throw new \RuntimeException('Simulated disk failure'); }
        });
        $this->artisan('tasks:purge-completed')->assertFailed();
        $this->assertDatabaseMissing('tasks', ['id' => $id]); $this->assertDatabaseCount('task_attachment_deletions', 1);
        Storage::disk('public')->assertExists('chat-attachments/'.$name);
        $this->app->forgetInstance(TaskAttachmentCleanup::class);
        $this->artisan('tasks:purge-completed')->assertSuccessful();
        Storage::disk('public')->assertMissing('chat-attachments/'.$name); $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_database_failure_preserves_task_file_and_relationships_and_continues(): void
    {
        $now = $this->cleanupClock(); $name = '66666666-6666-6666-6666-666666666666.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->expiredTask('a_failure', $now-30*86400000);
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode([$this->fileAttachment($name)])]);
        $other = $this->expiredTask('b_success', $now-30*86400000);
        $this->failOnSql('insert into', 'activities');
        $result = app(CompletedTaskPurger::class)->run();
        $this->assertSame(1, $result['deleted']); $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('tasks', ['id' => $id]); $this->assertDatabaseMissing('tasks', ['id' => $other]);
        Storage::disk('public')->assertExists('chat-attachments/'.$name); $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_preserves_recurring_template_and_future_generation(): void
    {
        $now = $this->cleanupClock();
        $template = $this->expiredTask('template', $now-60*86400000, ['recurring' => 'daily', 'next_recurrence_at_ms' => $now]);
        $occurrence = $this->expiredTask('old_occurrence', $now-30*86400000);
        app(CompletedTaskPurger::class)->run();
        $this->assertDatabaseHas('tasks', ['id' => $template]); $this->assertDatabaseMissing('tasks', ['id' => $occurrence]);
        $this->assertSame(1, app(RecurringTaskGenerator::class)->generateDueTasks(CarbonImmutable::createFromTimestampMs($now)));
        $this->assertDatabaseHas('tasks', ['status' => 'todo', 'recurring' => null, 'created_at_ms' => $now]);
        $this->assertSame(0, app(CompletedTaskPurger::class)->run()['deleted']);
    }

    public function test_cleanup_batches_string_ids_and_dry_run_changes_nothing(): void
    {
        $now = $this->cleanupClock(); $id = $this->expiredTask('sample', $now-30*86400000);
        $sample = (array) DB::table('tasks')->where('id', $id)->first();
        for ($i = 0; $i < 205; $i++) DB::table('tasks')->insert(array_replace($sample, ['id' => sprintf('string_%03d', $i)]));
        $this->task(['id' => 'z_keep']);
        $before = app(StateConcurrency::class)->revision();
        $this->artisan('tasks:purge-completed', ['--dry-run' => true])->expectsOutput('Dry run: 206 completed tasks eligible for permanent deletion. No data or files changed.')->assertSuccessful();
        $this->assertSame($before, app(StateConcurrency::class)->revision());
        $this->assertSame(206, app(CompletedTaskPurger::class)->run()['deleted']);
        $this->assertDatabaseCount('tasks', 1); $this->assertDatabaseHas('tasks', ['id' => 'z_keep']);
    }

    public function test_cleanup_rechecks_eligibility_under_lock_and_cannot_resurrect_through_state(): void
    {
        $now = $this->cleanupClock(); $id = $this->expiredTask('recheck', $now-30*86400000);
        $cutoff = $now-30*86400000;
        $this->assertSame(1, app(TaskService::class)->completedBefore($cutoff)->count());
        $this->patchJson('/api/tasks/'.$id, ['status' => 'todo'])->assertOk();
        $this->assertFalse(app(TaskService::class)->purgeCompleted($id, $cutoff));
        DB::table('tasks')->where('id', $id)->update(['status' => 'done', 'stage_at_ms' => $cutoff]);
        $state = $this->getJson('/api/state')->json();
        app(CompletedTaskPurger::class)->run();
        $this->putJson('/api/state', $state)->assertConflict();
        $state['_revision'] = app(StateConcurrency::class)->revision();
        $this->putJson('/api/state', $state)->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $id]);
    }

    public function test_cleanup_schedule_preserves_recurring_schedule_and_manual_deletion_uses_same_files_cleanup(): void
    {
        $this->cleanupClock();
        $events = collect(app(Schedule::class)->events());
        $purge = $events->first(fn ($event) => str_contains($event->command, 'tasks:purge-completed'));
        $recurring = $events->first(fn ($event) => str_contains($event->command, 'nagare:generate-recurring-tasks'));
        $this->assertNotNull($purge); $this->assertSame('0 3 * * *', $purge->expression); $this->assertTrue($purge->withoutOverlapping);
        $this->assertSame('* * * * *', $recurring->expression); $this->assertTrue($recurring->withoutOverlapping);
        $name = '77777777-7777-7777-7777-777777777777.txt'; Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->task(['attachments' => [$this->fileAttachment($name)]]);
        $this->deleteJson('/api/tasks/'.$id)->assertOk();
        Storage::disk('public')->assertMissing('chat-attachments/'.$name);
    }

    public function test_cleanup_dry_run_does_not_drain_file_queue_and_retry_rechecks_sharing(): void
    {
        $this->cleanupClock(); $name = '88888888-8888-8888-8888-888888888888.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        DB::transaction(fn () => app(TaskAttachmentCleanup::class)->enqueue([$this->fileAttachment($name)]));
        $this->artisan('tasks:purge-completed', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists('chat-attachments/'.$name); $this->assertDatabaseCount('task_attachment_deletions', 1);
        $this->task(['attachments' => [$this->fileAttachment($name)]]);
        app(TaskAttachmentCleanup::class)->drain();
        Storage::disk('public')->assertExists('chat-attachments/'.$name); $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_retry_after_unlink_and_database_acknowledgement_failure(): void
    {
        $this->cleanupClock(); $name = '99999999-9999-9999-9999-999999999999.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        DB::transaction(fn () => app(TaskAttachmentCleanup::class)->enqueue([$this->fileAttachment($name)]));
        $this->failOnSql('delete from', 'task_attachment_deletions');
        app(TaskAttachmentCleanup::class)->drain();
        Storage::disk('public')->assertMissing('chat-attachments/'.$name); $this->assertDatabaseCount('task_attachment_deletions', 1);
        app(TaskAttachmentCleanup::class)->drain();
        $this->assertDatabaseCount('task_attachment_deletions', 0);
    }

    public function test_cleanup_outer_rollback_preserves_file_and_discards_deletion_intent(): void
    {
        $now = $this->cleanupClock(); $name = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.txt';
        Storage::disk('public')->put('chat-attachments/'.$name, 'abc');
        $id = $this->expiredTask('outer_rollback', $now-30*86400000);
        DB::table('tasks')->where('id', $id)->update(['attachments' => json_encode([$this->fileAttachment($name)])]);
        try {
            app(StateConcurrency::class)->run(function () use ($id, $now) {
                app(TaskService::class)->purgeCompleted($id, $now-30*86400000);
                throw new \RuntimeException('Roll back compound operation');
            });
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back compound operation', $exception->getMessage());
        }
        $this->assertDatabaseHas('tasks', ['id' => $id]); Storage::disk('public')->assertExists('chat-attachments/'.$name);
        $this->assertDatabaseCount('task_attachment_deletions', 0);
    }
}
