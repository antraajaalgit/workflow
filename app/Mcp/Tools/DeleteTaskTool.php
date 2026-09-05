<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Permanently delete an existing Karya task. Only authenticated and allowed Karya administrators may use this tool.')]
class DeleteTaskTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, TaskService $taskService)
    {
        try {
            $actor = \App\Mcp\AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $validated = $request->validate([
            'task_id' => [
                'required',
                'string',
                'max:40',
            ],
        ], [
            'task_id.required' => 'A task ID is required.',
        ]);

        $taskId = $validated['task_id'];

        try {
            $task = \App\Mcp\AdminAccess::run($request, function ($actor) use ($taskService, $taskId) {
                $task = $taskService->find($taskId);
                $taskService->delete($taskId, $actor);
                return $task;
            });
        } catch (\Throwable $e) {
            return Response::error(
                'Unable to delete task. Check the supplied fields and application server.'
            );
        }

        return Response::structured([
            'success' => true,
            'message' => 'Task deleted successfully.',
            'actor' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
            ],
            'deleted_task' => [
                'id' => $task['id'] ?? $taskId,
                'title' => $task['title'] ?? null,
            ],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->string()
                ->description('The ID of the Karya task to permanently delete.')
                ->required(),
        ];
    }
}
