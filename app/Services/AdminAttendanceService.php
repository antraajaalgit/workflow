<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdminAttendanceService
{
    public function __construct(private AttendanceAccess $access, private AttendancePolicy $policy) {}

    public function listing(string $actorId, ?string $date = null, ?string $userId = null): array
    {
        $this->access->admin($actorId);
        $date = $this->policy->date($date ?? $this->policy->today())->toDateString();
        if ($userId !== null) {
            $this->access->employee($userId);
        }
        $employees = DB::table('users')->where('role', 'team')->where('role_id', 2)->orderBy('name')->get(['id', 'name']);
        $selected = $employees->filter(fn ($user) => $userId === null || $user->id === $userId);
        $ids = $selected->pluck('id');
        $records = DB::table('attendance_records')->where('attendance_date', $date)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $overrides = DB::table('attendance_working_day_overrides')->where('work_date', $date)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $leave = DB::table('leave_request_days as days')->join('leave_requests as requests', 'requests.id', '=', 'days.leave_request_id')
            ->where('requests.status', 'approved')->where('days.leave_date', $date)->whereIn('requests.user_id', $ids)
            ->pluck('days.type', 'requests.user_id');
        $weeklyOff = $this->policy->isWeeklyOff($date);
        $rows = $selected->map(function ($user) use ($records, $overrides, $leave, $weeklyOff) {
            $record = $records->get($user->id);
            $override = $overrides->get($user->id);
            $active = $override !== null && $override->revoked_at === null;
            $leaveType = $leave->get($user->id);
            $status = $record?->status ?? ($leaveType ? $leaveType.'_leave' : ($weeklyOff && ! $active ? 'weekly_off' : 'not_checked_in'));

            return [
                'user_id' => $user->id, 'name' => $user->name, 'status' => $status,
                'check_in_at' => $record?->check_in_at ? $this->policy->normalize($record->check_in_at)->toIso8601String() : null,
                'check_out_at' => $record?->check_out_at ? $this->policy->normalize($record->check_out_at)->toIso8601String() : null,
                'is_late' => (bool) $record?->is_late, 'is_early_checkout' => (bool) $record?->is_early_checkout,
                'worked_minutes' => $record?->worked_minutes === null ? null : (int) $record->worked_minutes,
                'is_weekly_off' => $weeklyOff, 'is_working_day' => ! $weeklyOff || $active,
                'approved_leave_type' => $leaveType, 'has_overtime_override' => $active,
                'override' => $override ? ['id' => $override->id, 'active' => $active, 'notes' => $override->notes,
                    'revoked_at' => $override->revoked_at ? $this->policy->normalize($override->revoked_at)->toIso8601String() : null] : null,
                // Step 1 retains revoked rows and prohibits a second override for the same user/date.
                'can_authorize_override' => $weeklyOff && $override === null,
                'can_revoke_override' => $active,
            ];
        })->values();

        return ['attendance_date' => $date, 'timezone' => $this->policy->timezone(), 'employees' => $employees, 'rows' => $rows];
    }
}
