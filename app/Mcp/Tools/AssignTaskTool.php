<?php

namespace App\Mcp\Tools;

use App\Services\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[Description('Assign an existing Karya task to one or more team members or eligible admins. Uses Karya task business logic so assignment history, validation, notifications, and emails remain consistent with the dashboard.')]
class AssignTaskTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, TaskService $taskService): Response|ResponseFactory
    {
        /*
         * Local MCP testing:
         *
         * KARYA_MCP_ALLOWED_ADMIN_IDS contains every admin allowed
         * to use MCP.
         *
          * The MCP actor is resolved from the authenticated
         * Passport/OAuth user attached to the MCP request.
         * simulated through MCP Inspector.
         */
        try {
            $actor = \App\Mcp\AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $validated = $request->validate([
            'task_id' => ['required', 'string', 'exists:tasks,id'],
            'assignee_ids' => ['present', 'array', 'list'],
            'assignee_ids.*' => ['required', 'string', 'distinct', 'exists:users,id'],
        ]);



        /*
         * TaskService expects Illuminate\Http\Request,
         * not Laravel\Mcp\Request.
         *
         * Translate Claude's MCP arguments into the same format
         * expected by the existing task API/business logic.
         */
        $httpRequest = HttpRequest::create(
            '/',
            'PATCH',
            [
                'owner_ids' => array_values($validated['assignee_ids']),
            ]
        );

        try {
            $task = \App\Mcp\AdminAccess::run($request, fn ($actor) => $taskService->update(
                $validated['task_id'],
                $httpRequest,
                $actor,
                'assignees'
            ));
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())
                ->flatten()
                ->implode(' ');

            return Response::error(
                'Task assignment validation failed: '.$messages
            );
        } catch (HttpExceptionInterface $exception) {
            return Response::error(
                $exception->getMessage() ?: 'Task assignment was not permitted.'
            );
        } catch (\Throwable $exception) {
            return Response::error('Unable to assign task. Check the application server.');
        }

        return Response::structured([
            'success' => true,

            'message' => 'Task assigned successfully.',

            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
            ],

            'task' => [
                'id' => $task['id'],
                'title' => $task['title'],
                'owner_id' => $task['owner_id'],
                'owner_ids' => $task['owner_ids'],
                'status' => $task['status'],
                'progress' => $task['progress'],
            ],

            'assignment_mail_failures' =>
                array_merge($task['assignmentMailFailures'] ?? [], $taskService->deferredMailFailures),
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
                ->description(
                    'The existing Karya task ID to assign, for example t_abc1234.'
                )
                ->required(),

            'assignee_ids' => $schema->array()
                ->items(
                    $schema->string()
                )
                ->min(0)
                ->unique()
                ->description(
                    'Assignee user IDs; an empty array clears all assignments. ListTeamMembersTool with include_admins resolves eligible admin IDs.'
                )
                ->required(),
        ];
    }
}