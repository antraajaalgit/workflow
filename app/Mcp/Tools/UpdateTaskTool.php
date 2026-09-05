<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit task title, description, priority, department, due date, project/client links or recurrence. Omitted fields stay unchanged; use the dedicated assignment, status and progress tools for those changes.')]
class UpdateTaskTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['task_id' => ['required', 'string', 'max:40']])['task_id'];
        $fields = array_diff_key($request->all(), ['task_id' => true]);
        $allowed = ['title', 'description', 'priority', 'department', 'project_id', 'client_id', 'due_date_ms', 'recurring', 'next_recurrence_at_ms'];
        abort_if(! $fields || array_diff(array_keys($fields), $allowed), 422, 'Supply only normal editable task fields; use dedicated tools for assignment, status and progress.');
        $task = $operations->tasks->update($id, new HttpRequest($fields), $actor);
        return ['message' => 'Task updated successfully.', 'task' => array_intersect_key($task, array_flip(array_merge(['id', 'owner_ids', 'status', 'progress'], $allowed)))];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['task_id' => $schema->string()->required()] + TaskFields::editable($schema);
    }
}
