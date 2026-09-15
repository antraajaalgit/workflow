<?php

namespace App\Exports\Sheets;

use App\Services\AdminAttendanceService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class MonthlyAttendanceSummarySheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
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

        $summary = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $result = $attendance->listing(
                $this->actorId,
                $date->toDateString()
            );

            foreach ($result['rows'] as $row) {
                $userId = $row['user_id'];

                if (! isset($summary[$userId])) {
                    $summary[$userId] = [
                        'name' => $row['name'],
                        'working_days' => 0,
                        'present_days' => 0,
                        'late_days' => 0,
                        'early_checkouts' => 0,
                        'paid_leave' => 0,
                        'unpaid_leave' => 0,
                        'weekly_offs' => 0,
                        'holidays' => 0,
                        'overtime_days' => 0,
                        'worked_minutes' => 0,
                    ];
                }

                if ($row['is_working_day']) {
                    $summary[$userId]['working_days']++;
                }

                if (! empty($row['check_in_at'])) {
                    $summary[$userId]['present_days']++;
                }

                if ($row['is_late']) {
                    $summary[$userId]['late_days']++;
                }

                if ($row['is_early_checkout']) {
                    $summary[$userId]['early_checkouts']++;
                }

                if ($row['status'] === 'paid_leave') {
                    $summary[$userId]['paid_leave']++;
                }

                if ($row['status'] === 'unpaid_leave') {
                    $summary[$userId]['unpaid_leave']++;
                }

                if ($row['status'] === 'weekly_off') {
                    $summary[$userId]['weekly_offs']++;
                }

                if ($row['status'] === 'holiday') {
                    $summary[$userId]['holidays']++;
                }

                if ($row['has_overtime_override']) {
                    $summary[$userId]['overtime_days']++;
                }

                if ($row['worked_minutes'] !== null) {
                    $summary[$userId]['worked_minutes'] += (int) $row['worked_minutes'];
                }
            }
        }

        return array_values(array_map(function (array $employee) {
            return [
                $employee['name'],
                $employee['working_days'],
                $employee['present_days'],
                $employee['late_days'],
                $employee['early_checkouts'],
                $employee['paid_leave'],
                $employee['unpaid_leave'],
                $employee['weekly_offs'],
                $employee['holidays'],
                $employee['overtime_days'],
                $this->formatWorkedTime($employee['worked_minutes']),
            ];
        }, $summary));
    }

    public function headings(): array
    {
        return [
            'Employee',
            'Working Days',
            'Present Days',
            'Late Days',
            'Early Checkouts',
            'Paid Leave',
            'Unpaid Leave',
            'Weekly Offs',
            'Holidays',
            'Overtime Days',
            'Total Worked Time',
        ];
    }

    public function title(): string
    {
        return 'Monthly Summary';
    }

    private function formatWorkedTime(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $remainingMinutes);
    }
}