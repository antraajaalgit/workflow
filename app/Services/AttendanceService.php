<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceService
{
    public function __construct(private AttendancePolicy $policy, private AttendanceAccess $access, private StateConcurrency $writes, private AttendanceGeofence $geofence) {}

    public function today(string $actorId): array
    {
        $this->access->employee($actorId);

        return $this->state($actorId, $this->policy->now());
    }

    public function checkIn(string $actorId, array $location = []): array
    {
        return $this->write(function () use ($actorId, $location) {
            $this->lockEmployee($actorId);
            $now = $this->policy->now();
            $state = $this->state($actorId, $now);
            abort_unless($state['can_check_in'], 409, $state['message']);
            $location = $this->geofence->validate($location);
            DB::table('attendance_records')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $actorId, 'attendance_date' => $now->toDateString(),
                'check_in_at' => $now->toDateTimeString(), 'status' => 'present',
                'is_late' => $this->policy->isLate($now), 'is_early_checkout' => false,
                'check_in_latitude' => $location['latitude'], 'check_in_longitude' => $location['longitude'],
                'check_in_accuracy' => $location['accuracy'],
                'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
            ]);

            return array_replace($this->state($actorId, $now), ['message' => 'Checked in successfully.']);
        });
    }

    public function checkOut(string $actorId, array $location = []): array
    {
        return $this->write(function () use ($actorId, $location) {
            $this->lockEmployee($actorId);
            $now = $this->policy->now();
            $state = $this->state($actorId, $now);
            $row = DB::table('attendance_records')->where('user_id', $actorId)
                ->where('attendance_date', $now->toDateString())->lockForUpdate()->first();
            abort_unless($row && $row->check_in_at && $row->status === 'present', 409, 'You have not checked in today.');
            abort_if($row->check_out_at !== null, 409, 'You have already checked out today.');
            abort_unless($state['can_check_out'], 409, $state['message']);
            $location = $this->geofence->validate($location);
            $checkIn = $this->policy->normalize($row->check_in_at);
            abort_if($now->lessThan($checkIn), 409, 'Checkout time cannot precede check-in. Please retry later.');
            $minutes = intdiv($now->getTimestamp() - $checkIn->getTimestamp(), 60);
            DB::table('attendance_records')->where('id', $row->id)->update([
                'check_out_at' => $now->toDateTimeString(), 'worked_minutes' => $minutes,
                'check_out_latitude' => $location['latitude'], 'check_out_longitude' => $location['longitude'],
                'check_out_accuracy' => $location['accuracy'],
                'is_early_checkout' => $now->lessThan($this->policy->shiftEnd($now->toDateString())),
                'updated_at' => $now->toDateTimeString(),
            ]);

            return array_replace($this->state($actorId, $now), ['message' => 'Checked out successfully.']);
        });
    }

    private function lockEmployee(string $actorId): void
    {
        DB::table('users')->where('id', $actorId)->lockForUpdate()->first();
        $this->access->employee($actorId);
    }

    private function write(\Closure $operation): array
    {
        // Own the snapshot, as LeaveService does; the shared MySQL lock precedes it.
        abort_if(DB::transactionLevel() !== 0, 409, 'Attendance must start outside an existing transaction.');
        try {
            return $this->writes->run($operation);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Attendance already exists for today. Refresh your attendance state.');
        } catch (QueryException $exception) {
            // SQLite contention and MySQL deadlock/lock timeout must be safe to retry.
            $code = (int) ($exception->errorInfo[1] ?? 0);
            if ((DB::getDriverName() === 'sqlite' && in_array($code, [5, 6], true))
                || (DB::getDriverName() === 'mysql' && in_array($code, [1205, 1213], true))) {
                abort(409, 'Attendance is busy. Refresh and retry.');
            }
            throw $exception;
        }
    }

    private function state(string $actorId, CarbonImmutable $now): array
    {
        $date = $now->toDateString();
        $weeklyOff = $this->policy->isWeeklyOff($date);
        $override = $this->policy->hasOverride($actorId, $date);
        $working = ! $weeklyOff || $override;
        $leave = DB::table('leave_request_days as days')
            ->join('leave_requests as requests', 'requests.id', '=', 'days.leave_request_id')
            ->where('requests.user_id', $actorId)->where('requests.status', 'approved')
            ->where('days.leave_date', $date)->value('days.type');
        $row = DB::table('attendance_records')->where('user_id', $actorId)->where('attendance_date', $date)->first();
        $attendance = $row ? [
            'id' => $row->id, 'attendance_date' => $row->attendance_date, 'status' => $row->status,
            'check_in_at' => $row->check_in_at ? $this->policy->normalize($row->check_in_at)->toIso8601String() : null,
            'check_out_at' => $row->check_out_at ? $this->policy->normalize($row->check_out_at)->toIso8601String() : null,
            'is_late' => (bool) $row->is_late, 'is_early_checkout' => (bool) $row->is_early_checkout,
            'worked_minutes' => $row->worked_minutes === null ? null : (int) $row->worked_minutes,
        ] : null;
        $canCheckIn = $working && $leave === null && $row === null;
        // Leave approved after check-in does not prevent closing an existing work session.
        $canCheckOut = $working && $row && $row->status === 'present' && $row->check_in_at && ! $row->check_out_at;
        $message = match (true) {
            ! $working => 'Office Closed – Weekly Off',
            $row !== null && $row->check_out_at !== null => 'You have already checked out today.',
            $row !== null && $row->check_in_at !== null => 'You have already checked in today.',
            $leave === 'paid' => 'Approved Paid Leave',
            $leave === 'unpaid' => 'Approved Unpaid Leave',
            $row !== null => 'Attendance already exists for today.',
            default => 'You have not checked in today.',
        };

        return ['attendance_date' => $date, 'timezone' => $this->policy->timezone(),
            'server_timestamp' => $now->toIso8601String(),
            'day_type' => $weeklyOff ? ($override ? 'overtime' : 'weekly_off') : 'working_day',
            'is_working_day' => $working, 'is_weekly_off' => $weeklyOff, 'has_overtime_override' => $override,
            'approved_leave_type' => $leave, 'attendance' => $attendance,
            'can_check_in' => $canCheckIn, 'can_check_out' => (bool) $canCheckOut, 'message' => $message];
    }
}
