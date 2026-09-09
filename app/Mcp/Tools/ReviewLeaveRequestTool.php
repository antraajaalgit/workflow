<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Approve or reject a pending leave request using existing leave allocation and review rules. Only authenticated and allowed Karya administrators may use this tool.')]
class ReviewLeaveRequestTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['request_id' => ['required', 'string', 'max:40'], 'action' => ['required', 'string', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:2000']]);
        $leave = app(\App\Services\LeaveService::class);
        $review = $data['action'] === 'approve'
            ? $leave->approve($actorId, $data['request_id'], $data['note'] ?? null)
            : $leave->reject($actorId, $data['request_id'], $data['note'] ?? null);
        return ['request' => (array) $review];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->string()->description('Leave request ID returned by the leave listing.')->required(),
            'action' => $schema->string()->enum(['approve', 'reject'])->required(),
            'note' => $schema->string()->description('Optional review note, at most 2000 characters.'),
        ];
    }
}
