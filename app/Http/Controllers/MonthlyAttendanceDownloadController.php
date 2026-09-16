<?php

namespace App\Http\Controllers;

use App\Services\MonthlyAttendanceDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyAttendanceDownloadController extends Controller
{
    public function __invoke(Request $request, string $id, MonthlyAttendanceDownload $downloads): StreamedResponse
    {
        // The route's short-lived signature is the bearer authorization for this exact file.
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);
        $disk = $downloads->disk();
        abort_unless($disk->exists($id.'.xlsx'), 404);

        return $disk->download($id.'.xlsx', $downloads->filename((int) $data['year'], (int) $data['month']), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
