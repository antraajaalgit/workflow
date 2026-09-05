<?php

namespace App\Mcp\Tools;

use App\Services\DashboardTaskOperations;
use App\Services\StateConcurrency;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new Karya project together with its initial task. Only authenticated and allowed Karya administrators may use this tool.')]
class CreateProjectTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(
        Request $request,
        DashboardTaskOperations $operations,
        StateConcurrency $concurrency
    ) {
        try {
            $actor = \App\Mcp\AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'client_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:40',
            ],

            'task_title' => [
                'required',
                'string',
                'max:255',
            ],

            'task_description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'task_department' => [
                'sometimes',
                'string',
                'max:40',
            ],

            'task_owner_ids' => [
                'sometimes',
                'array',
            ],

            'task_owner_ids.*' => [
                'required',
                'string',
                'max:40',
                'distinct',
            ],

            'task_status' => [
                'sometimes',
                'string',
                'in:new,todo,in_progress,review,done,blocked',
            ],

            'task_priority' => [
                'sometimes',
                'string',
                'in:low,med,high',
            ],

            'task_progress' => [
                'sometimes',
                'string',
                'in:just_started,25,50,75,completed',
            ],

            'task_due_date_ms' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'task_recurring' => [
                'sometimes',
                'nullable',
                'string',
                'in:daily,alternate_days,weekly,monthly',
            ],
        ]);

        $task = [
            'title' => $validated['task_title'],
        ];

        $taskFields = [
            'task_description' => 'description',
            'task_department' => 'department',
            'task_owner_ids' => 'owner_ids',
            'task_status' => 'status',
            'task_priority' => 'priority',
            'task_progress' => 'progress',
            'task_due_date_ms' => 'due_date_ms',
            'task_recurring' => 'recurring',
        ];

        foreach ($taskFields as $source => $destination) {
            if (array_key_exists($source, $validated)) {
                $task[$destination] = $validated[$source];
            }
        }

        $projectPayload = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'tasks' => [$task],
            'delete_ids' => [],
        ];

        $httpRequest = HttpRequest::create(
            '/api/projects',
            'POST',
            $projectPayload
        );

        $httpRequest->setUserResolver(
            fn () => $actor
        );

        $operations->tasks->deferredMailFailures = [];
        try {
            $project = $concurrency->run(function () use (
                $operations,
                $httpRequest,
                $request
            ) {
                $freshActor = \App\Mcp\AdminAccess::actor($request);

                return $operations->project(
                    $httpRequest,
                    $freshActor,
                    null
                );
            });
        } catch (\Throwable $e) {
            return Response::error(
                'Unable to create project. Check the supplied fields and application server.'
            );
        }

        return Response::structured([
            'success' => true,
            'message' => 'Project created successfully.',
            'actor' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
            ],
            'project' => [
                'id' => $project['id'] ?? null,
                'name' => $project['name'] ?? $validated['name'],
                'description' => $project['description'] ?? null,
                'client_id' => $project['client_id'] ?? null,
                'status' => $project['status'] ?? null,
            ],
            'initial_task' => [
                'title' => $validated['task_title'],
                'owner_ids' => $validated['task_owner_ids'] ?? [],
                'priority' => $validated['task_priority'] ?? 'med',
                'status' => $validated['task_status'] ?? 'todo',
                'progress' => $validated['task_progress'] ?? 'just_started',
            ],
            'assignment_mail_failures' =>
                $operations->tasks->deferredMailFailures,
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
            'name' => $schema->string()
                ->description('Name of the new Karya project.')
                ->required(),

            'description' => $schema->string()
                ->description('Optional project description.'),

            'client_id' => $schema->string()
                ->description('Optional Karya client ID to associate with the project.'),

            'task_title' => $schema->string()
                ->description('Title of the initial task that will be created inside the project.')
                ->required(),

            'task_description' => $schema->string()
                ->description('Optional description for the initial task.'),

            'task_department' => $schema->string()
                ->description('Optional department for the initial task.'),

            'task_owner_ids' => $schema->array()
                ->items(
                    $schema->string()
                        ->description('Karya user ID of a task assignee.')
                )
                ->unique()
                ->description('Optional list of user IDs to assign to the initial task.'),

            'task_status' => $schema->string()
                ->enum([
                    'new',
                    'todo',
                    'in_progress',
                    'review',
                    'done',
                    'blocked',
                ])
                ->description('Initial task status. Defaults to todo.'),

            'task_priority' => $schema->string()
                ->enum([
                    'low',
                    'med',
                    'high',
                ])
                ->description('Initial task priority. Defaults to med.'),

            'task_progress' => $schema->string()
                ->enum([
                    'just_started',
                    '25',
                    '50',
                    '75',
                    'completed',
                ])
                ->description('Initial task progress. Defaults to just_started.'),

            'task_due_date_ms' => $schema->integer()
                ->description('Optional task due date as a Unix timestamp in milliseconds.'),

            'task_recurring' => $schema->string()
                ->enum([
                    'daily',
                    'alternate_days',
                    'weekly',
                    'monthly',
                ])
                ->description('Optional recurrence schedule for the initial task.'),
        ];
    }
}