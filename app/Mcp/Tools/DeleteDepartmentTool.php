<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete the specified Karya entity by exact ID. Refuses departments containing team members; moves affected tasks to the dashboard fallback department.')]
class DeleteDepartmentTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['department_id' => ['required', 'string', 'max:40']])['department_id'];
        $deleted = $this->entity('departments', $id, ['id', 'name']);
        $operations->deleteDepartment($id, $actor);
        return ['message' => 'Department deleted successfully.', 'deleted' => $deleted];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['department_id' => $schema->string()->required()];
    }
}
