<?php

namespace Tests\Feature;

use App\Mcp\Servers\KaryaServer;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Passport\Passport;
use Tests\IsolatedDatabase;

class HolidayMcpTest extends TestCase
{
    private IsolatedDatabase $database;
    private mixed $allowlist;
    private const TOOLS = ['ListHolidaysTool', 'CreateHolidayTool', 'UpdateHolidayTool', 'DeleteHolidayTool'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) (require $file)->up();
        }
        foreach (['admin' => ['admin', 1, 'Admin'], 'other' => ['admin', 1, 'Other'], 'one' => ['team', 2, 'Alice Rao'],
            'two' => ['team', 2, 'Bob'], 'client' => ['client', 0, 'Client'], 'wrong' => ['team', 1, 'Wrong']] as $id => [$role, $roleId, $name]) {
            DB::table('users')->insert(['id' => $id, 'name' => $name, 'role' => $role, 'role_id' => $roleId, 'email' => $id.'@example.test', 'password' => 'unused']);
        }
        $this->allowlist = env('KARYA_MCP_ALLOWED_ADMIN_IDS');
        Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', 'admin');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 09:30:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        try {
            $this->allowlist === null ? Env::getRepository()->clear('KARYA_MCP_ALLOWED_ADMIN_IDS') : Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', $this->allowlist);
            CarbonImmutable::setTestNow();
            $this->database->cleanup();
        } finally { parent::tearDown(); }
    }

    private function callTool(string $tool, array $arguments = [])
    {
        return app('App\\Mcp\\Tools\\'.$tool)->handle(new Request($arguments));
    }

    private function data(string $tool, array $arguments = []): array
    {
        $response = $this->callTool($tool, $arguments);
        $this->assertNotInstanceOf(Response::class, $response, $response instanceof Response ? (string) $response->content() : '');
        return $response->getStructuredContent();
    }

    private function login(string $id = 'admin'): void
    {
        Passport::actingAs(User::findOrFail($id), [], 'api');
    }

    public function test_registration_and_auth_before_validation(): void
    {
        $registered = (new \ReflectionProperty(KaryaServer::class, 'tools'))->getDefaultValue();
        foreach (self::TOOLS as $tool) {
            $class = 'App\\Mcp\\Tools\\'.$tool;
            $this->assertContains($class, $registered);
            $this->assertArrayHasKey('inputSchema', app($class)->toArray());
            app('auth')->forgetGuards();
            $this->withSession(['nagare_user_id' => 'admin']);
            $this->assertStringContainsString('Authentication', (string) $this->callTool($tool)->content());
            foreach (['one', 'client', 'wrong', 'other'] as $id) {
                $this->login($id);
                $this->assertTrue($this->callTool($tool, ['actor_id' => 'admin'])->isError());
            }
        }
        $this->assertDatabaseCount('holidays', 0);
    }

    private function holiday(array $fields = []): array
    {
        return $this->data('CreateHolidayTool', $fields + ['name' => ' Diwali ', 'start_date' => '2026-11-08', 'end_date' => '2026-11-11']);
    }

    public function test_crud_partial_updates_and_authenticated_actor(): void
    {
        $this->login();
        $this->assertSame([], $this->data('ListHolidaysTool')['holidays']);
        $created = $this->holiday(['notes' => 'Festival', 'actor_id' => 'other']);
        $this->assertTrue($created['success']);
        $this->assertSame('admin', $created['actor']['id']);
        $id = $created['holiday']['id'];
        $this->assertSame('Diwali', $created['holiday']['name']);
        $earlier = $this->holiday(['name' => 'Earlier', 'start_date' => '2026-01-01', 'end_date' => '2026-01-01']);
        $this->assertNull($earlier['holiday']['notes']);
        $this->assertSame([$earlier['holiday']['id'], $id], array_column($this->data('ListHolidaysTool')['holidays'], 'id'));
        $updated = $this->data('UpdateHolidayTool', ['holiday_id' => $id, 'name' => 'Deepavali', 'start_date' => '2026-11-09'])['holiday'];
        $this->assertSame('Deepavali', $updated['name']);
        $this->assertSame('2026-11-09', $updated['start_date']);
        $this->assertSame('2026-11-11', $updated['end_date']);
        $this->assertSame('Festival', $updated['notes']);
        $this->assertNull($this->data('UpdateHolidayTool', ['holiday_id' => $id, 'notes' => null])['holiday']['notes']);
        $this->assertSame('2026-11-12', $this->data('UpdateHolidayTool', ['holiday_id' => $id, 'end_date' => '2026-11-12'])['holiday']['end_date']);
        $deleted = $this->data('DeleteHolidayTool', ['holiday_id' => $id]);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame($id, $deleted['holiday_id']);
        $this->assertDatabaseMissing('holidays', ['id' => $id]);
        $this->assertTrue($this->callTool('DeleteHolidayTool', ['holiday_id' => $id])->isError());
    }

    public function test_invalid_inputs_and_ids_do_not_mutate_holidays(): void
    {
        $this->login();
        $holiday = $this->holiday()['holiday'];
        foreach ([['name' => ' '], ['name' => str_repeat('x', 256)], ['name' => []], ['start_date' => '2026-02-30'],
            ['start_date' => 'tomorrow'], ['end_date' => '2026-11-07'], ['notes' => str_repeat('x', 2001)], ['notes' => []], ['start_date' => null]] as $invalid) {
            $args = $invalid + ['name' => 'Diwali', 'start_date' => '2026-11-08', 'end_date' => '2026-11-11'];
            $this->assertTrue($this->callTool('CreateHolidayTool', $args)->isError());
            $this->assertTrue($this->callTool('UpdateHolidayTool', $invalid + ['holiday_id' => $holiday['id']])->isError());
        }
        $this->assertTrue($this->callTool('CreateHolidayTool')->isError());
        $this->assertTrue($this->callTool('UpdateHolidayTool', ['holiday_id' => $holiday['id']])->isError());
        foreach (['UpdateHolidayTool', 'DeleteHolidayTool'] as $tool) {
            foreach ([null, '', [], str_repeat('x', 41), 'missing'] as $id) {
                $response = $this->callTool($tool, ['holiday_id' => $id, 'notes' => 'test']);
                $this->assertTrue($response->isError());
                $this->assertStringContainsString($id === 'missing' ? 'Holiday not found' : 'holiday_id', (string) $response->content());
            }
        }
        $this->assertSame([$holiday], $this->data('ListHolidaysTool')['holidays']);
    }

    public function test_service_recalculates_approved_leave_on_every_write(): void
    {
        $this->login();
        $leave = app(LeaveService::class);
        $request = $leave->submit('one', '2026-11-09', '2026-11-12', 'Personal');
        $leave->approve('admin', $request->id);
        $this->assertDatabaseHas('leave_requests', ['id' => $request->id, 'qualifying_days' => 4]);
        $id = $this->holiday()['holiday']['id'];
        $this->assertDatabaseHas('leave_requests', ['id' => $request->id, 'qualifying_days' => 1, 'paid_leave_days' => 1]);
        $this->data('UpdateHolidayTool', ['holiday_id' => $id, 'start_date' => '2026-11-10']);
        $this->assertDatabaseHas('leave_requests', ['id' => $request->id, 'qualifying_days' => 2, 'paid_leave_days' => 2]);
        $this->data('DeleteHolidayTool', ['holiday_id' => $id]);
        $this->assertDatabaseHas('leave_requests', ['id' => $request->id, 'qualifying_days' => 4, 'paid_leave_days' => 4]);
        $this->assertDatabaseCount('leave_request_days', 4);
    }

    public function test_mcp_transport_returns_structured_results_and_errors(): void
    {
        $this->login();
        $call = fn ($name, $args) => $this->postJson('/mcp/karya', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $args]]);
        $id = $call('create-holiday-tool', ['name' => 'Diwali', 'start_date' => '2026-11-08', 'end_date' => '2026-11-11'])
            ->assertOk()->assertJsonPath('result.structuredContent.success', true)->json('result.structuredContent.holiday.id');
        $call('list-holidays-tool', [])->assertOk()->assertJsonPath('result.structuredContent.holidays.0.id', $id);
        $call('update-holiday-tool', ['holiday_id' => $id, 'notes' => 'Festival'])->assertOk()->assertJsonPath('result.structuredContent.holiday.notes', 'Festival');
        $call('delete-holiday-tool', ['holiday_id' => $id])->assertOk()->assertJsonPath('result.structuredContent.deleted', true);
        $call('delete-holiday-tool', ['holiday_id' => $id])->assertOk()->assertJsonPath('result.isError', true);
        foreach ((new \ReflectionProperty(KaryaServer::class, 'tools'))->getDefaultValue() as $class) {
            $this->assertDoesNotMatchRegularExpression('/check.?in|check.?out/i', class_basename($class));
        }
    }
}
