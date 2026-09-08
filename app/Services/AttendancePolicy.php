<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendancePolicy
{
    public function timezone(): string
    {
        return config('attendance.timezone');
    }

    /** Naive server timestamps mean IST; offset-aware timestamps retain their instant. */
    public function normalize(string|DateTimeInterface $timestamp): CarbonImmutable
    {
        return $timestamp instanceof DateTimeInterface
            ? CarbonImmutable::instance($timestamp)->setTimezone($this->timezone())
            : CarbonImmutable::parse($timestamp, $this->timezone())->setTimezone($this->timezone());
    }

    public function today(): string
    {
        return $this->now()->toDateString();
    }

    /** Match the existing attendance datetime columns' second precision. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfSecond();
    }

    /** Calendar inputs must be literal ISO dates, never relative dates or timestamps. */
    public function date(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());
            if ($parsed && $parsed->format('Y-m-d') === $date) {
                return $parsed;
            }
        } catch (\Exception) {
        }
        throw ValidationException::withMessages(['date' => 'Use a valid YYYY-MM-DD date.']);
    }

    public function isSunday(string $date): bool
    {
        return $this->date($date)->isSunday();
    }

    public function isSecondSaturday(string $date): bool
    {
        $day = $this->date($date);

        return $day->isSaturday() && $day->subWeek()->month === $day->month
            && $day->subWeeks(2)->month !== $day->month;
    }

    public function isWeeklyOff(string $date): bool
    {
        return $this->isSunday($date) || $this->isSecondSaturday($date);
    }

    public function hasOverride(string $userId, string $date): bool
    {
        $this->date($date);

        return DB::table('attendance_working_day_overrides')->where('user_id', $userId)
            ->where('work_date', $date)->whereNull('revoked_at')->exists();
    }

    public function isWorkingDay(string $userId, string $date): bool
    {
        return ! $this->isWeeklyOff($date) || $this->hasOverride($userId, $date);
    }

    public function shiftStart(string $date): CarbonImmutable
    {
        return $this->date($date)->setTimeFromTimeString(config('attendance.shift_start'));
    }

    public function shiftEnd(string $date): CarbonImmutable
    {
        return $this->date($date)->setTimeFromTimeString(config('attendance.shift_end'));
    }

    public function graceCutoff(string $date): CarbonImmutable
    {
        return $this->shiftStart($date)->addMinutes(config('attendance.grace_minutes'));
    }

    public function isLate(string|DateTimeInterface $checkIn): bool
    {
        $local = $this->normalize($checkIn);

        return $local->greaterThan($this->graceCutoff($local->toDateString()));
    }

    /** Overtime overrides deliberately do not change annual leave qualification. */
    public function qualifyingDates(string $start, string $end): array
    {
        $first = $this->date($start);
        $last = $this->date($end);
        if ($last->lessThan($first)) {
            throw ValidationException::withMessages(['end_date' => 'End date cannot precede start date.']);
        }
        $dates = [];
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (! $this->isWeeklyOff($day->toDateString())) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }
}
