<?php

namespace Tests\Feature;

use App\Services\AttendancePolicy;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedDatabase;

class AdminAttendanceTest extends TestCase
{
    private IsolatedDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) {
                (require $file)->up();
            }
        }
        foreach (['admin' => ['admin', 1], 'one' => ['team', 2], 'two' => ['team', 2], 'client' => ['client', 0], 'wrong' => ['admin', 2]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role, 'role_id' => $roleId, 'email' => $id.'@example.test', 'password' => 'unused']);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T19:00:00Z'));
        $this->withSession(['nagare_user_id' => 'admin']);
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow();
            $this->database->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function authorize(string $user = 'one', string $date = '2026-09-13')
    {
        return $this->postJson('/api/admin/attendance/overrides', ['user_id' => $user, 'work_date' => $date, 'notes' => 'Approved Sunday work']);
    }

    public function test_admin_listing_filters_and_default_ist_date_are_read_only(): void
    {
        DB::table('attendance_records')->insert(['id' => 'test', 'user_id' => 'one', 'attendance_date' => '2026-09-08',
            'status' => 'present', 'check_in_at' => '2026-09-08 10:00:00', 'check_out_at' => '2026-09-08 17:00:00',
            'is_late' => true, 'is_early_checkout' => true, 'worked_minutes' => 420, 'notes' => 'private', 'check_in_latitude' => 10]);
        $before = DB::table('attendance_records')->first();
        $response = $this->getJson('/api/admin/attendance')->assertOk()->assertJsonPath('attendance_date', '2026-09-08')
            ->assertJsonPath('timezone', 'Asia/Kolkata')->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.name', 'one')->assertJsonPath('rows.0.status', 'present')
            ->assertJsonPath('rows.0.is_late', true)->assertJsonPath('rows.0.is_early_checkout', true)
            ->assertJsonPath('rows.0.worked_minutes', 420)->assertJsonPath('rows.0.check_in_at', '2026-09-08T10:00:00+05:30')
            ->assertJsonPath('rows.1.status', 'not_checked_in');
        $this->assertArrayNotHasKey('check_in_latitude', $response->json('rows.0'));
        $this->assertArrayNotHasKey('notes', $response->json('rows.0'));
        $this->getJson('/api/admin/attendance?date=2026-09-09&user_id=two')->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.user_id', 'two')->assertJsonPath('rows.0.check_in_at', null);
        $this->assertEquals($before, DB::table('attendance_records')->first());
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_non_admins_cannot_list_create_or_revoke_even_with_admin_payload(): void
    {
        $id = $this->authorize()->assertCreated()->json('id');
        foreach (['' => 401, 'missing' => 401, 'one' => 403, 'client' => 403, 'wrong' => 403] as $actor => $status) {
            $this->withSession(['nagare_user_id' => $actor]);
            $this->getJson('/api/admin/attendance?user_id=two')->assertStatus($status);
            $this->postJson('/api/admin/attendance/overrides', ['user_id' => 'two', 'work_date' => '2026-09-13', 'actor_id' => 'admin'])->assertStatus($status);
            $this->deleteJson('/api/admin/attendance/overrides/'.$id, ['actor_id' => 'admin'])->assertStatus($status);
        }
        $this->assertDatabaseCount('attendance_working_day_overrides', 1);
        $this->assertDatabaseHas('attendance_working_day_overrides', ['id' => $id, 'revoked_at' => null]);
    }

    public function test_admin_override_is_scoped_and_revoke_retains_audit(): void
    {
        $id = $this->authorize()->assertCreated()->json('id');
        $this->assertDatabaseHas('attendance_working_day_overrides', ['id' => $id, 'user_id' => 'one', 'work_date' => '2026-09-13', 'approved_by' => 'admin']);
        $this->getJson('/api/admin/attendance?date=2026-09-13')->assertOk()
            ->assertJsonPath('rows.0.has_overtime_override', true)->assertJsonPath('rows.0.is_working_day', true)
            ->assertJsonPath('rows.0.can_revoke_override', true)->assertJsonPath('rows.1.has_overtime_override', false)
            ->assertJsonPath('rows.1.status', 'weekly_off')->assertJsonPath('rows.1.is_working_day', false);
        $policy = app(AttendancePolicy::class);
        $this->assertTrue($policy->isWorkingDay('one', '2026-09-13'));
        $this->assertFalse($policy->isWorkingDay('two', '2026-09-13'));
        $this->assertFalse($policy->isWorkingDay('one', '2026-09-20'));
        $this->deleteJson('/api/admin/attendance/overrides/'.$id, ['notes' => 'Cancelled'])->assertOk()->assertJsonPath('revoked', true);
        $this->assertDatabaseHas('attendance_working_day_overrides', ['id' => $id, 'revoked_by' => 'admin', 'revocation_note' => 'Cancelled']);
        $this->assertFalse($policy->isWorkingDay('one', '2026-09-13'));
        $this->getJson('/api/admin/attendance?date=2026-09-13&user_id=one')->assertJsonPath('rows.0.override.active', false)
            ->assertJsonPath('rows.0.status', 'weekly_off')->assertJsonPath('rows.0.can_authorize_override', false);
    }

    public function test_duplicates_repeated_revoke_and_normal_working_days_rejected(): void
    {
        $id = $this->authorize()->assertCreated()->json('id');
        $this->authorize()->assertConflict();
        $this->authorize('two', '2026-09-12')->assertCreated();
        $this->authorize('two', '2026-09-05')->assertUnprocessable();
        $this->deleteJson('/api/admin/attendance/overrides/'.$id)->assertOk();
        $this->deleteJson('/api/admin/attendance/overrides/'.$id)->assertConflict();
        $this->authorize()->assertConflict();
        $this->assertDatabaseCount('attendance_working_day_overrides', 2);
    }

    public function test_bad_dates_targets_and_inputs_are_rejected(): void
    {
        $this->getJson('/api/admin/attendance?date=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/admin/attendance?date=tomorrow')->assertUnprocessable();
        $this->postJson('/api/admin/attendance/overrides', [])->assertUnprocessable();
        $this->authorize('one', 'not-a-date')->assertUnprocessable();
        $this->authorize('admin')->assertForbidden();
        $this->authorize('client')->assertForbidden();
        $this->getJson('/api/admin/attendance?user_id=client')->assertForbidden();
        $this->deleteJson('/api/admin/attendance/overrides/missing')->assertNotFound();
        $this->assertDatabaseCount('attendance_working_day_overrides', 0);
    }

    public function test_approved_leave_display_and_current_admin_role_check(): void
    {
        $leave = app(LeaveService::class);
        $leave->approve('admin', $leave->submit('one', '2026-09-08', '2026-09-08', 'Leave')->id);
        config(['attendance.annual_paid_leave_days' => 0]);
        $leave->approve('admin', $leave->submit('two', '2026-09-08', '2026-09-08', 'Leave')->id);
        $this->getJson('/api/admin/attendance')->assertJsonPath('rows.0.status', 'paid_leave')->assertJsonPath('rows.1.status', 'unpaid_leave');
        DB::table('users')->where('id', 'admin')->update(['role' => 'team', 'role_id' => 2]);
        $this->getJson('/api/admin/attendance')->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_overtime_routes_keep_csrf_protection(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->authorize()->assertStatus(419);
        $this->deleteJson('/api/admin/attendance/overrides/anything')->assertStatus(419);
        $this->assertDatabaseCount('attendance_working_day_overrides', 0);
    }
}
