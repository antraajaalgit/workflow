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

#[Description('Update the progress of an existing Karya task. Only authenticated and allowed Karya administrators may use this tool.')]
class UpdateTaskProgressTool extends Tool
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
            'progress' => [
                'required',
                'string',
                'in:just_started,25,50,75,completed',
            ],
        ], [
            'task_id.required' => 'A task ID is required.',
            'progress.required' => 'Task progress is required.',
            'progress.in' => 'Progress must be one of: just_started, 25, 50, 75, completed.',
        ]);

        $taskId = $validated['task_id'];
        $progress = $validated['progress'];

        $httpRequest = HttpRequest::create(
            "/api/tasks/{$taskId}/progress",
            'PATCH',
            [
                'progress' => $progress,
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
                'progress'
            ));
        } catch (\Throwable $e) {
            return Response::error(
                'Unable to update task progress. Check the supplied fields and application server.'
            );
        }

        $task = $result['task'] ?? $result;

        return Response::structured([
            'success' => true,
            'message' => 'Task progress updated successfully.',
            'actor' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
            ],
            'task' => [
                'id' => $task['id'] ?? $taskId,
                'title' => $task['title'] ?? null,
                'status' => $task['status'] ?? null,
                'progress' => $task['progress'] ?? $progress,
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
                ->description('The ID of the Karya task whose progress should be changed.')
                ->required(),

            'progress' => $schema->string()
                ->enum([
                    'just_started',
                    '25',
                    '50',
                    '75',
                    'completed',
                ])
                ->description('The new progress value: just_started, 25, 50, 75, or completed.')
                ->required(),
        ];
    }
}