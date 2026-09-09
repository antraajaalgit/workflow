<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Authorize overtime for a team employee on a weekly-off date using existing attendance rules. Only authenticated and allowed Karya administrators may use this tool.')]
class AuthorizeOvertimeTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['employee_name' => ['required', 'string', 'max:255'], 'work_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $userId = $this->employeeId($data['employee_name']);
        return ['override' => (array) app(\App\Services\AttendanceOverrideService::class)->authorize($actorId, $userId, $data['work_date'], $data['notes'] ?? null)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_name' => $schema->string()->description('Exact team employee name, case-insensitive.')->required(),
            'work_date' => $schema->string()->description('Weekly-off date in YYYY-MM-DD format.')->required(),
            'notes' => $schema->string()->description('Optional authorization notes, at most 2000 characters.'),
        ];
    }
}
