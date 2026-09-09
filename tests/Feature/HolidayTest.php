<?php

namespace Tests\Feature;

use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedDatabase;

class HolidayTest extends TestCase
{
    private IsolatedDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'attendance.geofence' => [
                'office_latitude' => 0,
                'office_longitude' => 0,
                'radius_metres' => 100,
                'max_accuracy_metres' => 50,
            ],
        ]);

        $this->database = new IsolatedDatabase;
        $this->database->connect();

        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) {
                (require $file)->up();
            }
        }

        foreach ([
            'admin' => ['admin', 1],
            'one' => ['team', 2],
            'two' => ['team', 2],
            'client' => ['client', 0],
        ] as $id => [$role, $roleId]) {
            DB::table('users')->insert([
                'id' => $id,
                'name' => $id,
                'role' => $role,
                'role_id' => $roleId,
                'email' => $id.'@example.test',
                'password' => 'unused',
            ]);
        }

        $this->at('2026-11-10 09:30:00');
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

    private function at(string $time): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($time, 'Asia/Kolkata')
        );
    }

    private function createHoliday(
        string $start = '2026-11-08',
        string $end = '2026-11-11'
    ) {
        return $this->postJson('/api/admin/holidays', [
            'name' => 'Diwali',
            'start_date' => $start,
            'end_date' => $end,
            'notes' => 'Diwali holidays',
        ]);
    }

    private function postAttendance(string $uri)
    {
        return $this->postJson($uri, [
            'latitude' => 0,
            'longitude' => 0,
            'accuracy' => 10,
        ]);
    }

    public function test_admin_can_create_list_update_and_delete_multi_day_holiday(): void
    {
        $id = $this->createHoliday()
            ->assertCreated()
            ->assertJsonPath('holiday.name', 'Diwali')
            ->assertJsonPath('holiday.start_date', '2026-11-08')
            ->assertJsonPath('holiday.end_date', '2026-11-11')
            ->json('holiday.id');

        $this->getJson('/api/admin/holidays')
            ->assertOk()
            ->assertJsonCount(1, 'holidays')
            ->assertJsonPath('holidays.0.name', 'Diwali');

        $this->putJson('/api/admin/holidays/'.$id, [
            'name' => 'Diwali',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'notes' => 'Updated Diwali holidays',
        ])->assertOk()
            ->assertJsonPath('holiday.start_date', '2026-11-10')
            ->assertJsonPath('holiday.end_date', '2026-11-11');

        $this->assertDatabaseHas('holidays', [
            'id' => $id,
            'name' => 'Diwali',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
        ]);

        $this->deleteJson('/api/admin/holidays/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseCount('holidays', 0);
    }

    public function test_non_admin_cannot_manage_holidays(): void
    {
        $this->withSession(['nagare_user_id' => 'one']);

        $this->getJson('/api/admin/holidays')->assertForbidden();

        $this->postJson('/api/admin/holidays', [
            'name' => 'Christmas',
            'start_date' => '2026-12-25',
            'end_date' => '2026-12-25',
        ])->assertForbidden();

        $this->assertDatabaseCount('holidays', 0);
    }

    public function test_holiday_blocks_normal_attendance_and_appears_in_admin_listing(): void
    {
        $this->createHoliday('2026-11-10', '2026-11-10')
            ->assertCreated();

        $this->withSession(['nagare_user_id' => 'one']);

        $this->getJson('/api/attendance/today')
            ->assertOk()
            ->assertJsonPath('day_type', 'holiday')
            ->assertJsonPath('holiday_name', 'Diwali')
            ->assertJsonPath('is_holiday', true)
            ->assertJsonPath('is_working_day', false)
            ->assertJsonPath('can_check_in', false)
            ->assertJsonPath('can_check_out', false)
            ->assertJsonPath('message', 'Holiday – Diwali');

        $this->postAttendance('/api/attendance/check-in')
            ->assertConflict()
            ->assertJsonPath('message', 'Holiday – Diwali');

        $this->withSession(['nagare_user_id' => 'admin']);

        $this->getJson('/api/admin/attendance?date=2026-11-10&user_id=one')
            ->assertOk()
            ->assertJsonPath('rows.0.status', 'holiday')
            ->assertJsonPath('rows.0.holiday_name', 'Diwali')
            ->assertJsonPath('rows.0.is_holiday', true)
            ->assertJsonPath('rows.0.is_working_day', false)
            ->assertJsonPath('rows.0.can_authorize_override', true);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_overtime_authorization_allows_work_on_holiday_for_selected_employee_only(): void
    {
        $this->createHoliday('2026-11-10', '2026-11-10')
            ->assertCreated();

        $this->postJson('/api/admin/attendance/overrides', [
            'user_id' => 'one',
            'work_date' => '2026-11-10',
            'notes' => 'Urgent holiday work',
        ])->assertCreated();

        $this->withSession(['nagare_user_id' => 'one']);

        $this->getJson('/api/attendance/today')
            ->assertOk()
            ->assertJsonPath('day_type', 'overtime')
            ->assertJsonPath('is_holiday', true)
            ->assertJsonPath('holiday_name', 'Diwali')
            ->assertJsonPath('has_overtime_override', true)
            ->assertJsonPath('is_working_day', true)
            ->assertJsonPath('can_check_in', true);

        $this->postAttendance('/api/attendance/check-in')
            ->assertCreated();

        $this->at('2026-11-10 18:30:00');

        $this->postAttendance('/api/attendance/check-out')
            ->assertOk()
            ->assertJsonPath('attendance.worked_minutes', 540);

        $this->withSession(['nagare_user_id' => 'two']);

        $this->getJson('/api/attendance/today')
            ->assertJsonPath('day_type', 'holiday')
            ->assertJsonPath('can_check_in', false);

        $this->postAttendance('/api/attendance/check-in')
            ->assertConflict()
            ->assertJsonPath('message', 'Holiday – Diwali');
    }

    public function test_multi_day_holiday_dates_do_not_consume_leave(): void
    {
        $this->createHoliday()
            ->assertCreated();

        $leave = app(LeaveService::class);

        $request = $leave->submit(
            'one',
            '2026-11-09',
            '2026-11-12',
            'Attend marriage'
        );

        $this->assertSame(1, (int) $request->qualifying_days);

        $approved = $leave->approve('admin', $request->id);

        $this->assertSame(1, (int) $approved->qualifying_days);
        $this->assertSame(1, (int) $approved->paid_leave_days);
        $this->assertSame(0, (int) $approved->unpaid_leave_days);

        $this->assertDatabaseCount('leave_request_days', 1);

        $this->assertDatabaseHas('leave_request_days', [
            'leave_request_id' => $request->id,
            'leave_date' => '2026-11-12',
            'type' => 'paid',
        ]);
    }

    public function test_edit_and_delete_holiday_recalculate_approved_leave(): void
    {
        $holidayId = $this->createHoliday()
            ->assertCreated()
            ->json('holiday.id');

        $leave = app(LeaveService::class);

        $request = $leave->submit(
            'one',
            '2026-11-09',
            '2026-11-12',
            'Attend marriage'
        );

        $leave->approve('admin', $request->id);

        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'qualifying_days' => 1,
            'paid_leave_days' => 1,
            'unpaid_leave_days' => 0,
        ]);

        $this->putJson('/api/admin/holidays/'.$holidayId, [
            'name' => 'Diwali',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'notes' => 'Shortened holiday',
        ])->assertOk();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'qualifying_days' => 2,
            'paid_leave_days' => 2,
            'unpaid_leave_days' => 0,
        ]);

        $this->assertDatabaseHas('leave_request_days', [
            'leave_request_id' => $request->id,
            'leave_date' => '2026-11-09',
        ]);

        $this->assertDatabaseHas('leave_request_days', [
            'leave_request_id' => $request->id,
            'leave_date' => '2026-11-12',
        ]);

        $this->deleteJson('/api/admin/holidays/'.$holidayId)
            ->assertOk();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $request->id,
            'qualifying_days' => 4,
            'paid_leave_days' => 4,
            'unpaid_leave_days' => 0,
        ]);

        $this->assertDatabaseCount('leave_request_days', 4);

        foreach ([
            '2026-11-09',
            '2026-11-10',
            '2026-11-11',
            '2026-11-12',
        ] as $date) {
            $this->assertDatabaseHas('leave_request_days', [
                'leave_request_id' => $request->id,
                'leave_date' => $date,
                'type' => 'paid',
            ]);
        }
    }
}