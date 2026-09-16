<?php

namespace App\Mcp\Tools;

use App\Services\MonthlyAttendanceDownload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Download monthly attendance Excel for all employees, e.g. September 2026 means month 9 and year 2026. Admin-only. Returns a filename and a private download link valid for 10 minutes; treat the link as a secret. No workbook contents are returned through MCP.')]
class DownloadMonthlyAttendanceExcelTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        return app(MonthlyAttendanceDownload::class)->create($actorId, (int) $data['year'], (int) $data['month']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->integer()->min(1)->max(12)->required()->description('Calendar month, 1 through 12.'),
            'year' => $schema->integer()->min(2000)->max(2100)->required()->description('Calendar year, 2000 through 2100.'),
        ];
    }
}
