<?php

namespace App\Exports\Sheets;

use App\Services\AdminAttendanceService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class MonthlyAttendanceDetailsSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    public function __construct(
        private string $actorId,
        private int $year,
        private int $month
    ) {}

    public function array(): array
    {
        /** @var AdminAttendanceService $attendance */
        $attendance = app(AdminAttendanceService::class);

        $start = CarbonImmutable::create(
            $this->year,
            $this->month,
            1,
            0,
            0,
            0,
            'Asia/Kolkata'
        );

        $end = $start->endOfMonth();

        $rows = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $result = $attendance->listing(
                $this->actorId,
                $date->toDateString()
            );

            foreach ($result['rows'] as $attendanceRow) {
                $rows[] = [
                    $date->format('d-m-Y'),
                    $date->format('l'),
                    $attendanceRow['name'],
                    $this->statusLabel($attendanceRow['status']),
                    $this->formatTime($attendanceRow['check_in_at']),
                    $this->formatTime($attendanceRow['check_out_at']),
                    $attendanceRow['is_late'] ? 'Yes' : 'No',
                    $attendanceRow['is_early_checkout'] ? 'Yes' : 'No',
                    $this->formatWorkedTime($attendanceRow['worked_minutes']),
                    $this->leaveLabel($attendanceRow['approved_leave_type']),
                    $attendanceRow['is_weekly_off'] ? 'Yes' : 'No',
                    $attendanceRow['holiday_name'] ?? '',
                    $attendanceRow['has_overtime_override'] ? 'Yes' : 'No',
                ];
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Date',
            'Day',
            'Employee',
            'Status',
            'Check In',
            'Check Out',
            'Late',
            'Early Checkout',
            'Worked Time',
            'Leave Type',
            'Weekly Off',
            'Holiday',
            'Overtime Authorized',
        ];
    }

    public function title(): string
    {
        return 'Attendance Details';
    }

    private function formatTime(?string $value): string
    {
        if (! $value) {
            return '';
        }

        return CarbonImmutable::parse($value)
            ->setTimezone('Asia/Kolkata')
            ->format('h:i A');
    }

    private function formatWorkedTime(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $remainingMinutes);
    }

    private function leaveLabel(?string $type): string
    {
        return match ($type) {
            'paid' => 'Paid Leave',
            'unpaid' => 'Unpaid Leave',
            default => '',
        };
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return '';
        }

        return match ($status) {
            'present' => 'Present',
            'paid_leave' => 'Paid Leave',
            'unpaid_leave' => 'Unpaid Leave',
            'weekly_off' => 'Weekly Off',
            'holiday' => 'Holiday',
            'not_checked_in' => 'Not Checked In',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }
}