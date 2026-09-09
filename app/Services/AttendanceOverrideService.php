<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceOverrideService
{
    public function __construct(private AttendanceAccess $access, private AttendancePolicy $policy, private StateConcurrency $writes) {}

    public function authorize(string $actorId, string $userId, string $date, ?string $notes = null): object
    {
        return $this->writes->run(function () use ($actorId, $userId, $date, $notes) {
            $this->access->admin($actorId, $userId);
            $this->access->employee($userId);
            abort_unless($this->policy->isNonWorkingDay($date), 422, 'Overtime override requires a weekly-off or holiday date.');
            abort_if(DB::table('attendance_working_day_overrides')->where('user_id', $userId)->where('work_date', $date)->exists(),
                409, 'An override already exists for this employee and date.');
            $id = (string) Str::uuid();
            DB::table('attendance_working_day_overrides')->insert([
                'id' => $id, 'user_id' => $userId, 'work_date' => $date, 'type' => 'overtime',
                'approved_by' => $actorId, 'notes' => $notes, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('attendance_working_day_overrides')->where('id', $id)->first();
        });
    }

    /** Retain original authorization and revocation audit instead of deleting history. */
    public function revoke(string $actorId, string $id, ?string $note = null): void
    {
        $this->writes->run(function () use ($actorId, $id, $note) {
            $row = DB::table('attendance_working_day_overrides')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->access->admin($actorId, $row->user_id);
            abort_if($row->revoked_at !== null, 409, 'Override already revoked.');
            DB::table('attendance_working_day_overrides')->where('id', $id)->update([
                'revoked_at' => now(), 'revoked_by' => $actorId, 'revocation_note' => $note, 'updated_at' => now(),
            ]);
        });
    }
}
