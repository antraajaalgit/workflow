<?php

namespace App\Mcp\Tools;

use App\Services\HolidayService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a Karya holiday covering an inclusive date range, recalculating leave through the holiday service. Only authenticated and allowed administrators may use this tool.')]
class CreateHolidayTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        return ['holiday' => (array) app(HolidayService::class)->save($actorId, null, $request->only(['name', 'start_date', 'end_date', 'notes']))];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Holiday name, at most 255 characters.')->required(),
            'start_date' => $schema->string()->description('Inclusive start date in YYYY-MM-DD format, including the year.')->required(),
            'end_date' => $schema->string()->description('Inclusive end date in YYYY-MM-DD format, on or after start_date.')->required(),
            'notes' => $schema->string()->nullable()->description('Optional notes, at most 2000 characters.'),
        ];
    }
}
