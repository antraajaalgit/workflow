<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update project details and optionally add/edit/delete its tasks atomically. Existing task IDs must belong to this project. Omitted details are preserved. The dashboard operation requires at least one retained or new task; projects without tasks need a new task in this call.')]
class UpdateProjectTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['project_id' => ['required', 'string', 'max:40']])['project_id'];
        $current = $this->entity('projects', $id, ['id', 'name', 'description', 'client_id']);
        $data = array_diff_key($request->all(), ['project_id' => true]);
        abort_if(! $data || array_diff(array_keys($data), ['name', 'description', 'client_id', 'tasks', 'delete_ids']), 422, 'Supply supported project fields only.');
        $request->validate(['delete_ids' => ['sometimes', 'array', 'list'], 'delete_ids.*' => ['string', 'max:40', 'distinct']]);
        if (! array_key_exists('tasks', $data)) {
            $data['tasks'] = DB::table('tasks')->where('project_id', $id)->whereNotIn('id', $data['delete_ids'] ?? [])->orderBy('id')->get(['id'])->map(fn ($row) => (array) $row)->all();
        }
        $data += ['delete_ids' => []];
        $project = $operations->project(new HttpRequest(array_replace($current, $data)), $actor, $id);
        return ['message' => 'Project updated successfully.', 'project' => array_intersect_key($project, array_flip(['id', 'name', 'description', 'client_id', 'status'])),
            'task_ids' => DB::table('tasks')->where('project_id', $id)->pluck('id')->all(), 'deleted_task_ids' => $data['delete_ids']];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'name' => $schema->string(),
            'description' => $schema->string()->nullable(),
            'client_id' => $schema->string()->nullable(),
            'tasks' => $schema->array()->items($schema->object(TaskFields::projectTask($schema))->withoutAdditionalProperties())->min(1)->description('Partial edits with id, or new tasks with title; omitted tasks are retained.'),
            'delete_ids' => $schema->array()->items($schema->string())->unique()->description('Task IDs belonging to this project to delete in the same transaction.'),
        ];
    }
}
