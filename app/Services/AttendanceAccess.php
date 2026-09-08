<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Future controllers supply the authenticated session user's ID, never an input actor ID. */
class AttendanceAccess
{
    public function actor(string $id): object
    {
        $user = DB::table('users')->where('id', $id)->first();
        abort_unless($user, 401, 'Please sign in.');

        return $user;
    }

    public function employee(string $id): object
    {
        $user = $this->actor($id);
        abort_unless($user->role === 'team' && (int) $user->role_id === 2, 403, 'Team member required.');

        return $user;
    }

    public function admin(string $actorId, ?string $employeeId = null): void
    {
        $actor = $this->actor($actorId);
        abort_unless($actor->role === 'admin' && (int) $actor->role_id === 1 && ($employeeId === null || $actorId !== $employeeId),
            403, 'A different administrator must authorize this operation.');
    }

    public function view(string $actorId, string $employeeId): void
    {
        if ($actorId === $employeeId) {
            $this->employee($actorId);
        } else {
            $this->admin($actorId, $employeeId);
        }
    }

    public function selfAttendance(string $actorId, string $employeeId): void
    {
        abort_unless($actorId === $employeeId, 403, 'Only your own attendance may be changed.');
        $this->employee($actorId);
    }
}
