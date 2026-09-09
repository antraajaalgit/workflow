<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Revoke an overtime override while preserving its authorization and revocation audit. Only authenticated and allowed Karya administrators may use this tool.')]
class RevokeOvertimeTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['override_id' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:2000']]);
        app(\App\Services\AttendanceOverrideService::class)->revoke($actorId, $data['override_id'], $data['note'] ?? null);
        return ['override_id' => $data['override_id'], 'revoked' => true];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'override_id' => $schema->string()->description('Override ID returned by attendance listing or authorization.')->required(),
            'note' => $schema->string()->description('Optional revocation note, at most 2000 characters.'),
        ];
    }
}
