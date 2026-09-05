<?php

namespace App\Mcp\Servers;



use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListTeamMembersTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\UpdateTaskStatusTool;
use App\Mcp\Tools\UpdateTaskProgressTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\DeleteProjectTool;
use App\Mcp\Tools\ListClientsTool;
use App\Mcp\Tools\CreateClientTool;
use App\Mcp\Tools\UpdateClientTool;
use App\Mcp\Tools\DeleteClientTool;
use App\Mcp\Tools\ListDepartmentsTool;
use App\Mcp\Tools\CreateDepartmentTool;
use App\Mcp\Tools\UpdateDepartmentTool;
use App\Mcp\Tools\DeleteDepartmentTool;
use App\Mcp\Tools\DeleteTeamMemberTool;
use App\Mcp\Tools\CreateProjectTool;

#[Name('Karya Server')]
#[Version('0.0.1')]
#[Instructions('Manage Karya as the Passport-authenticated allowed admin. Resolve entity IDs with list tools before writes. Use dedicated task status, progress and assignment tools. Project task edits are atomic. Delete tools perform dashboard cleanup. Never reveal credentials. Team member creation and editing remain dashboard-only.')]
class KaryaServer extends Server
{
    protected array $tools = [
        ListProjectsTool::class,
        ListTasksTool::class,
        ListTeamMembersTool::class,
        AssignTaskTool::class,
        UpdateTaskStatusTool::class,
        UpdateTaskProgressTool::class,
        CreateTaskTool::class,
        DeleteTaskTool::class,
        UpdateTaskTool::class,
        UpdateProjectTool::class,
        DeleteProjectTool::class,
        ListClientsTool::class,
        CreateClientTool::class,
        UpdateClientTool::class,
        DeleteClientTool::class,
        ListDepartmentsTool::class,
        CreateDepartmentTool::class,
        UpdateDepartmentTool::class,
        DeleteDepartmentTool::class,
        DeleteTeamMemberTool::class,
        CreateProjectTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
