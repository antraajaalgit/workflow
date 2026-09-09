<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the annual leave balance for an exact team employee name. Only authenticated and allowed Karya administrators may use this tool.')]
class GetLeaveBalanceTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['employee_name' => ['required', 'string', 'max:255'], 'year' => ['nullable', 'integer', 'min:1000', 'max:9999']]);
        $userId = $this->employeeId($data['employee_name']);
        $year = (int) ($data['year'] ?? app(\App\Services\AttendancePolicy::class)->now()->year);
        return app(\App\Services\LeaveService::class)->balance($actorId, $userId, $year);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_name' => $schema->string()->description('Exact team employee name, case-insensitive.')->required(),
            'year' => $schema->integer()->description('Calendar year (1000-9999); defaults to the current attendance year.'),
        ];
    }
}
