<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete the specified Karya entity by exact ID. Deletes all project tasks with existing history and attachment cleanup.')]
class DeleteProjectTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['project_id' => ['required', 'string', 'max:40']])['project_id'];
        $deleted = $this->entity('projects', $id, ['id', 'name', 'client_id']);
        $operations->deleteProject($id, $actor);
        return ['message' => 'Project deleted successfully.', 'deleted' => $deleted];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->required()];
    }
}
