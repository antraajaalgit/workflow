<?php

namespace Tests\Feature;

use App\Mail\TaskAssignment;
use App\Services\StateConcurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

trait CompoundTaskAssertions
{
    private function revision(): array
    {
        return ['_revision' => app(StateConcurrency::class)->revision()];
    }

    private function grid(array $extra = []): array
    {
        return $extra + ['name' => 'Project', 'client_id' => 'c_test', 'delete_ids' => [],
            'tasks' => [['title' => 'Grid task', 'owner_ids' => ['one', 'two'], 'department' => 'General', 'progress' => '25']]] + $this->revision();
    }

    /** Fail after the SQL has executed, proving all preceding writes roll back. */
    private function failOnSql(string $verb, string $table): void
    {
        $fired = false;
        $name = DB::connection()->getTablePrefix().$table;
        DB::listen(function ($query) use ($verb, $name, &$fired) {
            $sql = str_replace(['`', '"'], '', strtolower($query->sql));
            if (! $fired && str_starts_with($sql, $verb.' '.$name.' ')) {
                $fired = true;
                throw new \RuntimeException('Injected database failure');
            }
        });
    }

    public function test_atomic_multi_field_edit_and_completion(): void
    {
        $id = $this->task();
        Mail::fake();
        $before = DB::table('tasks')->where('id', $id)->first();
        $this->patchJson('/api/tasks/'.$id, ['title' => 'Must roll back', 'status' => 'done', 'progress' => 'completed', 'owner_ids' => ['missing']])->assertUnprocessable();
        $this->assertEquals($before, DB::table('tasks')->where('id', $id)->first());
        Mail::assertNothingSent();
        $this->patchJson('/api/tasks/'.$id, ['title' => 'All together', 'status' => 'done', 'progress' => 'completed', 'owner_ids' => ['two', 'one']])
            ->assertOk()->assertJsonPath('title', 'All together')->assertJsonPath('status', 'done')
            ->assertJsonPath('progress', 'completed')->assertJsonPath('owner_id', 'two')->assertJsonPath('owner_ids', ['two', 'one']);
        Mail::assertSent(TaskAssignment::class, 1);
        $this->patchJson('/api/tasks/'.$id, ['status' => 'done', 'progress' => 'completed', 'owner_ids' => ['two', 'one']])->assertOk();
        Mail::assertSent(TaskAssignment::class, 1);
    }

    public function test_task_effect_failure_rolls_back_task_and_email(): void
    {
        $this->failOnSql('insert into', 'activities');
        $this->postJson('/api/tasks', ['title' => 'Fail', 'owner_ids' => ['one']])->assertStatus(500);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('activities', 0);
        Mail::assertNothingSent();
    }

