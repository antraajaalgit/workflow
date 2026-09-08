<?php

namespace Tests\Feature;

use App\Services\AttendanceAccess;
use App\Services\AttendanceOverrideService;
use App\Services\AttendancePolicy;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\IsolatedDatabase;

class AttendanceFoundationTest extends TestCase
{
    private IsolatedDatabase $database;

    private AttendancePolicy $policy;

    private LeaveService $leave;

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
        foreach (['admin' => ['admin', 1], 'one' => ['team', 2], 'two' => ['team', 2],
            'client' => ['client', 0], 'fake_admin' => ['admin', 2]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role, 'role_id' => $roleId,
                'email' => $id.'@example.test', 'password' => 'unused']);
        }
        $this->policy = app(AttendancePolicy::class);
        $this->leave = app(LeaveService::class);
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

    private function denied(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected authorization or conflict rejection.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function approved(string $start, string $end): object
    {
        return $this->leave->approve('admin', $this->leave->submit('one', $start, $end, 'Leave')->id);
    }

    public function test_ist_is_explicit_even_with_a_different_application_and_php_timezone(): void
    {
        $this->assertSame('Asia/Kolkata', config('attendance.timezone'));
        config(['app.timezone' => 'America/New_York']);
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12T19:00:00Z'));
            $this->assertSame('2026-09-13', $this->policy->today());
            $this->assertTrue($this->policy->isSunday($this->policy->today()));
            $this->assertSame('2027-01-01', $this->policy->normalize('2026-12-31T19:00:00Z')->toDateString());
            $this->assertSame('09:30 +05:30', $this->policy->shiftStart('2026-09-08')->format('H:i P'));
            $this->assertSame('18:30 +05:30', $this->policy->shiftEnd('2026-09-08')->format('H:i P'));
            $this->assertSame('09:45 +05:30', $this->policy->graceCutoff('2026-09-08')->format('H:i P'));
            $this->assertFalse($this->policy->isLate('2026-09-08T04:15:00Z'));
            $this->assertTrue($this->policy->isLate('2026-09-08T04:16:00Z'));
            $this->assertFalse($this->policy->isLate('2026-09-08 09:45:00'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_on_time_and_late_boundaries(): void
    {
        foreach (['09:29', '09:30', '09:40', '09:45'] as $time) {
            $this->assertFalse($this->policy->isLate('2026-09-08 '.$time));
        }
        foreach (['09:45:01', '09:46', '10:30', '15:00'] as $time) {
            $this->assertTrue($this->policy->isLate('2026-09-08 '.$time));
        }
        config(['attendance.grace_minutes' => 20, 'attendance.shift_start' => '10:00']);
        $this->assertFalse($this->policy->isLate('2026-09-08 10:20'));
        $this->assertTrue($this->policy->isLate('2026-09-08 10:21'));
    }

    public function test_weekly_off_calendar_occurrences_across_every_month(): void
    {
        foreach ([2026, 2027, 2028] as $year) {
            for ($month = 1; $month <= 12; $month++) {
                $saturdays = 0;
                $day = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Asia/Kolkata');
                while ($day->month === $month) {
                    if ($day->isSaturday()) {
                        $saturdays++;
                    }
                    $expected = $day->isSunday() || ($day->isSaturday() && $saturdays === 2);
                    $this->assertSame($expected, $this->policy->isWeeklyOff($day->toDateString()), $day->toDateString());
                    $day = $day->addDay();
                }
            }
        }
    }

    public function test_employee_override_is_scoped_audited_and_revocable(): void
    {
        $service = app(AttendanceOverrideService::class);
        $override = $service->authorize('admin', 'one', '2026-09-13', 'Sunday work');
        $this->assertSame('admin', $override->approved_by);
        $this->assertTrue($this->policy->isWorkingDay('one', '2026-09-13'));
        $this->assertFalse($this->policy->isWorkingDay('two', '2026-09-13'));
        $this->assertFalse($this->policy->isWorkingDay('one', '2026-09-20'));
        $this->assertSame([], $this->policy->qualifyingDates('2026-09-13', '2026-09-13'));
        $this->denied(fn () => $service->authorize('admin', 'one', '2026-09-13'), 409);
        $service->revoke('admin', $override->id, 'Cancelled');
        $this->assertFalse($this->policy->isWorkingDay('one', '2026-09-13'));
        $this->assertDatabaseHas('attendance_working_day_overrides', ['id' => $override->id,
            'approved_by' => 'admin', 'revoked_by' => 'admin', 'revocation_note' => 'Cancelled']);
        $this->denied(fn () => $service->revoke('admin', $override->id), 409);
    }

    public function test_override_database_unique_constraint(): void
    {
        $override = (array) app(AttendanceOverrideService::class)->authorize('admin', 'one', '2026-09-12');
        $override['id'] = 'duplicate';
        $this->expectException(QueryException::class);
        DB::table('attendance_working_day_overrides')->insert($override);
    }

    public function test_late_present_record_location_precision_and_unique_constraint(): void
    {
        $record = ['id' => 'attendance_one', 'user_id' => 'one', 'attendance_date' => '2026-09-08',
            'check_in_at' => '2026-09-08 10:30:00', 'status' => 'present', 'is_late' => true,
            'check_in_latitude' => 30.7333148, 'check_in_longitude' => 76.7794179, 'check_in_accuracy' => 8.125,
            'check_out_latitude' => -30.7333148, 'check_out_longitude' => -176.7794179, 'check_out_accuracy' => 12.375];
        DB::table('attendance_records')->insert($record);
        $row = DB::table('attendance_records')->first();
        $this->assertSame('present', $row->status);
        $this->assertTrue((bool) $row->is_late);
        foreach (['check_in_latitude', 'check_in_longitude', 'check_in_accuracy', 'check_out_latitude', 'check_out_longitude', 'check_out_accuracy'] as $field) {
            $this->assertEqualsWithDelta($record[$field], (float) $row->$field, 0.00000001);
        }
        $record['id'] = 'duplicate';
        $this->expectException(QueryException::class);
        DB::table('attendance_records')->insert($record);
    }

    public function test_pending_and_rejected_do_not_consume_entitlement(): void
    {
        $this->assertSame(['year' => 2026, 'entitlement' => 12, 'paid_used' => 0, 'paid_remaining' => 12, 'unpaid_used' => 0],
            $this->leave->balance('one', 'one', 2026));
        $pending = $this->leave->submit('one', '2026-09-01', '2026-09-05', 'Holiday');
        $this->assertSame(0, (int) $pending->paid_leave_days);
        $this->assertSame(12, $this->leave->balance('one', 'one', 2026)['paid_remaining']);
        $rejected = $this->leave->reject('admin', $pending->id, 'Busy week');
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('admin', $rejected->reviewed_by);
        $this->assertNotNull($rejected->reviewed_at);
        $this->assertSame(12, $this->leave->balance('one', 'one', 2026)['paid_remaining']);
        $this->assertDatabaseCount('leave_request_days', 0);
    }

    public function test_seven_used_then_three_approved_are_all_paid(): void
    {
        $this->approved('2026-09-01', '2026-09-08');
        $this->assertSame(5, $this->leave->balance('one', 'one', 2026)['paid_remaining']);
        $approved = $this->approved('2026-09-14', '2026-09-16');
        $this->assertSame(3, (int) $approved->paid_leave_days);
        $this->assertSame(0, (int) $approved->unpaid_leave_days);
        $this->assertSame(2, $this->leave->balance('one', 'one', 2026)['paid_remaining']);
    }

    public function test_mixed_allocation_persists_and_exhausted_leave_is_unpaid(): void
    {
        $this->approved('2026-09-01', '2026-09-08');
        $approved = $this->approved('2026-09-14', '2026-09-21');
        $this->assertSame(7, (int) $approved->qualifying_days);
        $this->assertSame(5, (int) $approved->paid_leave_days);
        $this->assertSame(2, (int) $approved->unpaid_leave_days);
        $this->assertSame(5, DB::table('leave_request_days')->where('leave_request_id', $approved->id)->where('type', 'paid')->count());
        $this->assertSame(2, DB::table('leave_request_days')->where('leave_request_id', $approved->id)->where('type', 'unpaid')->count());
        $extra = $this->approved('2026-09-22', '2026-09-22');
        $this->assertSame(0, (int) $extra->paid_leave_days);
        $this->assertSame(1, (int) $extra->unpaid_leave_days);
        $this->assertSame(['year' => 2026, 'entitlement' => 12, 'paid_used' => 12, 'paid_remaining' => 0, 'unpaid_used' => 3],
            $this->leave->balance('admin', 'one', 2026));
        $this->assertSame(12, $this->leave->balance('two', 'two', 2026)['paid_remaining']);
    }

    public function test_leave_ranges_exclude_only_normal_weekly_offs(): void
    {
        $this->assertSame(['2026-09-04', '2026-09-05', '2026-09-07'], $this->policy->qualifyingDates('2026-09-04', '2026-09-07'));
        $this->assertSame(['2026-09-11', '2026-09-14'], $this->policy->qualifyingDates('2026-09-11', '2026-09-14'));
        $this->assertSame(['2026-08-31', '2026-09-01'], $this->policy->qualifyingDates('2026-08-30', '2026-09-01'));
        app(AttendanceOverrideService::class)->authorize('admin', 'one', '2026-09-12');
        $approved = $this->approved('2026-09-11', '2026-09-14');
        $this->assertSame(2, (int) $approved->qualifying_days);
        $offOnly = $this->approved('2026-09-20', '2026-09-20');
        $this->assertSame(0, (int) $offOnly->paid_leave_days);
    }

    public function test_cross_year_allocation_uses_separate_calendar_entitlements(): void
    {
        $this->approved('2026-09-01', '2026-09-15'); // Twelve qualifying days.
        $this->assertSame(12, $this->leave->balance('one', 'one', 2026)['paid_used']);
        $mixed = $this->approved('2026-12-31', '2027-01-04');
        $this->assertSame(3, (int) $mixed->paid_leave_days);
        $this->assertSame(1, (int) $mixed->unpaid_leave_days);
        $this->assertSame(1, $this->leave->balance('one', 'one', 2026)['unpaid_used']);
        $this->assertSame(3, $this->leave->balance('one', 'one', 2027)['paid_used']);
        $this->assertSame(9, $this->leave->balance('one', 'one', 2027)['paid_remaining']);
        $this->assertDatabaseHas('leave_request_days', ['leave_request_id' => $mixed->id, 'leave_date' => '2026-12-31', 'type' => 'unpaid']);
        $this->assertDatabaseHas('leave_request_days', ['leave_request_id' => $mixed->id, 'leave_date' => '2027-01-01', 'type' => 'paid']);
    }

    public function test_invalid_dates_ranges_and_empty_reason_are_rejected(): void
    {
        foreach ([['2026-09-08', '2026-09-07', 'Leave'], ['2026-02-30', '2026-03-01', 'Leave'],
            ['tomorrow', '2026-09-09', 'Leave'], ['2026-09-08', '2026-09-09', ' ']] as [$start, $end, $reason]) {
            try {
                $this->leave->submit('one', $start, $end, $reason);
                $this->fail('Invalid request accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('leave_requests', 0);
            }
        }
    }

    public function test_authorization_uses_current_roles_and_prevents_cross_employee_access(): void
    {
        $pending = $this->leave->submit('one', '2026-09-01', '2026-09-02', 'Leave');
        $access = app(AttendanceAccess::class);
        foreach (['one', 'two', 'client', 'fake_admin'] as $actor) {
            $this->denied(fn () => $this->leave->approve($actor, $pending->id), 403);
            $this->denied(fn () => $this->leave->reject($actor, $pending->id), 403);
            $this->denied(fn () => app(AttendanceOverrideService::class)->authorize($actor, 'one', '2026-09-13'), 403);
        }
        $this->denied(fn () => $this->leave->submit('client', '2026-09-01', '2026-09-02', 'Leave'), 403);
        $this->denied(fn () => $this->leave->balance('two', 'one', 2026), 403);
        $this->denied(fn () => $access->selfAttendance('two', 'one'), 403);
        $this->denied(fn () => $access->admin('admin', 'admin'), 403);
        $this->denied(fn () => $access->view('missing', 'one'), 401);
        $access->selfAttendance('one', 'one');
        $access->view('admin', 'one');
        DB::table('users')->where('id', 'admin')->update(['role' => 'team', 'role_id' => 2]);
        $this->denied(fn () => $this->leave->approve('admin', $pending->id), 403);
        $this->assertDatabaseCount('leave_request_days', 0);
    }

    public function test_repeated_and_overlapping_approvals_cannot_double_consume(): void
    {
        $approved = $this->approved('2026-09-01', '2026-09-08');
        $this->denied(fn () => $this->leave->approve('admin', $approved->id), 409);
        $this->denied(fn () => $this->leave->reject('admin', $approved->id), 409);
        $overlap = $this->leave->submit('one', '2026-09-08', '2026-09-09', 'Overlap');
        $this->denied(fn () => $this->leave->approve('admin', $overlap->id), 409);
        $this->assertSame(7, $this->leave->balance('one', 'one', 2026)['paid_used']);
        $this->assertDatabaseHas('leave_requests', ['id' => $overlap->id, 'status' => 'pending']);
    }

    public function test_approval_owns_transaction_and_rolls_back_partial_allocations(): void
    {
        $pending = $this->leave->submit('one', '2026-09-01', '2026-09-03', 'Leave');
        DB::transaction(function () use ($pending) {
            $this->denied(fn () => $this->leave->approve('admin', $pending->id), 409);
        });
        // An unexpected existing allocation forces the second insertion to fail.
        DB::table('leave_request_days')->insert(['id' => 'fault', 'leave_request_id' => $pending->id,
            'leave_date' => '2026-09-02', 'type' => 'paid']);
        try {
            $this->leave->approve('admin', $pending->id);
            $this->fail('Expected unique constraint failure.');
        } catch (QueryException) {
            $this->assertDatabaseCount('leave_request_days', 1);
            $this->assertDatabaseHas('leave_requests', ['id' => $pending->id, 'status' => 'pending', 'paid_leave_days' => 0]);
            $this->assertSame(0, $this->leave->balance('one', 'one', 2026)['paid_used']);
        }
    }

    public function test_foreign_keys_reject_unknown_employee_and_reviewer(): void
    {
        foreach ([['user_id' => 'missing'], ['approved_by' => 'missing']] as $invalid) {
            try {
                DB::table('attendance_working_day_overrides')->insert($invalid + [
                    'id' => 'invalid', 'user_id' => 'one', 'work_date' => '2026-09-13', 'approved_by' => 'admin',
                ]);
                $this->fail('Invalid foreign key accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('attendance_working_day_overrides', 0);
            }
        }
    }

    public function test_two_simultaneous_approvals_cannot_overspend_paid_leave(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This subprocess contention test uses an isolated SQLite file.');
        }
        $this->approved('2026-09-01', '2026-09-08');
        $first = $this->leave->submit('one', '2026-09-14', '2026-09-18', 'First');
        $second = $this->leave->submit('one', '2026-09-21', '2026-09-25', 'Second');
        // Windows tempnam truncates its prefix to three characters.
        $temporary = tempnam(sys_get_temp_dir(), 'kat');
        $path = dirname($temporary).DIRECTORY_SEPARATOR.'karya-attendance-'.basename($temporary);
        rename($temporary, $path);
        $signal = $path.'.start';
        $processes = [];
        try {
            DB::statement('VACUUM INTO ?', [$path]);
            config(['database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');
            foreach ([$first, $second] as $request) {
                $process = new Process([
                    PHP_BINARY, '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3',
                    base_path('tests/attendance-approval-worker.php'), $path, $signal, $request->id,
                ], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path]);
                $process->setTimeout(20);
                $process->start();
                $processes[$request->id] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = count(array_filter($processes, fn ($process) => str_contains($process->getOutput(), 'ready')));
                if ($ready === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, implode('\n', array_map(fn ($p) => $p->getErrorOutput(), $processes)));
            touch($signal);
            foreach ($processes as $id => $process) {
                $code = $process->wait();
                $this->assertContains($code, [0, 75], $process->getErrorOutput());
                if ($code === 75) {
                    // SQLite rejects a concurrent writer instead of providing MySQL row locks.
                    // A fresh operation must recompute the balance after the winner commits.
                    $this->leave->approve('admin', $id);
                }
            }
            $this->assertSame(12, $this->leave->balance('one', 'one', 2026)['paid_used']);
            $this->assertSame(5, $this->leave->balance('one', 'one', 2026)['unpaid_used']);
            $this->assertSame(3, DB::table('leave_requests')->where('status', 'approved')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
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

    public function test_migration_rollback_only_removes_new_tables_and_existing_user_deletion_still_works(): void
    {
        $this->approved('2026-09-01', '2026-09-02');
        app(AttendanceOverrideService::class)->authorize('admin', 'one', '2026-09-13');
        DB::table('users')->where('id', 'admin')->delete();
        $this->assertNull(DB::table('leave_requests')->first()->reviewed_by);
        DB::table('users')->where('id', 'one')->delete();
        $this->assertDatabaseCount('leave_request_days', 0);
        $this->assertDatabaseCount('attendance_working_day_overrides', 0);
        (require database_path('migrations/2026_09_08_000000_create_attendance_and_leave_tables.php'))->down();
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('attendance_records'));
        $this->assertDatabaseHas('users', ['id' => 'two']);
    }
}
