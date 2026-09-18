<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Show team members with tasks that crossed their deadlines, each member\'s overdue task count, and task title, project, and due date. Admin-only and read-only.')]
class ListOverdueTeamTasksTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone)->getTimestampMs();
        $members = DB::table('users')->where('role', 'team')->orderBy('name')->get(['id', 'name']);
        $byId = $members->keyBy('id');
        $results = $members->mapWithKeys(fn ($member) => [$member->id => [
            'name' => $member->name, 'overdue_task_count' => 0, 'tasks' => [],
        ]])->all();

        $tasks = DB::table('tasks')->leftJoin('projects', 'tasks.project_id', '=', 'projects.id')
            ->whereNotNull('tasks.due_date_ms')->where('tasks.due_date_ms', '>', 0)
            ->where('tasks.due_date_ms', '<', $today)
            ->where('tasks.status', '!=', 'done')->where('tasks.progress', '!=', 'completed')
            ->orderBy('tasks.due_date_ms')->orderBy('tasks.id')
            ->get(['tasks.title', 'tasks.due_date_ms', 'tasks.owner_id', 'tasks.owner_ids', 'projects.name as project_name']);

        foreach ($tasks as $task) {
            $owners = json_decode($task->owner_ids ?? '[]', true) ?: ($task->owner_id ? [$task->owner_id] : []);
            $detail = [
                'title' => $task->title,
                'project_name' => $task->project_name,
                'due_date' => CarbonImmutable::createFromTimestampMs((int) $task->due_date_ms, $timezone)->toDateString(),
            ];
            foreach (array_unique($owners) as $ownerId) {
                if (! $byId->has($ownerId)) continue;
                $results[$ownerId]['tasks'][] = $detail;
                $results[$ownerId]['overdue_task_count']++;
            }
        }

        $results = array_values(array_filter($results, fn ($member) => $member['overdue_task_count'] > 0));
        return Response::structured(['count' => count($results), 'team_members' => $results]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
