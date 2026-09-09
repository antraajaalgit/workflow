<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List team attendance, leave and overtime details. Optional exact employee name; omit for all team employees. Only authenticated and allowed Karya administrators may use this tool.')]
class ListAttendanceTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'employee_name' => ['sometimes', 'required', 'string', 'max:255']]);
        $userId = isset($data['employee_name']) ? $this->employeeId($data['employee_name']) : null;
        return app(\App\Services\AdminAttendanceService::class)->listing($actorId, $data['date'] ?? null, $userId);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('YYYY-MM-DD; defaults to today in the attendance timezone.'),
            'employee_name' => $schema->string()->description('Exact team employee name, case-insensitive; omit for all employees.'),
        ];
    }
}
