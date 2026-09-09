<?php

namespace App\Mcp\Tools;

use App\Services\HolidayService;
use App\Services\StateConcurrency;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a Karya holiday by ID. Supply at least one editable field; omitted fields are preserved. Leave is recalculated by the holiday service. Only authenticated and allowed administrators may use this tool.')]
class UpdateHolidayTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        $data = $request->validate(['holiday_id' => ['required', 'string', 'max:40']]);
        $fields = $request->only(['name', 'start_date', 'end_date', 'notes']);
        abort_if($fields === [], 422, 'Supply at least one of name, start_date, end_date or notes.');

        // Keep the read/merge/save atomic so partial edits cannot overwrite another writer.
        return app(StateConcurrency::class)->run(function () use ($actorId, $data, $fields) {
            $service = app(HolidayService::class);
            $holiday = collect($service->listing($actorId))->firstWhere('id', $data['holiday_id']);
            abort_unless($holiday, 404, 'Holiday not found.');
            return ['holiday' => (array) $service->save($actorId, $data['holiday_id'], array_replace((array) $holiday, $fields))];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'holiday_id' => $schema->string()->description('Holiday ID returned by ListHolidaysTool.')->required(),
            'name' => $schema->string()->description('Holiday name, at most 255 characters.'),
            'start_date' => $schema->string()->description('Inclusive start date in YYYY-MM-DD format.'),
            'end_date' => $schema->string()->description('Inclusive end date in YYYY-MM-DD format, on or after start_date.'),
            'notes' => $schema->string()->nullable()->description('Notes, at most 2000 characters. Set null to clear.'),
        ];
    }
}
