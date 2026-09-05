<?php

namespace Tests\Feature;

use App\Mail\TaskAssignment;
use App\Services\RecurringTaskGenerator;
use App\Services\StateConcurrency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\IsolatedDatabase;

class TaskApiTest extends TestCase
{
    use CompoundTaskAssertions;
    use CompletedTaskCleanupAssertions;
    use McpToolAssertions;
    private ?IsolatedDatabase $testDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMcpTestAllowlist();
        Mail::fake();
        config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        $this->testDatabase = new IsolatedDatabase;
        $this->testDatabase->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            // Do not run the production account data migration in tests.
            if (str_contains($file, 'seed_admin_credentials_and_roles')) {
                continue;
            }
            (require $file)->up();
        }
        DB::table('departments')->insert(['id' => 'dept_general', 'name' => 'General']);
        DB::table('clients')->insert(['id' => 'c_test', 'name' => 'Client', 'company' => 'Test']);
        foreach (['admin' => 'admin', 'one' => 'team', 'two' => 'team', 'other' => 'team', 'client' => 'client'] as $id => $role) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role,
                'department' => $role === 'team' ? 'General' : null, 'role_id' => $role === 'admin' ? 1 : ($role === 'team' ? 2 : 0),
                'email' => $id.'@example.test', 'password' => 'unused', 'client_id' => $role === 'client' ? 'c_test' : null]);
        }
        $this->withSession(['nagare_user_id' => 'admin']);
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            $this->restoreMcpTestAllowlist();
            $this->testDatabase?->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function task(array $extra = []): string
    {
        return $this->postJson('/api/tasks', $extra + ['title' => 'Test task', 'owner_ids' => ['one']])
            ->assertCreated()->json('id');
    }

    public function test_create_get_update_and_preserve_unrelated_fields(): void
    {
        $id = $this->task(['id' => 't_string', 'description' => 'Keep me']);
        $this->getJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('owner_id', 'one');
        $this->patchJson('/api/tasks/'.$id, ['title' => 'Updated', 'id' => 'overwrite', 'created_at_ms' => 1])
            ->assertOk()->assertJsonPath('title', 'Updated')->assertJsonPath('id', $id)->assertJsonPath('description', 'Keep me');
        $this->assertDatabaseCount('tasks', 1);
        $this->postJson('/api/tasks', ['id' => $id, 'title' => 'Duplicate'])->assertUnprocessable();
    }

    public function test_missing_tasks_return_404(): void
    {
        $this->getJson('/api/tasks/missing')->assertNotFound();
        $this->patchJson('/api/tasks/missing', ['title' => 'x'])->assertNotFound();
        $this->deleteJson('/api/tasks/missing')->assertNotFound();
    }

    public function test_status_and_progress_values_and_endpoint_isolation(): void
    {
        $id = $this->task(['stage_at_ms' => 1]);
        foreach (['new', 'todo', 'in_progress', 'review', 'done', 'blocked'] as $status) {
            $result = $this->patchJson('/api/tasks/'.$id.'/status', ['status' => $status, 'title' => 'Ignored'])
                ->assertOk()->assertJsonPath('status', $status)->assertJsonPath('title', 'Test task');
            $this->assertGreaterThan(1, $result->json('stage_at_ms'));
        }
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/tasks/'.$id.'/status', [])->assertUnprocessable();
        foreach (['just_started', '25', '50', '75', 'completed'] as $progress) {
            $this->patchJson('/api/tasks/'.$id.'/progress', ['progress' => $progress])->assertOk()->assertJsonPath('progress', $progress);
        }
        foreach (['100', 25, 'invalid'] as $progress) {
            $this->patchJson('/api/tasks/'.$id.'/progress', ['progress' => $progress])->assertUnprocessable();
        }
    }

    public function test_single_multiple_legacy_and_invalid_assignees(): void
    {
        $id = $this->task();
        $url = '/api/tasks/'.$id.'/assignees';
        $this->patchJson($url, ['owner_id' => 'two'])->assertOk()->assertJsonPath('owner_ids', ['two']);
        $this->patchJson($url, ['owner_ids' => ['one', 'two'], 'owner_id' => 'admin'])->assertOk()
            ->assertJsonPath('owner_id', 'one')->assertJsonPath('owner_ids', ['one', 'two']);
        foreach ([['missing'], ['client'], ['one', 'one']] as $owners) {
            $this->patchJson($url, ['owner_ids' => $owners])->assertUnprocessable();
        }
        $this->patchJson($url, [])->assertUnprocessable();
        $this->patchJson($url, ['owner_ids' => []])->assertOk()->assertJsonPath('owner_id', null);
        DB::table('tasks')->where('id', $id)->update(['owner_id' => 'two', 'owner_ids' => null]);
        $this->getJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('owner_ids', ['two']);
        $this->withSession(['nagare_user_id' => 'two'])->patchJson('/api/tasks/'.$id, ['title' => 'Legacy owner'])->assertOk();
    }

    public function test_authorization_for_assigned_team_clients_and_guests(): void
    {
        $id = $this->task(['owner_ids' => ['one', 'two']]);
        $this->withSession(['nagare_user_id' => 'two']);
        $this->patchJson('/api/tasks/'.$id, ['title' => 'Second owner'])->assertOk();
        $this->patchJson('/api/tasks/'.$id, ['department' => 'Other'])->assertForbidden();
        $this->withSession(['nagare_user_id' => 'other']);
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'done'])->assertForbidden();
        $this->deleteJson('/api/tasks/'.$id)->assertForbidden();
        $this->withSession(['nagare_user_id' => 'client']);
        $this->getJson('/api/tasks/'.$id)->assertOk();
        $this->patchJson('/api/tasks/'.$id, ['title' => 'Denied'])->assertForbidden();
        $this->postJson('/api/tasks', ['title' => 'Brief'])->assertCreated()->assertJsonPath('status', 'new')->assertJsonPath('client_id', 'c_test');
        $this->postJson('/api/tasks', ['title' => 'Denied', 'owner_id' => 'one'])->assertForbidden();
        $this->withSession(['nagare_user_id' => null]);
        $this->getJson('/api/tasks/'.$id)->assertUnauthorized();
        $this->postJson('/api/tasks', ['title' => 'Denied'])->assertUnauthorized();
    }

    public function test_relationships_recurrence_and_attachments(): void
    {
        DB::table('projects')->insert(['id' => 'p_test', 'client_id' => 'c_test', 'name' => 'Project', 'status' => 'active']);
        $attachment = ['id' => 'file', 'name' => 'brief.txt', 'type' => 'text/plain', 'size' => 1, 'data' => 'data:text/plain;base64,eA=='];
        $id = $this->task(['project_id' => 'p_test', 'attachments' => [$attachment], 'recurring' => 'weekly']);
        $result = $this->getJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('client_id', 'c_test')->assertJsonPath('attachments', [$attachment]);
        $this->assertNotNull($result->json('next_recurrence_at_ms'));
        $this->patchJson('/api/tasks/'.$id, ['recurring' => null])->assertOk()->assertJsonPath('next_recurrence_at_ms', null);
        $this->patchJson('/api/tasks/'.$id, ['project_id' => 'missing'])->assertUnprocessable();
        $this->patchJson('/api/tasks/'.$id, ['client_id' => 'missing'])->assertUnprocessable();
        $this->postJson('/api/tasks', ['title' => ''])->assertUnprocessable();
    }

    public function test_delete_retains_conversation_and_other_tasks(): void
    {
        $id = $this->task();
        $other = $this->task();
        DB::table('messages')->insert(['id' => 'm_test', 'client_id' => null, 'task_id' => $id, 'from_id' => 'one', 'from_role' => 'team', 'sent_at_ms' => 1]);
        $this->withSession(['nagare_user_id' => 'one'])->deleteJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('tasks', ['id' => $id]);
        $this->assertDatabaseHas('tasks', ['id' => $other]);
        $this->assertDatabaseHas('messages', ['id' => 'm_test', 'task_id' => null]);
    }

    public function test_existing_state_routes_and_state_task_serialization(): void
    {
        $id = $this->task();
        $this->getJson('/api/state')->assertOk()->assertJsonFragment(['id' => $id, 'ownerId' => 'one', 'ownerIds' => ['one']]);
        foreach (['GET' => 'show', 'PUT' => 'update'] as $method => $action) {
            $route = Route::getRoutes()->match(Request::create('/api/state', $method));
            $this->assertSame('App\\Http\\Controllers\\StateController@'.$action, $route->getActionName());
        }
        // Exercise PUT authentication without invoking its MySQL-only full-state replacement.
        $this->withSession(['nagare_user_id' => null])->putJson('/api/state', [])->assertUnauthorized();
    }

    public function test_assignment_mail_reassignment_and_history_are_idempotent(): void
    {
        $id = $this->task(['owner_ids' => ['one', 'two']]);
        Mail::assertSent(TaskAssignment::class, 2);
        Mail::assertSent(TaskAssignment::class, fn ($mail) => $mail->hasTo('one@example.test') && $mail->task['id'] === $id);
        $this->assertDatabaseCount('activities', 1);
        $this->patchJson('/api/tasks/'.$id.'/assignees', ['owner_ids' => ['two', 'other']])->assertOk();
        Mail::assertSent(TaskAssignment::class, 3);
        Mail::assertSent(TaskAssignment::class, fn ($mail) => $mail->hasTo('other@example.test'));
        $this->patchJson('/api/tasks/'.$id.'/assignees', ['owner_ids' => ['two', 'other']])->assertOk();
        Mail::assertSent(TaskAssignment::class, 3);
        $this->assertDatabaseCount('activities', 2);
        $this->patchJson('/api/tasks/'.$id.'/assignees', ['owner_ids' => ['missing']])->assertUnprocessable();
        Mail::assertSent(TaskAssignment::class, 3);
        $this->assertDatabaseCount('activities', 2);
    }

    public function test_completion_and_client_brief_notifications_are_persisted_not_sent(): void
    {
        DB::table('clients')->where('id', 'c_test')->update(['email' => 'customer@example.test', 'phone' => '123']);
        $this->withSession(['nagare_user_id' => 'client']);
        $id = $this->postJson('/api/tasks', ['title' => 'Customer brief'])->assertCreated()->json('id');
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseHas('activities', ['text' => 'New brief from Test awaiting assignment']);
        Mail::assertNothingSent();
        $this->withSession(['nagare_user_id' => 'admin']);
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'done'])->assertOk();
        $this->assertDatabaseCount('notifications', 6);
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'done'])->assertOk();
        $this->assertDatabaseCount('notifications', 6);
        Mail::assertNothingSent();
    }

    public function test_fresh_state_save_and_stale_api_update_create_delete_protection(): void
    {
        $id = $this->task();
        $state = $this->getJson('/api/state')->assertOk()->json();
        $state['tasks'][0]['title'] = 'Dashboard edit';
        $saved = $this->putJson('/api/state', $state)->assertOk()->json();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'title' => 'Test task']);
        $state['_revision'] = $saved['_revision'];
        $this->patchJson('/api/tasks/'.$id, ['title' => 'API edit'])->assertOk();
        $this->putJson('/api/state', $state)->assertConflict();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'title' => 'API edit']);
        $state = $this->getJson('/api/state')->json();
        $new = $this->task();
        $this->putJson('/api/state', $state)->assertConflict();
        $this->assertDatabaseHas('tasks', ['id' => $new]);
        $state = $this->getJson('/api/state')->json();
        $this->deleteJson('/api/tasks/'.$id)->assertOk();
        $this->putJson('/api/state', $state)->assertConflict();
        $this->assertDatabaseMissing('tasks', ['id' => $id]);
        unset($state['_revision']);
        $this->putJson('/api/state', $state)->assertConflict();
    }

    public function test_state_reassignment_is_ignored_without_sending_mail(): void
    {
        $id = $this->task();
        Mail::fake();
        $state = $this->getJson('/api/state')->json();
        $state['tasks'][0]['ownerIds'] = ['two'];
        $state['tasks'][0]['ownerId'] = 'two';
        $this->putJson('/api/state', $state)->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'owner_id' => 'one']);
        $state = $this->getJson('/api/state')->json();
        $this->putJson('/api/state', $state)->assertOk();
        Mail::assertNothingSent();
    }

    public function test_recurring_generation_keeps_schema_values_and_invalidates_state(): void
    {
        $at = CarbonImmutable::parse('2026-09-04 12:00:00');
        $id = $this->task(['recurring' => 'daily', 'next_recurrence_at_ms' => $at->getTimestampMs(), 'owner_ids' => ['one', 'two']]);
        $state = $this->getJson('/api/state')->json();
        $this->assertSame(1, app(RecurringTaskGenerator::class)->generateDueTasks($at));
        $this->assertSame(0, app(RecurringTaskGenerator::class)->generateDueTasks($at));
        $row = DB::table('tasks')->where('id', '!=', $id)->first();
        $this->assertSame(['one', 'two'], json_decode($row->owner_ids, true));
        $this->assertSame('one', $row->owner_id);
        $this->assertSame('todo', $row->status);
        $this->assertSame('just_started', $row->progress);
        $this->assertNull($row->recurring);
        $this->assertSame($at->getTimestampMs(), (int) $row->created_at_ms);
        $this->putJson('/api/state', $state)->assertConflict();
    }

    public function test_unsafe_attachment_data_and_task_ids_are_rejected(): void
    {
        $this->postJson('/api/tasks', ['title' => 'Unsafe', 'id' => 'x" onclick="bad'])->assertUnprocessable();
        $this->postJson('/api/tasks', ['title' => 'Unsafe', 'attachments' => [
            ['id' => 'file', 'name' => 'x', 'type' => 'text/plain', 'size' => 1, 'data' => 'javascript:alert(1)'],
        ]])->assertUnprocessable();
        Mail::assertNothingSent();
    }

    public function test_mail_failure_does_not_rollback_task_or_history(): void
    {
        Mail::shouldReceive('mailer')->with('chat_smtp')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->postJson('/api/tasks', ['id' => 't_mail_failure', 'title' => 'Saved despite mail failure', 'owner_id' => 'one'])
            ->assertCreated()->assertJsonPath('assignmentMailFailures', ['one@example.test']);
        $this->assertDatabaseHas('tasks', ['id' => 't_mail_failure']);
        $this->assertDatabaseCount('activities', 1);
    }

    public function test_team_creation_and_unassigned_admin_validation(): void
    {
        $this->withSession(['nagare_user_id' => 'one']);
        $id = $this->task(['owner_ids' => ['one', 'two']]);
        $this->patchJson('/api/tasks/'.$id.'/assignees', ['owner_ids' => ['two']])->assertOk();
        $this->patchJson('/api/tasks/'.$id, ['title' => 'No longer assigned'])->assertForbidden();
        $this->postJson('/api/tasks', ['title' => 'Repeating', 'recurring' => 'daily'])->assertForbidden();
        $this->withSession(['nagare_user_id' => 'admin']);
        DB::table('users')->where('id', 'admin')->update(['email' => null]);
        $this->patchJson('/api/tasks/'.$id.'/assignees', ['owner_ids' => ['admin']])->assertUnprocessable();
    }

    public function test_all_task_routes_require_a_valid_session(): void
    {
        $id = $this->task();
        $this->withSession(['nagare_user_id' => 'deleted-user']);
        foreach ([['GET', '/'.$id], ['POST', ''], ['PATCH', '/'.$id], ['PATCH', '/'.$id.'/status'],
            ['PATCH', '/'.$id.'/progress'], ['PATCH', '/'.$id.'/assignees'], ['DELETE', '/'.$id]] as [$method, $path]) {
            $this->json($method, '/api/tasks'.$path, [])->assertUnauthorized();
        }
    }

    public function test_mysql_mutex_blocks_other_connections_and_releases_after_rollback(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the guarded dedicated MySQL test configuration.');
        }
        config(['database.connections.task_mysql_peer' => config('database.connections.task_mysql_test')]);
        $peer = DB::connection('task_mysql_peer');
        $name = 'karya-state-'.substr(hash('sha256', 'workflow_task_api_test'), 0, 40);
        try {
            app(StateConcurrency::class)->run(function () use ($peer, $name) {
                $this->assertSame(0, (int) $peer->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
                DB::table('tasks')->where('id', 'missing')->delete();
                throw new \RuntimeException('Force rollback');
            });
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force rollback', $exception->getMessage());
            $this->assertSame(1, (int) $peer->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        } finally {
            $peer->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            DB::purge('task_mysql_peer');
        }
    }
}
