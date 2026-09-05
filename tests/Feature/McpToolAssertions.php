<?php

namespace Tests\Feature;

use App\Mcp\Servers\KaryaServer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Passport\Passport;

trait McpToolAssertions
{
    private mixed $originalMcpAllowlist = null;

    private function configureMcpTestAllowlist(): void
    {
        $this->originalMcpAllowlist = env('KARYA_MCP_ALLOWED_ADMIN_IDS');
        \Illuminate\Support\Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', 'admin');
    }

    private function restoreMcpTestAllowlist(): void
    {
        if ($this->originalMcpAllowlist === null) \Illuminate\Support\Env::getRepository()->clear('KARYA_MCP_ALLOWED_ADMIN_IDS');
        else \Illuminate\Support\Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', $this->originalMcpAllowlist);
    }
    private function mcp(string $tool, array $arguments = [])
    {
        return app()->call([app('App\\Mcp\\Tools\\'.$tool), 'handle'], ['request' => new McpRequest($arguments)]);
    }

    private function oauthAdmin(): void
    {
        DB::table('users')->where('id', 'admin')->update(['name' => 'Sales Admin']);
        Passport::actingAs(User::findOrFail('admin'), [], 'api');
    }

    private function mcpData(string $tool, array $arguments = []): array
    {
        $response = $this->mcp($tool, $arguments);
        $this->assertNotInstanceOf(Response::class, $response, $response instanceof Response ? (string) $response->content() : '');
        return $response->getStructuredContent();
    }

    public function test_mcp_all_registered_tools_enforce_oauth_admin_allowlist_and_render_schemas(): void
    {
        $property = new \ReflectionProperty(KaryaServer::class, 'tools');
        $classes = $property->getDefaultValue();
        $previous = env('KARYA_MCP_ALLOWED_ADMIN_IDS');
        try {
            foreach ($classes as $class) {
                $tool = class_basename($class);
                // All authorization must happen before field validation or reads.
                app('auth')->forgetGuards();
                $response = $this->mcp($tool);
                $this->assertInstanceOf(Response::class, $response, $tool);
                $this->assertTrue($response->isError(), $tool);
                Passport::actingAs(User::findOrFail('one'), [], 'api');
                $this->assertTrue($this->mcp($tool)->isError(), $tool);
                $this->oauthAdmin();
                \Illuminate\Support\Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', 'other_admin');
                $this->assertTrue($this->mcp($tool)->isError(), $tool);
                \Illuminate\Support\Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', ' admin , other_admin ');
                DB::table('users')->where('id', 'admin')->update(['role_id' => 2]);
                $this->assertTrue($this->mcp($tool)->isError(), $tool);
                DB::table('users')->where('id', 'admin')->update(['role_id' => 1]);
                $schema = app($class)->toArray();
                $this->assertArrayHasKey('inputSchema', $schema);
                $this->assertIsString(json_encode($schema, JSON_THROW_ON_ERROR));
            }
            $this->assertSame('admin', $this->mcpData('ListClientsTool')['actor']['id']);
        } finally {
            if ($previous === null) \Illuminate\Support\Env::getRepository()->clear('KARYA_MCP_ALLOWED_ADMIN_IDS');
            else \Illuminate\Support\Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', $previous);
        }
    }

