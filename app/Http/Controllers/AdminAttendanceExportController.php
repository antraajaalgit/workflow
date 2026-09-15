<?php

namespace App\Http\Controllers;

use App\Exports\MonthlyAttendanceExport;
use App\Services\AttendanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminAttendanceExportController extends Controller
{
    public function __construct(
        private AttendanceAccess $access
    ) {}

    public function download(Request $request): BinaryFileResponse
    {
        $actorId = $request->session()->get('nagare_user_id');

        abort_unless(
            is_string($actorId) && $actorId !== '',
            401,
            'Please sign in.'
        );

        $this->access->admin($actorId);

        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $month = (int) $data['month'];
        $year = (int) $data['year'];

        $monthName = CarbonImmutable::create(
            $year,
            $month,
            1,
            0,
            0,
            0,
            'Asia/Kolkata'
        )->format('F');

        $filename = sprintf(
            'Karya-Attendance-%s-%d.xlsx',
            $monthName,
            $year
        );

        return Excel::download(
            new MonthlyAttendanceExport(
                $actorId,
                $year,
                $month
            ),
            $filename
        );
    }
}