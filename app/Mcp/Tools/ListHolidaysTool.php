<?php

namespace App\Mcp\Tools;

use App\Services\HolidayService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List Karya holidays ordered by start date and name. Only authenticated and allowed administrators may use this tool. Use the returned IDs for updates and deletion.')]
class ListHolidaysTool extends AttendanceLeaveTool
{
    protected function execute(Request $request, string $actorId): array
    {
        return ['holidays' => array_map(fn ($holiday) => (array) $holiday, app(HolidayService::class)->listing($actorId))];
    }
}
