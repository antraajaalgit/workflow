<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List employee leave requests, optionally filtered by pending, approved or rejected status. Only authenticated and allowed Karya administrators may use this tool.')]
class ListLeaveRequestsTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'in:pending,approved,rejected']]);
        return ['requests' => app(\App\Services\LeaveService::class)->adminListing($actorId, $data['status'] ?? null)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['pending', 'approved', 'rejected'])->description('Omit to list all statuses.'),
        ];
    }
}
