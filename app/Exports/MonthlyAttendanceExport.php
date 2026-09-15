<?php

namespace App\Exports;

use App\Exports\Sheets\MonthlyAttendanceDetailsSheet;
use App\Exports\Sheets\MonthlyAttendanceSummarySheet;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MonthlyAttendanceExport implements Export, WithMultipleSheets
{
    public function __construct(
        private string $actorId,
        private int $year,
        private int $month
    ) {}

    public function sheets(): array
    {
        return [
            new MonthlyAttendanceDetailsSheet(
                $this->actorId,
                $this->year,
                $this->month
            ),
            new MonthlyAttendanceSummarySheet(
                $this->actorId,
                $this->year,
                $this->month
            ),
        ];
    }
}