    public function test_project_grid_creates_edits_assigns_and_deletes_atomically(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        $id = $state['projects'][0]['id']; $task = $state['tasks'][0]['id'];
        $this->assertDatabaseHas('tasks', ['id' => $task, 'project_id' => $id, 'client_id' => 'c_test', 'owner_id' => 'one', 'progress' => '25']);
        Mail::assertSent(TaskAssignment::class, 2);
        Mail::fake();
        $edited = $this->grid(['tasks' => [['id' => $task, 'title' => 'Edited', 'status' => 'review', 'progress' => '75', 'owner_ids' => ['two']]]]);
        $this->patchJson('/api/projects/'.$id.'/tasks', $edited)->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task, 'title' => 'Edited', 'status' => 'review', 'progress' => '75', 'owner_id' => 'two']);
        Mail::assertNothingSent();
        $this->patchJson('/api/projects/'.$id.'/tasks', $this->grid(['delete_ids' => [$task]]))->assertOk();
        $this->assertDatabaseMissing('tasks', ['id' => $task]);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('activities', ['text' => 'admin updated project "Project" with 1 assigned task']);
    }

    public function test_project_grid_later_failure_rolls_back_earlier_rows_and_emails(): void
    {
        $this->postJson('/api/projects', $this->grid(['tasks' => [
            ['title' => 'Valid first', 'owner_ids' => ['one']], ['title' => 'Invalid second', 'owner_ids' => ['missing']],
        ]]))->assertUnprocessable();
        foreach (['projects', 'tasks', 'activities', 'notifications'] as $table) $this->assertDatabaseCount($table, 0);
        Mail::assertNothingSent();
        // A later successful transaction must not flush a rolled-back mail callback.
        $this->task(['owner_ids' => []]);
        Mail::assertNothingSent();
    }

    public function test_project_grid_rejects_foreign_task_and_stale_revision(): void
    {
        $other = $this->task();
        $this->postJson('/api/projects', $this->grid(['tasks' => [['id' => $other, 'title' => 'Hijack']]]))->assertUnprocessable();
        $this->assertDatabaseCount('projects', 0);
        $stale = $this->grid(); $this->task();
        $this->postJson('/api/projects', $stale)->assertConflict();
    }

    public function test_voice_brief_creates_message_task_history_and_notifications_together(): void
    {
        $this->withSession(['nagare_user_id' => 'client']);
        $state = $this->postJson('/api/briefs', ['task' => ['title' => 'Voice brief', 'description' => 'Details'], 'voice' => 'data:audio/webm;codecs=opus;base64,YWJj'])
            ->assertCreated()->json('state');
        $task = $state['tasks'][0];
        $this->assertSame('new', $task['status']); $this->assertSame('c_test', $task['clientId']);
        $this->assertDatabaseHas('messages', ['task_id' => $task['id'], 'client_id' => 'c_test', 'from_id' => 'client', 'voice' => 'data:audio/webm;codecs=opus;base64,YWJj']);
        $this->assertDatabaseCount('activities', 1); $this->assertDatabaseCount('notifications', 2);
        Mail::assertNothingSent();
    }

    public function test_voice_brief_message_failure_rolls_back_all_side_effects(): void
    {
        $this->withSession(['nagare_user_id' => 'client']);
        $this->failOnSql('insert into', 'messages');
        $this->postJson('/api/briefs', ['task' => ['title' => 'Voice brief'], 'voice' => 'data:audio/webm;base64,YWJj'])->assertStatus(500);
        foreach (['tasks', 'messages', 'activities', 'notifications'] as $table) $this->assertDatabaseCount($table, 0);
        Mail::assertNothingSent();
    }

    public function test_voice_brief_validates_voice_and_uses_client_authorization(): void
    {
        $payload = ['task' => ['title' => 'Voice brief'], 'voice' => 'data:audio/webm;base64,YWJj'];
        $this->postJson('/api/briefs', $payload)->assertForbidden();
        $this->withSession(['nagare_user_id' => 'client']);
        $this->postJson('/api/briefs', array_replace($payload, ['voice' => 'javascript:bad']))->assertUnprocessable();
        $payload['task']['owner_ids'] = ['one'];
        $this->postJson('/api/briefs', $payload)->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_project_deletion_rolls_back_on_failure_then_detaches_messages(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        $id = $state['projects'][0]['id']; $task = $state['tasks'][0]['id'];
        DB::table('messages')->insert(['id' => 'message', 'task_id' => $task, 'from_id' => 'one', 'from_role' => 'team', 'sent_at_ms' => 1]);
        $revision = $this->revision();
        $this->failOnSql('delete from', 'projects');
        $this->deleteJson('/api/projects/'.$id, $revision)->assertStatus(500);
        $this->assertSame($revision, $this->revision());
        $this->assertDatabaseHas('messages', ['id' => 'message', 'task_id' => $task]);
        $this->deleteJson('/api/projects/'.$id, $revision)->assertOk();
        $this->assertDatabaseCount('projects', 0); $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('messages', ['id' => 'message', 'task_id' => null]);
    }

    public function test_client_profile_project_link_and_unlink_update_all_tasks(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        $project = $state['projects'][0]['id']; $task = $state['tasks'][0]['id'];
        $profile = ['name' => 'Updated', 'company' => 'Company', 'email' => 'client@example.test', 'phone' => '123', 'project_id' => null];
        $this->patchJson('/api/clients/c_test', $profile + $this->revision())->assertOk();
        $this->assertDatabaseHas('projects', ['id' => $project, 'client_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $task, 'client_id' => null]);
        $this->assertDatabaseHas('users', ['id' => 'client', 'name' => 'Updated']);
        $profile['project_id'] = $project;
        $this->patchJson('/api/clients/c_test', $profile + $this->revision())->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task, 'client_id' => 'c_test']);
    }

    public function test_client_profile_link_failure_rolls_back_profile_login_and_tasks(): void
    {
        $this->postJson('/api/projects', $this->grid())->assertCreated();
        $before = $this->revision();
        $this->failOnSql('update', 'tasks');
        $this->patchJson('/api/clients/c_test', ['name' => 'New name', 'company' => 'Changed', 'email' => 'client@example.test', 'phone' => '123', 'project_id' => null] + $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
    }

    public function test_client_deletion_is_atomic_for_projects_tasks_messages_and_accounts(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        DB::table('messages')->insert(['id' => 'message', 'client_id' => 'c_test', 'task_id' => $state['tasks'][0]['id'], 'from_id' => 'client', 'from_role' => 'client', 'sent_at_ms' => 1]);
        $before = $this->revision();
        $this->failOnSql('delete from', 'clients');
        $this->deleteJson('/api/clients/c_test', $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
        $this->deleteJson('/api/clients/c_test', $before)->assertOk();
        foreach (['clients', 'projects', 'tasks', 'messages'] as $table) $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseMissing('users', ['id' => 'client']); $this->assertDatabaseHas('users', ['id' => 'one']);
    }

    public function test_member_removal_is_atomic_and_preserves_other_owners_without_email(): void
    {
        $id = $this->task(['owner_ids' => ['one', 'two']]); Mail::fake();
        $before = $this->revision();
        $this->failOnSql('delete from', 'users');
        $this->deleteJson('/api/team-members/one', $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
        $this->deleteJson('/api/team-members/one', $before)->assertOk();
        $this->getJson('/api/tasks/'.$id)->assertOk()->assertJsonPath('owner_id', 'two')->assertJsonPath('owner_ids', ['two']);
        $this->assertDatabaseMissing('users', ['id' => 'one']);
        Mail::assertNothingSent();
        $this->deleteJson('/api/team-members/two', $this->revision())->assertOk();
        $this->getJson('/api/tasks/'.$id)->assertJsonPath('owner_id', null)->assertJsonPath('owner_ids', []);
        $this->deleteJson('/api/team-members/admin', $this->revision())->assertForbidden();
    }

    public function test_department_rename_rollback_and_deletion_fallback(): void
    {
        $id = $this->task(); $before = $this->revision();
        $this->failOnSql('update', 'departments');
        $this->patchJson('/api/departments/dept_general', ['name' => 'Engineering QA'] + $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
        $this->patchJson('/api/departments/dept_general', ['name' => 'Engineering QA'] + $before)->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'department' => 'Engineering QA']);
        $this->assertDatabaseHas('users', ['id' => 'one', 'department' => 'Engineering QA']);
        $this->deleteJson('/api/departments/dept_general', $this->revision())->assertUnprocessable();
        $state = $this->postJson('/api/departments', ['name' => 'Empty'] + $this->revision())->assertCreated()->json('state');
        $empty = collect($state['departments'])->firstWhere('name', 'Empty')['id'];
        $this->patchJson('/api/tasks/'.$id, ['department' => 'Empty'])->assertOk();
        $before = $this->revision(); $this->failOnSql('delete from', 'departments');
        $this->deleteJson('/api/departments/'.$empty, $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
        $this->deleteJson('/api/departments/'.$empty, $before)->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $id, 'department' => DB::table('departments')->orderBy('name')->value('name')]);
    }

    public function test_state_ignores_missing_forged_or_empty_tasks_and_preserves_foreign_keys(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        $before = DB::table('tasks')->get()->all(); Mail::fake();
        foreach ([[], [['id' => 'forged', 'title' => 'Bad']], null] as $tasks) {
            $state = $this->getJson('/api/state')->json();
            if ($tasks === null) unset($state['tasks']); else $state['tasks'] = $tasks;
            $state['settings']['amberMin'] = 42;
            $this->putJson('/api/state', $state)->assertOk();
            $this->assertEquals($before, DB::table('tasks')->get()->all());
            $this->assertDatabaseHas('settings', ['key' => 'amberMin', 'value' => '42']);
        }
        Mail::assertNothingSent();
        foreach (['projects', 'clients', 'users', 'departments'] as $table) {
            $state = $this->getJson('/api/state')->json(); $state[$table] = [];
            $this->putJson('/api/state', $state)->assertStatus(in_array($table, ['departments']) ? 422 : 409);
            $this->assertEquals($before, DB::table('tasks')->get()->all());
        }
    }

    public function test_compound_routes_reject_team_clients_and_guests(): void
    {
        foreach (['one', 'client', 'missing'] as $actor) {
            $this->withSession(['nagare_user_id' => $actor]); $status = $actor === 'missing' ? 401 : 403;
            foreach (['/api/projects/p', '/api/clients/c_test', '/api/team-members/one', '/api/departments/dept_general'] as $url) $this->deleteJson($url, $this->revision())->assertStatus($status);
            $this->postJson('/api/projects', $this->grid())->assertStatus($status);
            $this->postJson('/api/clients', $this->revision())->assertStatus($status);
            $this->postJson('/api/departments', $this->revision())->assertStatus($status);
        }
    }

    public function test_client_creation_is_atomic_with_login_and_project_link(): void
    {
        $state = $this->postJson('/api/projects', $this->grid())->assertCreated()->json('state');
        $id = $state['projects'][0]['id'];
        $profile = ['name' => 'New client', 'company' => 'New company', 'email' => 'new@example.test', 'phone' => '123',
            'password' => 'a-test-password', 'project_id' => $id];
        $before = $this->revision(); $this->failOnSql('update', 'tasks');
        $this->postJson('/api/clients', $profile + $before)->assertStatus(500);
        $this->assertSame($before, $this->revision());
        $state = $this->postJson('/api/clients', $profile + $before)->assertCreated()->json('state');
        $client = collect($state['clients'])->firstWhere('email', 'new@example.test');
        $this->assertDatabaseHas('users', ['id' => $client['id'], 'role' => 'client', 'client_id' => $client['id']]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-test-password', DB::table('users')->where('id', $client['id'])->value('password')));
        $this->assertDatabaseHas('tasks', ['project_id' => $id, 'client_id' => $client['id']]);
    }

    public function test_mysql_json_milliseconds_and_nested_advisory_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('Requires the guarded dedicated MySQL test configuration.');
        $id = $this->task(['id' => 't_string_mysql', 'owner_ids' => ['one', 'two'], 'due_date_ms' => 4102444800123,
            'attachments' => [['id' => 'a1', 'name' => 'file.txt', 'type' => 'text/plain', 'size' => 3, 'data' => 'data:text/plain;base64,YWJj']]]);
        $row = DB::table('tasks')->where('id', $id)->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(owner_ids, '$[1]')) AS second_owner, JSON_UNQUOTE(JSON_EXTRACT(attachments, '$[0].name')) AS file_name, due_date_ms")->first();
        $this->assertSame('two', $row->second_owner); $this->assertSame('file.txt', $row->file_name);
        $this->assertSame(4102444800123, (int) $row->due_date_ms);
        config(['database.connections.task_mysql_peer' => config('database.connections.task_mysql_test')]);
        $peer = DB::connection('task_mysql_peer');
        $name = 'karya-state-'.substr(hash('sha256', 'workflow_task_api_test'), 0, 40);
        try {
            app(StateConcurrency::class)->run(function () use ($peer, $name, $id) {
                $actor = DB::table('users')->where('id', 'admin')->first();
                app(\App\Services\TaskService::class)->update($id, new \Illuminate\Http\Request(['progress' => 'completed', 'status' => 'done']), $actor);
                $this->assertSame(0, (int) $peer->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
            });
            $this->assertSame(1, (int) $peer->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        } finally {
            $peer->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]); DB::purge('task_mysql_peer');
        }
        $before = DB::table('tasks')->where('id', $id)->first();
        $state = $this->getJson('/api/state')->json(); $state['tasks'] = [];
        $this->putJson('/api/state', $state)->assertOk();
        $this->assertEquals($before, DB::table('tasks')->where('id', $id)->first());
    }
}
