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

#[Description('Create a new task in Karya. The task may optionally be assigned to one or more team members/admins and linked to a project or client. Only authenticated and allowed Karya administrators may use this tool.')]
class CreateTaskTool extends Tool
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
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:40',
            ],
            'client_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:40',
            ],
            'department' => [
                'sometimes',
                'string',
                'max:40',
            ],
            'owner_ids' => [
                'sometimes',
                'array',
            ],
            'owner_ids.*' => [
                'required',
                'string',
                'max:40',
                'distinct',
            ],
            'status' => [
                'sometimes',
                'string',
                'in:new,todo,in_progress,review,done,blocked',
            ],
            'priority' => [
                'sometimes',
                'string',
                'in:low,med,high',
            ],
            'progress' => [
                'sometimes',
                'string',
                'in:just_started,25,50,75,completed',
            ],
            'due_date_ms' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],
            'recurring' => [
                'sometimes',
                'nullable',
                'string',
                'in:daily,alternate_days,weekly,monthly',
            ],
        ]);

        $httpRequest = HttpRequest::create(
            '/api/tasks',
            'POST',
            $validated
        );

        $httpRequest->setUserResolver(
            fn () => $actor
        );

        try {
            $task = \App\Mcp\AdminAccess::run($request, fn ($actor) => $taskService->create(
                $httpRequest,
                $actor
            ));
        } catch (\Throwable $e) {
            return Response::error(
                'Unable to create task. Check the supplied fields and application server.'
            );
        }

        return Response::structured([
            'success' => true,
            'message' => 'Task created successfully.',
            'actor' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
            ],
            'task' => [
                'id' => $task['id'] ?? null,
                'title' => $task['title'] ?? null,
                'description' => $task['description'] ?? null,
                'project_id' => $task['project_id'] ?? null,
                'client_id' => $task['client_id'] ?? null,
                'department' => $task['department'] ?? null,
                'owner_id' => $task['owner_id'] ?? null,
                'owner_ids' => $task['owner_ids'] ?? [],
                'status' => $task['status'] ?? null,
                'priority' => $task['priority'] ?? null,
                'progress' => $task['progress'] ?? null,
                'due_date_ms' => $task['due_date_ms'] ?? null,
                'recurring' => $task['recurring'] ?? null,
            ],
            'assignment_mail_failures' => array_merge($task['assignmentMailFailures'] ?? [], $taskService->deferredMailFailures),
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
            'title' => $schema->string()
                ->description('The title of the new Karya task.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional detailed description of the task.'),

            'project_id' => $schema->string()
                ->description('Optional Karya project ID to associate with the task.'),

            'client_id' => $schema->string()
                ->description('Optional Karya client ID to associate with the task.'),

            'department' => $schema->string()
                ->description('Optional department name. If omitted, Karya defaults to General.'),

            'owner_ids' => $schema->array()
                ->items(
                    $schema->string()
                        ->description('Karya user ID of a task assignee.')
                )
                ->unique()
                ->description('Optional list of Karya user IDs to assign to the task.'),

            'status' => $schema->string()
                ->enum([
                    'new',
                    'todo',
                    'in_progress',
                    'review',
                    'done',
                    'blocked',
                ])
                ->description('Optional initial task status. Defaults to todo.'),

            'priority' => $schema->string()
                ->enum([
                    'low',
                    'med',
                    'high',
                ])
                ->description('Optional task priority. Defaults to med.'),

            'progress' => $schema->string()
                ->enum([
                    'just_started',
                    '25',
                    '50',
                    '75',
                    'completed',
                ])
                ->description('Optional initial progress. Defaults to just_started.'),

            'due_date_ms' => $schema->integer()
                ->description('Optional task due date as a Unix timestamp in milliseconds.'),

            'recurring' => $schema->string()
                ->enum([
                    'daily',
                    'alternate_days',
                    'weekly',
                    'monthly',
                ])
                ->description('Optional recurrence schedule for the task.'),
        ];
    }
}