    public function test_mcp_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/mcp/karya', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_mcp_oauth_transport_calls_registered_tool_with_authenticated_actor(): void
    {
        $this->oauthAdmin();
        $this->postJson('/mcp/karya', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create-department-tool', 'arguments' => ['name' => 'Transport test']]])
            ->assertOk()->assertJsonPath('result.structuredContent.actor.name', 'Sales Admin')
            ->assertJsonPath('result.structuredContent.success', true);
    }

    public function test_mcp_client_failure_rolls_back_login_profile_and_welcome_mail_without_leaking_sql(): void
    {
        $this->oauthAdmin();
        $before = app(\App\Services\StateConcurrency::class)->revision();
        $this->failOnSql('insert into', 'activities');
        $response = $this->mcp('CreateClientTool', ['name' => 'Rollback', 'company' => 'Rollback',
            'email' => 'rollback@example.test', 'phone' => '123', 'password' => 'Rollback-secret-123']);
        $this->assertTrue($response->isError());
        $this->assertStringNotContainsString('Rollback-secret', (string) $response->content());
        $this->assertStringNotContainsString('Injected database', (string) $response->content());
        $this->assertSame($before, app(\App\Services\StateConcurrency::class)->revision());
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_mcp_mail_failures_are_reported_after_successful_commit(): void
    {
        $this->oauthAdmin();
        $this->mock(\App\Http\Controllers\StateController::class, function ($mock) {
            $mock->shouldReceive('sendWelcomeEmail')->once()->withArgs(fn ($user, $actor) =>
                $user['role'] === 'client' && $actor->id === 'admin' && DB::transactionLevel() === 0)->andReturn(false);
        });
        $data = $this->mcpData('CreateClientTool', ['name' => 'Mail failure', 'company' => 'Mail failure',
            'email' => 'mailfailure@example.test', 'phone' => '123', 'password' => 'Mail-secret-123']);
        $this->assertSame(['mailfailure@example.test'], $data['mail_failures']);
        $this->assertDatabaseHas('clients', ['id' => $data['client']['id']]);
        $this->mock(\App\Services\TaskEffects::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('sendAssignments')->withArgs(fn ($assignments) => count($assignments) === 1)->once()->andReturn(['one@example.test']);
            $mock->shouldReceive('sendAssignments')->with([])->andReturn([]);
        });
        $project = $this->mcpData('CreateProjectTool', ['name' => 'Mail project', 'task_title' => 'Mail task', 'task_owner_ids' => ['one']]);
        $this->assertSame(['one@example.test'], $project['assignment_mail_failures']);
        $this->assertDatabaseHas('projects', ['id' => $project['project']['id']]);
    }

    public function test_mcp_existing_task_tools_and_normal_edits_preserve_effects(): void
    {
        $this->oauthAdmin();
        $data = $this->mcpData('CreateTaskTool', ['title' => 'MCP task', 'description' => 'Keep', 'owner_ids' => ['one']]);
        $id = $data['task']['id'];
        $this->assertSame('Sales Admin', $data['actor']['name']);
        $this->mcpData('AssignTaskTool', ['task_id' => $id, 'assignee_ids' => ['two']]);
        $this->mcpData('UpdateTaskStatusTool', ['task_id' => $id, 'status' => 'in_progress']);
        $this->mcpData('UpdateTaskProgressTool', ['task_id' => $id, 'progress' => '25']);
        $this->mcpData('UpdateTaskTool', ['task_id' => $id, 'title' => 'Changed', 'priority' => 'high', 'recurring' => 'weekly']);
        $this->assertDatabaseHas('tasks', ['id' => $id, 'title' => 'Changed', 'description' => 'Keep', 'owner_id' => 'two', 'progress' => '25']);
        $this->assertNotNull(DB::table('tasks')->where('id', $id)->value('next_recurrence_at_ms'));
        $this->assertTrue($this->mcp('UpdateTaskTool', ['task_id' => $id, 'title' => 'Bad', 'priority' => 'invalid'])->isError());
        $this->assertDatabaseHas('tasks', ['id' => $id, 'title' => 'Changed']);
        $this->mcpData('UpdateTaskTool', ['task_id' => $id, 'recurring' => null, 'due_date_ms' => null]);
        $this->mcpData('AssignTaskTool', ['task_id' => $id, 'assignee_ids' => []]);
        $this->assertSame(1, $this->mcpData('ListTasksTool')['count']);
        $this->assertGreaterThan(0, $this->mcpData('ListTeamMembersTool')['count']);
        $this->mcpData('DeleteTaskTool', ['task_id' => $id]);
        $this->assertDatabaseMissing('tasks', ['id' => $id]);
        $this->assertTrue(DB::table('activities')->where('text', 'like', '%Sales Admin%')->exists());
    }

    public function test_mcp_project_compound_failure_rolls_back_and_delete_cleans_up(): void
    {
        $this->oauthAdmin();
        $project = $this->mcpData('CreateProjectTool', ['name' => 'Original', 'task_title' => 'First'])['project'];
        $id = $project['id'];
        $taskId = DB::table('tasks')->where('project_id', $id)->value('id');
        $revision = app(\App\Services\StateConcurrency::class)->revision();
        $response = $this->mcp('UpdateProjectTool', ['project_id' => $id, 'name' => 'Wrong', 'delete_ids' => [$taskId], 'tasks' => [['title' => 'Valid'], ['title' => 'Invalid', 'owner_ids' => ['missing']]]]);
        $this->assertTrue($response->isError());
        $this->assertSame($revision, app(\App\Services\StateConcurrency::class)->revision());
        $this->mcpData('UpdateProjectTool', ['project_id' => $id, 'name' => 'Renamed', 'tasks' => [['id' => $taskId, 'title' => 'Updated'], ['title' => 'New']]]);
        $this->assertSame(2, DB::table('tasks')->where('project_id', $id)->count());
        $this->assertSame(1, $this->mcpData('ListProjectsTool')['count']);
        DB::table('messages')->insert(['id' => 'm_mcp', 'task_id' => $taskId, 'from_id' => 'one', 'from_role' => 'team', 'sent_at_ms' => 1]);
        $this->mcpData('DeleteProjectTool', ['project_id' => $id]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('messages', ['id' => 'm_mcp', 'task_id' => null]);
        $this->assertTrue(DB::table('activities')->where('text', 'like', 'Sales Admin deleted project%')->exists());
        $this->assertTrue($this->mcp('CreateProjectTool', ['name' => 'Bad', 'task_title' => 'Bad', 'task_owner_ids' => ['missing']])->isError());
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_mcp_client_lifecycle_never_returns_passwords_and_cleans_relationships(): void
    {
        $this->oauthAdmin();
        $secret = 'Mcp-test-secret-123';
        $client = $this->mcpData('CreateClientTool', ['name' => 'Contact', 'company' => 'Company', 'email' => 'contact@example.test', 'phone' => '123', 'password' => $secret]);
        $id = $client['client']['id'];
        $hash = DB::table('users')->where('id', $id)->value('password');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($secret, $hash));
        foreach ([$client, $this->mcpData('ListClientsTool'), $this->mcpData('UpdateClientTool', ['client_id' => $id, 'name' => 'New contact'])] as $response) {
            $json = json_encode($response);
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($hash, $json);
            $this->assertStringNotContainsString('password', $json);
        }
        $this->assertTrue($this->mcp('UpdateClientTool', ['client_id' => $id, 'password' => $secret])->isError());
        $this->mcpData('CreateProjectTool', ['name' => 'Client project', 'client_id' => $id, 'task_title' => 'Client task']);
        $this->mcpData('DeleteClientTool', ['client_id' => $id]);
        $this->assertDatabaseMissing('users', ['id' => $id]);
        $this->assertDatabaseMissing('clients', ['id' => $id]);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertTrue(DB::table('activities')->where('text', 'like', 'Sales Admin deleted client%')->exists());
    }

    public function test_mcp_departments_and_member_deletion_reuse_cleanup(): void
    {
        $this->oauthAdmin();
        $initialCount = DB::table('departments')->count();
        $id = $this->mcpData('CreateDepartmentTool', ['name' => 'MCP Design'])['department']['id'];
        DB::table('users')->where('id', 'one')->update(['department' => 'MCP Design']);
        $taskId = $this->mcpData('CreateTaskTool', ['title' => 'Design task', 'department' => 'MCP Design', 'owner_ids' => ['one']])['task']['id'];
        $this->mcpData('UpdateDepartmentTool', ['department_id' => $id, 'name' => 'Studio']);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'department' => 'Studio']);
        $this->assertDatabaseHas('users', ['id' => 'one', 'department' => 'Studio']);
        $this->assertTrue($this->mcp('DeleteDepartmentTool', ['department_id' => $id])->isError());
        $this->assertTrue($this->mcp('DeleteTeamMemberTool', ['member_id' => 'admin'])->isError());
        $this->mcpData('DeleteTeamMemberTool', ['member_id' => 'one']);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'owner_id' => null]);
        $this->mcpData('DeleteDepartmentTool', ['department_id' => $id]);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'department' => DB::table('departments')->orderBy('name')->value('name')]);
        $this->assertSame($initialCount, $this->mcpData('ListDepartmentsTool')['count']);
        $this->assertTrue(DB::table('activities')->where('text', 'like', 'Sales Admin deleted department%')->exists());
    }
}
