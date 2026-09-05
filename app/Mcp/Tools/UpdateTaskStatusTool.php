<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the status of an existing Karya task. Only authenticated and allowed Karya administrators may use this tool.')]
class UpdateTaskStatusTool extends Tool
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
            ],
            'status' => [
                'required',
                'string',
                'in:new,todo,in_progress,review,done,blocked',
            ],
        ], [
            'task_id.required' => 'A task ID is required.',
            'status.required' => 'A task status is required.',
            'status.in' => 'Status must be one of: new, todo, in_progress, review, done, blocked.',
        ]);

        $taskId = $validated['task_id'];
        $status = $validated['status'];

        $httpRequest = HttpRequest::create(
            "/api/tasks/{$taskId}/status",
            'PATCH',
            [
                'status' => $status,
            ]
        );

        $httpRequest->setUserResolver(
            fn () => $actor
        );

        try {
            $result = \App\Mcp\AdminAccess::run($request, fn ($actor) => $taskService->update(
                $taskId,
                $httpRequest,
                $actor,
                'status'
            ));
        } catch (\Throwable $e) {
            return Response::error(
                'Unable to update task status. Check the supplied fields and application server.'
            );
        }

        $task = $result['task'] ?? $result;

        return Response::structured([
            'success' => true,
            'message' => 'Task status updated successfully.',
            'actor' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
            ],
            'task' => [
                'id' => $task['id'] ?? $taskId,
                'title' => $task['title'] ?? null,
                'status' => $task['status'] ?? $status,
                'progress' => $task['progress'] ?? null,
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
                ->description('The ID of the Karya task whose status should be changed.')
                ->required(),

            'status' => $schema->string()
                ->enum([
                    'new',
                    'todo',
                    'in_progress',
                    'review',
                    'done',
                    'blocked',
                ])
                ->description('The new status for the task.')
                ->required(),
        ];
    }
}