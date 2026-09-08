<?php

namespace Tests\Feature;

use App\Services\AttendanceOverrideService;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\IsolatedDatabase;

class AttendanceApiTest extends TestCase
{
    private IsolatedDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        // Synthetic test location only; these are not real office coordinates.
        config(['attendance.geofence' => ['office_latitude' => 0, 'office_longitude' => 0,
            'radius_metres' => 100, 'max_accuracy_metres' => 50]]);
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) {
                (require $file)->up();
            }
        }
        foreach (['admin' => ['admin', 1], 'one' => ['team', 2], 'two' => ['team', 2],
            'client' => ['client', 0], 'wrong_role' => ['team', 1]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role, 'role_id' => $roleId,
                'email' => $id.'@example.test', 'password' => 'unused']);
        }
        $this->at('2026-09-08 09:30:00');
        $this->withSession(['nagare_user_id' => 'one']);
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

    private function at(string $time): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'Asia/Kolkata'));
    }

    private function postAttendance(string $uri, array $data = [], array $headers = [])
    {
        return $this->postJson($uri, $data + ['latitude' => 0, 'longitude' => 0, 'accuracy' => 10], $headers);
    }

    public function test_all_endpoints_require_a_current_team_session(): void
    {
        foreach ([null => 401, 'missing' => 401, 'admin' => 403, 'client' => 403, 'wrong_role' => 403] as $id => $status) {
            $this->withSession(['nagare_user_id' => $id]);
            $this->getJson('/api/attendance/today')->assertStatus($status)->assertJsonStructure(['message']);
            $this->postAttendance('/api/attendance/check-in')->assertStatus($status);
            $this->postAttendance('/api/attendance/check-out')->assertStatus($status);
        }
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_today_is_read_only_and_full_check_in_out_flow(): void
    {
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('can_check_in', true)
            ->assertJsonPath('can_check_out', false)->assertJsonPath('attendance', null)
            ->assertJsonPath('attendance_date', '2026-09-08')->assertJsonPath('timezone', 'Asia/Kolkata');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->postAttendance('/api/attendance/check-in')->assertCreated()->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('can_check_in', false)->assertJsonPath('can_check_out', true)
            ->assertJsonPath('attendance.worked_minutes', null)->assertJsonPath('attendance.check_out_at', null);
        $this->getJson('/api/attendance/today')->assertJsonPath('can_check_out', true);
        $this->at('2026-09-08 18:30:00');
        $this->postAttendance('/api/attendance/check-out')->assertOk()->assertJsonPath('attendance.worked_minutes', 540)
            ->assertJsonPath('attendance.is_early_checkout', false)->assertJsonPath('can_check_in', false)
            ->assertJsonPath('can_check_out', false);
        $before = DB::table('attendance_records')->first();
        $response = $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('can_check_out', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEquals($before, DB::table('attendance_records')->first());
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_client_fields_are_ignored_on_both_operations(): void
    {
        $payload = ['user_id' => 'two', 'attendance_date' => '2020-01-01', 'date' => '2020-01-01',
            'check_in_at' => '2026-09-08 00:00:00', 'check_out_at' => '2026-09-08 23:59:59',
            'timestamp' => '2020-01-01', 'status' => 'absent', 'is_late' => false,
            'is_early_checkout' => false, 'worked_minutes' => 9999,
            'check_in_latitude' => 20, 'check_in_longitude' => 70, 'check_in_accuracy' => 1,
            'check_out_latitude' => 20, 'check_out_longitude' => 70, 'check_out_accuracy' => 1];
        $this->at('2026-09-08 10:30:00');
        $this->postAttendance('/api/attendance/check-in', $payload)->assertCreated()
            ->assertJsonPath('attendance.check_in_at', '2026-09-08T10:30:00+05:30')
            ->assertJsonPath('attendance.is_late', true)->assertJsonPath('attendance.status', 'present');
        $this->withSession(['nagare_user_id' => 'two'])->postAttendance('/api/attendance/check-out', ['user_id' => 'one'])->assertConflict();
        $this->withSession(['nagare_user_id' => 'one']);
        $this->at('2026-09-08 17:55:00');
        $this->postAttendance('/api/attendance/check-out', $payload)->assertOk()
            ->assertJsonPath('attendance.check_out_at', '2026-09-08T17:55:00+05:30')
            ->assertJsonPath('attendance.is_early_checkout', true)->assertJsonPath('attendance.worked_minutes', 445);
        $row = DB::table('attendance_records')->first();
        $this->assertSame('one', $row->user_id);
        foreach (['check_in', 'check_out'] as $prefix) {
            foreach (['latitude', 'longitude', 'accuracy'] as $field) {
                $this->assertEquals($field === 'accuracy' ? 10 : 0, $row->{$prefix.'_'.$field});
            }
        }
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_check_in_boundaries_and_late_arrivals_are_present(): void
    {
        foreach (['09:20:00' => false, '09:30:00' => false, '09:45:00' => false, '09:45:01' => true, '10:30:00' => true] as $time => $late) {
            $this->at('2026-09-08 '.$time);
            $this->postAttendance('/api/attendance/check-in')->assertCreated()->assertJsonPath('attendance.is_late', $late)
                ->assertJsonPath('attendance.status', 'present');
            DB::table('attendance_records')->delete();
        }
    }

    public function test_duplicates_do_not_overwrite_original_times(): void
    {
        $this->postAttendance('/api/attendance/check-in')->assertCreated();
        $this->at('2026-09-08 10:00:00');
        $this->postAttendance('/api/attendance/check-in')->assertConflict()->assertExactJson(['message' => 'You have already checked in today.']);
        $this->assertDatabaseHas('attendance_records', ['check_in_at' => '2026-09-08 09:30:00']);
        $this->postAttendance('/api/attendance/check-out')->assertOk();
        $this->at('2026-09-08 11:00:00');
        $this->postAttendance('/api/attendance/check-out')->assertConflict()->assertExactJson(['message' => 'You have already checked out today.']);
        $this->postAttendance('/api/attendance/check-in')->assertConflict();
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => '2026-09-08 10:00:00']);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_weekly_off_and_normal_saturday(): void
    {
        foreach (['2026-09-12', '2026-09-13'] as $date) {
            $this->at($date.' 09:30:00');
            $this->getJson('/api/attendance/today')->assertJsonPath('day_type', 'weekly_off')
                ->assertJsonPath('can_check_in', false)->assertJsonPath('can_check_out', false);
            $this->postAttendance('/api/attendance/check-in')->assertConflict()->assertExactJson(['message' => 'Office Closed – Weekly Off']);
        }
        $this->assertDatabaseCount('attendance_records', 0);
        $this->at('2026-09-05 09:30:00');
        $this->postAttendance('/api/attendance/check-in')->assertCreated();
    }

    public function test_overtime_is_employee_specific_and_uses_normal_flags(): void
    {
        $this->at('2026-09-13 10:30:00');
        app(AttendanceOverrideService::class)->authorize('admin', 'one', '2026-09-13');
        $this->getJson('/api/attendance/today')->assertJsonPath('day_type', 'overtime')->assertJsonPath('is_weekly_off', true)
            ->assertJsonPath('is_working_day', true)->assertJsonPath('has_overtime_override', true)->assertJsonPath('can_check_in', true);
        $this->postAttendance('/api/attendance/check-in')->assertCreated()->assertJsonPath('attendance.is_late', true);
        $this->at('2026-09-13 17:55:00');
        $this->postAttendance('/api/attendance/check-out')->assertOk()->assertJsonPath('attendance.is_early_checkout', true);
        $this->withSession(['nagare_user_id' => 'two'])->getJson('/api/attendance/today')->assertJsonPath('can_check_in', false);
        $this->postAttendance('/api/attendance/check-in')->assertConflict();
    }

    public function test_revoked_override_disables_weekly_off_attendance(): void
    {
        $this->at('2026-09-13 10:00:00');
        $service = app(AttendanceOverrideService::class);
        $override = $service->authorize('admin', 'one', '2026-09-13');
        $this->postAttendance('/api/attendance/check-in')->assertCreated();
        $service->revoke('admin', $override->id);
        $this->getJson('/api/attendance/today')->assertJsonPath('can_check_out', false);
        $this->postAttendance('/api/attendance/check-out')->assertConflict();
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => null]);
    }

    public function test_approved_paid_and_unpaid_leave_block_check_in(): void
    {
        $leave = app(LeaveService::class);
        $request = $leave->submit('one', '2026-09-08', '2026-09-08', 'Leave');
        $leave->approve('admin', $request->id);
        $this->getJson('/api/attendance/today')->assertJsonPath('can_check_in', false)->assertJsonPath('approved_leave_type', 'paid');
        $this->postAttendance('/api/attendance/check-in')->assertConflict()->assertExactJson(['message' => 'Approved Paid Leave']);
        config(['attendance.annual_paid_leave_days' => 0]);
        $request = $leave->submit('two', '2026-09-08', '2026-09-08', 'Leave');
        $leave->approve('admin', $request->id);
        $this->withSession(['nagare_user_id' => 'two'])->getJson('/api/attendance/today')->assertJsonPath('approved_leave_type', 'unpaid');
        $this->postAttendance('/api/attendance/check-in')->assertConflict()->assertExactJson(['message' => 'Approved Unpaid Leave']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_pending_and_rejected_leave_do_not_block_attendance(): void
    {
        $leave = app(LeaveService::class);
        foreach (['one', 'two'] as $employee) {
            $request = $leave->submit($employee, '2026-09-08', '2026-09-08', 'Leave');
            if ($employee === 'two') {
                $leave->reject('admin', $request->id);
            }
            $this->withSession(['nagare_user_id' => $employee])->postAttendance('/api/attendance/check-in')->assertCreated();
        }
    }

    public function test_leave_approved_after_check_in_does_not_prevent_checkout(): void
    {
        $this->postAttendance('/api/attendance/check-in')->assertCreated();
        $leave = app(LeaveService::class);
        $leave->approve('admin', $leave->submit('one', '2026-09-08', '2026-09-08', 'Leave')->id);
        $this->getJson('/api/attendance/today')->assertJsonPath('can_check_out', true);
        $this->postAttendance('/api/attendance/check-out')->assertOk();
    }

    public function test_early_checkout_and_elapsed_minutes_boundaries(): void
    {
        foreach (['17:55:00' => [true, 495], '18:29:59' => [true, 529], '18:30:00' => [false, 530], '18:35:00' => [false, 535], '20:00:00' => [false, 620]] as $time => [$early, $minutes]) {
            $this->at('2026-09-08 09:40:00');
            $this->postAttendance('/api/attendance/check-in')->assertCreated();
            $this->at('2026-09-08 '.$time);
            $this->postAttendance('/api/attendance/check-out')->assertOk()->assertJsonPath('attendance.is_early_checkout', $early)
                ->assertJsonPath('attendance.worked_minutes', $minutes);
            DB::table('attendance_records')->delete();
        }
    }

    public function test_checkout_without_checkin_and_historical_rows_are_untouched(): void
    {
        $this->postAttendance('/api/attendance/check-out')->assertConflict();
        $this->assertDatabaseCount('attendance_records', 0);
        $this->postAttendance('/api/attendance/check-in')->assertCreated();
        $before = DB::table('attendance_records')->first();
        $this->at('2026-09-09 09:00:00');
        $this->postAttendance('/api/attendance/check-out', ['attendance_date' => '2026-09-08'])->assertConflict();
        $this->getJson('/api/attendance/today')->assertJsonPath('attendance', null)->assertJsonPath('can_check_in', true);
        $this->assertEquals($before, DB::table('attendance_records')->first());
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_non_present_record_is_not_overwritten_or_checked_out(): void
    {
        DB::table('attendance_records')->insert(['id' => 'existing', 'user_id' => 'one', 'attendance_date' => '2026-09-08', 'status' => 'absent']);
        $this->getJson('/api/attendance/today')->assertJsonPath('can_check_in', false)->assertJsonPath('can_check_out', false);
        $this->postAttendance('/api/attendance/check-in')->assertConflict();
        $this->postAttendance('/api/attendance/check-out')->assertConflict();
        $this->assertDatabaseHas('attendance_records', ['status' => 'absent', 'check_in_at' => null]);
    }

    public function test_timezone_midnight_and_clock_rollback_are_safe(): void
    {
        $previous = date_default_timezone_get();
        config(['app.timezone' => 'America/Los_Angeles']);
        date_default_timezone_set('UTC');
        try {
            $this->at('2026-09-07T19:00:00Z');
            $this->postAttendance('/api/attendance/check-in')->assertCreated()->assertJsonPath('attendance_date', '2026-09-08')
                ->assertJsonPath('attendance.check_in_at', '2026-09-08T00:30:00+05:30')->assertJsonPath('attendance.is_late', false);
            $this->at('2026-09-07T18:59:00Z');
            $this->postAttendance('/api/attendance/check-out')->assertConflict();
            $this->at('2026-09-08T13:00:00Z');
            $this->postAttendance('/api/attendance/check-out')->assertOk()->assertJsonPath('attendance.is_early_checkout', false)
                ->assertJsonPath('attendance.worked_minutes', 1080);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_csrf_is_enforced_when_test_bypass_is_disabled(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postAttendance('/api/attendance/check-in')->assertStatus(419);
        $this->postAttendance('/api/attendance/check-out')->assertStatus(419);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->withSession(['_token' => 'attendance-test-csrf'])->postAttendance('/api/attendance/check-in', [], ['X-CSRF-TOKEN' => 'attendance-test-csrf'])->assertCreated();
    }

    public function test_simultaneous_submissions_are_clean_conflicts_and_only_write_once(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Subprocess fixture requires isolated SQLite.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'kat');
        $path = dirname($temporary).DIRECTORY_SEPARATOR.'karya-attendance-api-'.basename($temporary);
        rename($temporary, $path);
        $signal = $path.'.start';
        $processes = [];
        try {
            DB::statement('VACUUM INTO ?', [$path]);
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
            foreach (['checkIn', 'checkOut'] as $operation) {
                if (is_file($signal)) {
                    unlink($signal);
                }
                $processes = [];
                for ($i = 0; $i < 2; $i++) {
                    $process = new Process([PHP_BINARY, '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3',
                        base_path('tests/attendance-api-worker.php'), $path, $signal, $operation], base_path(), ['APP_ENV' => 'testing']);
                    $process->setTimeout(20);
                    $process->start();
                    $processes[] = $process;
                }
                $deadline = microtime(true) + 10;
                do {
                    $ready = count(array_filter($processes, fn ($p) => str_contains($p->getOutput(), 'ready')));
                    if ($ready === 2) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame(2, $ready, implode('\n', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
                touch($signal);
                $codes = array_map(fn ($p) => $p->wait(), $processes);
                sort($codes);
                $this->assertSame([0, 9], $codes, implode('\n', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
            }
            $this->assertDatabaseCount('attendance_records', 1);
            $this->assertDatabaseHas('attendance_records', ['check_in_at' => '2026-09-08 09:30:00', 'check_out_at' => '2026-09-08 18:30:00', 'worked_minutes' => 540]);
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach ([$signal, $path, $path.'-journal', $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
