<?php

namespace App\Mcp\Tools;

use App\Services\HolidayService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a Karya holiday by ID and recalculate leave through the holiday service. Only authenticated and allowed administrators may use this tool.')]
class DeleteHolidayTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['holiday_id' => ['required', 'string', 'max:40']]);
        app(HolidayService::class)->delete($actorId, $data['holiday_id']);
        return ['deleted' => true, 'holiday_id' => $data['holiday_id']];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['holiday_id' => $schema->string()->description('Holiday ID returned by ListHolidaysTool.')->required()];
    }
}
