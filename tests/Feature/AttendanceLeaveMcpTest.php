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

class AttendanceLeaveMcpTest extends TestCase
{
    private IsolatedDatabase $database;
    private mixed $allowlist;
    private const TOOLS = ['ListAttendanceTool', 'AuthorizeOvertimeTool', 'RevokeOvertimeTool', 'ListLeaveRequestsTool', 'ReviewLeaveRequestTool', 'GetLeaveBalanceTool'];

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

    public function test_tools_are_registered_and_require_allowed_passport_admin_before_validation(): void
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
                $this->assertTrue($this->callTool($tool)->isError());
            }
        }
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('attendance_working_day_overrides', 0);
    }

    public function test_name_resolution_is_case_insensitive_scoped_and_never_guesses(): void
    {
        $this->login();
        $this->assertSame('one', $this->data('ListAttendanceTool', ['employee_name' => ' ALICE rao '])['rows'][0]['user_id']);
        foreach (['Missing', 'Admin', 'Client', 'Wrong'] as $name) {
            foreach (['ListAttendanceTool', 'AuthorizeOvertimeTool', 'GetLeaveBalanceTool'] as $tool) {
                $response = $this->callTool($tool, ['employee_name' => $name, 'work_date' => '2026-09-13']);
                $this->assertTrue($response->isError());
                $this->assertStringContainsString('No team employee', (string) $response->content());
            }
        }
        DB::table('users')->where('id', 'two')->update(['name' => 'ALICE RAO']);
        foreach (['ListAttendanceTool', 'AuthorizeOvertimeTool', 'GetLeaveBalanceTool'] as $tool) {
            $response = $this->callTool($tool, ['employee_name' => 'Alice Rao', 'work_date' => '2026-09-13']);
            $this->assertTrue($response->isError());
            $this->assertStringContainsString('Ambiguous employee_name', (string) $response->content());
        }
        $this->assertDatabaseCount('attendance_working_day_overrides', 0);
    }

    public function test_attendance_reads_and_overtime_writes_use_authenticated_actor_and_service_rules(): void
    {
        $this->login();
        $listing = $this->data('ListAttendanceTool');
        $this->assertSame('2026-09-08', $listing['attendance_date']);
        $this->assertCount(2, $listing['rows']);
        foreach (['status', 'check_in_at', 'check_out_at', 'is_late', 'is_early_checkout', 'worked_minutes', 'is_weekly_off', 'approved_leave_type', 'has_overtime_override', 'override'] as $key) $this->assertArrayHasKey($key, $listing['rows'][0]);
        $this->assertTrue($this->callTool('AuthorizeOvertimeTool', ['employee_name' => 'Alice Rao', 'work_date' => '2026-09-08'])->isError());
        $args = ['employee_name' => 'alice rao', 'work_date' => '2026-09-13', 'notes' => 'Sunday work', 'actor_id' => 'other'];
        $override = $this->data('AuthorizeOvertimeTool', $args)['override'];
        $this->assertSame('admin', $override['approved_by']);
        $this->assertSame('one', $override['user_id']);
        $this->assertTrue($this->callTool('AuthorizeOvertimeTool', $args)->isError());
        $row = $this->data('ListAttendanceTool', ['date' => '2026-09-13', 'employee_name' => 'Alice Rao'])['rows'][0];
        $this->assertTrue($row['has_overtime_override']);
        $this->assertSame($override['id'], $row['override']['id']);
        $this->assertTrue($this->data('RevokeOvertimeTool', ['override_id' => $override['id'], 'note' => 'Cancelled'])['revoked']);
        $this->assertDatabaseHas('attendance_working_day_overrides', ['id' => $override['id'], 'revoked_by' => 'admin', 'revocation_note' => 'Cancelled', 'notes' => 'Sunday work']);
        $this->assertTrue($this->callTool('RevokeOvertimeTool', ['override_id' => $override['id']])->isError());
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_leave_reads_approval_rejection_and_balance_use_existing_services(): void
    {
        $this->login();
        $leave = app(LeaveService::class);
        $first = $leave->submit('one', '2026-09-08', '2026-09-08', 'Appointment');
        $second = $leave->submit('one', '2026-09-09', '2026-09-09', 'Personal');
        $this->assertCount(2, $this->data('ListLeaveRequestsTool', ['status' => 'pending'])['requests']);
        $approved = $this->data('ReviewLeaveRequestTool', ['request_id' => $first->id, 'action' => 'approve', 'note' => 'Approved note'])['request'];
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('admin', $approved['reviewed_by']);
        $this->assertSame('Approved note', $approved['review_note']);
        $this->assertSame('rejected', $this->data('ReviewLeaveRequestTool', ['request_id' => $second->id, 'action' => 'reject', 'note' => 'Rejected note'])['request']['status']);
        $this->assertCount(1, $this->data('ListLeaveRequestsTool', ['status' => 'approved'])['requests']);
        $this->assertCount(1, $this->data('ListLeaveRequestsTool', ['status' => 'rejected'])['requests']);
        $this->assertCount(2, $this->data('ListLeaveRequestsTool')['requests']);
        $balance = $this->data('GetLeaveBalanceTool', ['employee_name' => 'ALICE RAO']);
        $this->assertSame(2026, $balance['year']);
        $this->assertSame(1, $balance['paid_used']);
        $this->assertSame(0, $this->data('GetLeaveBalanceTool', ['employee_name' => 'Alice Rao', 'year' => 2027])['paid_used']);
        $this->assertSame('paid', $this->data('ListAttendanceTool', ['date' => '2026-09-08', 'employee_name' => 'Alice Rao'])['rows'][0]['approved_leave_type']);
        $this->assertTrue($this->callTool('ReviewLeaveRequestTool', ['request_id' => $first->id, 'action' => 'reject'])->isError());
    }

    public function test_invalid_inputs_and_unknown_records_return_errors(): void
    {
        $this->login();
        foreach ([['ListAttendanceTool', ['date' => '2026-02-30']], ['ListAttendanceTool', ['employee_name' => ' ']],
            ['AuthorizeOvertimeTool', ['employee_name' => 'Alice Rao', 'work_date' => 'tomorrow']],
            ['RevokeOvertimeTool', ['override_id' => 'missing']], ['ListLeaveRequestsTool', ['status' => 'invalid']],
            ['ReviewLeaveRequestTool', ['request_id' => 'missing', 'action' => 'approve']],
            ['ReviewLeaveRequestTool', ['request_id' => 'missing', 'action' => 'invalid']],
            ['GetLeaveBalanceTool', ['employee_name' => 'Alice Rao', 'year' => 999]]] as [$tool, $args]) {
            $this->assertTrue($this->callTool($tool, $args)->isError(), $tool);
        }
    }

    public function test_mcp_transport_exposes_admin_tools_but_no_employee_clock_actions(): void
    {
        $this->login();
        $this->postJson('/mcp/karya', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list-attendance-tool', 'arguments' => ['employee_name' => 'alice rao']]])
            ->assertOk()->assertJsonPath('result.structuredContent.rows.0.user_id', 'one');
        foreach ((new \ReflectionProperty(KaryaServer::class, 'tools'))->getDefaultValue() as $class) {
            $this->assertDoesNotMatchRegularExpression('/check.?in|check.?out/i', class_basename($class));
        }
    }
}
