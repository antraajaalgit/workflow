<?php

namespace Tests\Feature;

use App\Mcp\Servers\KaryaServer;
use App\Mcp\Tools\ListOverdueTeamTasksTool;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Passport\Passport;
use Tests\IsolatedDatabase;

class ListOverdueTeamTasksMcpTest extends TestCase
{
    private IsolatedDatabase $database;
    private mixed $allowlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) (require $file)->up();
        }
        foreach (['admin' => ['admin', 1], 'one' => ['team', 2], 'two' => ['team', 2], 'idle' => ['team', 2]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => ucfirst($id), 'role' => $role,
                'role_id' => $roleId, 'email' => $id.'@example.test', 'password' => 'unused']);
        }
        DB::table('clients')->insert(['id' => 'client', 'name' => 'Client', 'company' => 'Client']);
        DB::table('projects')->insert(['id' => 'project', 'client_id' => 'client', 'name' => 'Launch']);
        $this->allowlist = env('KARYA_MCP_ALLOWED_ADMIN_IDS');
        Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', 'admin');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow();
            $this->allowlist === null ? Env::getRepository()->clear('KARYA_MCP_ALLOWED_ADMIN_IDS') : Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', $this->allowlist);
            $this->database->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function tool()
    {
        return app(ListOverdueTeamTasksTool::class)->handle(new Request([]));
    }

    private function task(string $id, string $due, array $extra = []): void
    {
        DB::table('tasks')->insert(array_merge([
            'id' => $id, 'client_id' => 'client', 'title' => $id, 'department' => 'General',
            'owner_id' => 'one', 'owner_ids' => json_encode(['one']), 'status' => 'todo',
            'progress' => 'just_started', 'priority' => 'med', 'created_at_ms' => 1,
            'stage_at_ms' => 1, 'due_date_ms' => CarbonImmutable::parse($due, 'Asia/Kolkata')->getTimestampMs(),
        ], $extra));
    }

    public function test_admin_only_and_registered(): void
    {
        $this->assertContains(ListOverdueTeamTasksTool::class, (new \ReflectionProperty(KaryaServer::class, 'tools'))->getDefaultValue());
        $this->assertSame([], (array) app(ListOverdueTeamTasksTool::class)->toArray()['inputSchema']['properties']);
        $this->assertTrue($this->tool()->isError());
        Passport::actingAs(User::findOrFail('one'), [], 'api');
        $this->assertTrue($this->tool()->isError());
        Passport::actingAs(User::findOrFail('admin'), [], 'api');
        $this->assertNotInstanceOf(Response::class, $this->tool());
    }

    public function test_overdue_tasks_include_each_assignee_once_and_exclude_completed_or_today(): void
    {
        Passport::actingAs(User::findOrFail('admin'), [], 'api');
        $this->task('shared', '2026-09-17 23:59:00', ['project_id' => 'project', 'owner_ids' => json_encode(['one', 'two', 'two'])]);
        $this->task('legacy', '2026-09-16 10:00:00', ['owner_id' => 'two', 'owner_ids' => null]);
        $this->task('today', '2026-09-18 00:00:00');
        $this->task('done', '2026-09-17 10:00:00', ['status' => 'done']);
        $this->task('complete', '2026-09-17 10:00:00', ['progress' => 'completed']);
        $this->task('undated', '2026-09-17 10:00:00', ['due_date_ms' => null]);

        $response = $this->tool();
        $this->assertNotInstanceOf(Response::class, $response);
        $data = $response->getStructuredContent();
        $this->assertSame(2, $data['count']);
        $this->assertSame([
            ['name' => 'One', 'overdue_task_count' => 1, 'tasks' => [
                ['title' => 'shared', 'project_name' => 'Launch', 'due_date' => '2026-09-17'],
            ]],
            ['name' => 'Two', 'overdue_task_count' => 2, 'tasks' => [
                ['title' => 'legacy', 'project_name' => null, 'due_date' => '2026-09-16'],
                ['title' => 'shared', 'project_name' => 'Launch', 'due_date' => '2026-09-17'],
            ]],
        ], $data['team_members']);
    }
}
