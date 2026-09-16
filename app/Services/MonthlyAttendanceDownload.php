<?php

namespace App\Services;

use App\Exports\MonthlyAttendanceExport;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class MonthlyAttendanceDownload
{
    public function disk(): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 'local',
            'root' => storage_path('app/private/monthly-attendance-downloads'),
            'visibility' => 'private',
            'throw' => true,
        ]);
    }

    public function filename(int $year, int $month): string
    {
        return 'Karya-Attendance-'.CarbonImmutable::create($year, $month, 1)->format('F-Y').'.xlsx';
    }

    public function create(string $actorId, int $year, int $month): array
    {
        $id = (string) Str::uuid();
        $this->disk()->put($id.'.xlsx', Excel::raw(
            new MonthlyAttendanceExport($actorId, $year, $month),
            \Maatwebsite\Excel\Excel::XLSX,
        ));
        $expires = now()->addMinutes(10);

        return [
            'filename' => $this->filename($year, $month),
            'download_url' => URL::temporarySignedRoute('attendance.monthly-download', $expires, [
                'id' => $id, 'month' => $month, 'year' => $year,
            ]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    public function prune(): void
    {
        $disk = $this->disk();
        foreach ($disk->files() as $file) {
            if (preg_match('/^[0-9a-f-]{36}\.xlsx$/', $file) && $disk->lastModified($file) < now()->subHour()->timestamp) {
                $disk->delete($file);
            }
        }
    }
}
