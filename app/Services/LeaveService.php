<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(private AttendancePolicy $policy, private AttendanceAccess $access, private StateConcurrency $writes) {}

    public function submit(string $actorId, string $start, string $end, string $reason): object
    {
        return $this->writes->run(function () use ($actorId, $start, $end, $reason) {
            $this->access->employee($actorId);
            $dates = $this->policy->qualifyingDates($start, $end);
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required.']);
            }
            $id = (string) Str::uuid();
            DB::table('leave_requests')->insert([
                'id' => $id, 'user_id' => $actorId, 'start_date' => $start, 'end_date' => $end,
                'reason' => $reason, 'status' => 'pending', 'qualifying_days' => count($dates),
                'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->find($id);
        });
    }

    public function balance(string $actorId, string $userId, int $year): array
    {
        $this->access->view($actorId, $userId);
        abort_unless($year >= 1000 && $year <= 9999, 422, 'Invalid calendar year.');
        $counts = DB::table('leave_request_days as days')
            ->join('leave_requests as requests', 'requests.id', '=', 'days.leave_request_id')
            ->where('requests.user_id', $userId)->where('requests.status', 'approved')
            ->whereBetween('days.leave_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->selectRaw('days.type, COUNT(*) AS total')->groupBy('days.type')->pluck('total', 'type');
        $entitlement = (int) config('attendance.annual_paid_leave_days');
        $used = (int) ($counts['paid'] ?? 0);

        return ['year' => $year, 'entitlement' => $entitlement, 'paid_used' => $used,
            'paid_remaining' => max(0, $entitlement - $used), 'unpaid_used' => (int) ($counts['unpaid'] ?? 0)];
    }


public function mine(string $actorId): array
{
    $this->access->employee($actorId);

    return DB::table('leave_requests')
        ->where('user_id', $actorId)
        ->orderByDesc('created_at')
        ->get()
        ->map(fn ($request) => (array) $request)
        ->all();
}

public function adminListing(string $actorId, ?string $status = null): array
{
    $this->access->admin($actorId);

    if ($status !== null && ! in_array($status, ['pending', 'approved', 'rejected'], true)) {
        throw ValidationException::withMessages([
            'status' => 'Invalid leave request status.',
        ]);
    }

    $query = DB::table('leave_requests as leave')
        ->join('users', 'users.id', '=', 'leave.user_id')
        ->select([
            'leave.*',
            'users.name as user_name',
            'users.email as user_email',
        ])
        ->orderByDesc('leave.created_at');

    if ($status !== null) {
        $query->where('leave.status', $status);
    }

    return $query
        ->get()
        ->map(fn ($request) => (array) $request)
        ->all();
}




    public function approve(string $actorId, string $id, ?string $note = null): object
    {
        return $this->review($actorId, $id, $note, true);
    }

    public function reject(string $actorId, string $id, ?string $note = null): object
    {
        return $this->review($actorId, $id, $note, false);
    }

    private function review(string $actorId, string $id, ?string $note, bool $approve): object
    {
        // Own the outer transaction: callers must not supply a pre-existing stale snapshot.
        abort_if(DB::transactionLevel() !== 0, 409, 'Review must start outside an existing transaction.');

        // The existing MySQL advisory writer lock is acquired BEFORE opening the transaction,
        // so a repeatable-read snapshot cannot predate another Karya approval's commit.
        return $this->writes->run(function () use ($actorId, $id, $note, $approve) {
            $request = $this->find($id);
            $this->access->admin($actorId, $request->user_id);
            DB::table('users')->where('id', $request->user_id)->lockForUpdate()->first();
            $request = $this->find($id, true);
            abort_unless($request->status === 'pending', 409, 'Only pending requests can be reviewed.');
            $dates = $this->policy->qualifyingDates($request->start_date, $request->end_date);
            $paid = 0;
            $unpaid = 0;
            $remaining = [];
            if ($approve) {
                // Prevent paying twice for overlapping requests; rejected/pending ranges do not block.
                $overlap = DB::table('leave_request_days as days')
                    ->join('leave_requests as requests', 'requests.id', '=', 'days.leave_request_id')
                    ->where('requests.user_id', $request->user_id)->where('requests.status', 'approved')
                    ->whereIn('days.leave_date', $dates)->exists();
                abort_if($overlap, 409, 'Approved leave already covers a qualifying date.');
                foreach ($dates as $date) {
                    $year = $this->policy->date($date)->year;
                    $remaining[$year] ??= $this->balance($actorId, $request->user_id, $year)['paid_remaining'];
                    $type = $remaining[$year] > 0 ? 'paid' : 'unpaid';
                    if ($type === 'paid') {
                        $remaining[$year]--;
                        $paid++;
                    } else {
                        $unpaid++;
                    }
                    DB::table('leave_request_days')->insert([
                        'id' => (string) Str::uuid(), 'leave_request_id' => $id, 'leave_date' => $date, 'type' => $type,
                    ]);
                }
            }
            DB::table('leave_requests')->where('id', $id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actorId,
                'reviewed_at' => now(), 'review_note' => $note, 'qualifying_days' => count($dates),
                'paid_leave_days' => $paid, 'unpaid_leave_days' => $unpaid, 'updated_at' => now(),
            ]);

            return $this->find($id);
        });
    }

    /** Holiday CRUD calls this inside the shared writer transaction; review audit stays intact. */
    public function recalculateForCalendar(): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'Calendar recalculation requires a transaction.');
        $requests = DB::table('leave_requests')->where('status', 'approved')->orderBy('reviewed_at')->orderBy('id')->get();
        DB::table('leave_request_days')->whereIn('leave_request_id', $requests->pluck('id'))->delete();
        $remaining = []; $covered = [];
        foreach ($requests as $request) {
            $dates = $this->policy->qualifyingDates($request->start_date, $request->end_date);
            $paid = 0; $unpaid = 0;
            foreach ($dates as $date) {
                $key = $request->user_id.':'.$date;
                abort_if(isset($covered[$key]), 409, 'Holiday change would create overlapping approved leave. Review the affected leave requests first.');
                $covered[$key] = true;
                $yearKey = $request->user_id.':'.$this->policy->date($date)->year;
                $remaining[$yearKey] ??= (int) config('attendance.annual_paid_leave_days');
                $type = $remaining[$yearKey] > 0 ? 'paid' : 'unpaid';
                if ($type === 'paid') { $remaining[$yearKey]--; $paid++; } else $unpaid++;
                DB::table('leave_request_days')->insert(['id' => (string) Str::uuid(), 'leave_request_id' => $request->id, 'leave_date' => $date, 'type' => $type]);
            }
            DB::table('leave_requests')->where('id', $request->id)->update(['qualifying_days' => count($dates), 'paid_leave_days' => $paid, 'unpaid_leave_days' => $unpaid, 'updated_at' => now()]);
        }
        foreach (DB::table('leave_requests')->where('status', 'pending')->get() as $request) {
            DB::table('leave_requests')->where('id', $request->id)->update(['qualifying_days' => count($this->policy->qualifyingDates($request->start_date, $request->end_date))]);
        }
    }

    private function find(string $id, bool $lock = false): object
    {
        $query = DB::table('leave_requests')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404, 'Leave request not found.');

        return $row;
    }
}
