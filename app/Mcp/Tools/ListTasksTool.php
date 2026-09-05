<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List all tasks available in the Karya workflow system. This is a read-only tool used by Claude to identify tasks before performing admin actions.')]
class ListTasksTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $actor = \App\Mcp\AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $tasks = DB::table('tasks')
            ->get()
            ->map(fn ($task) => (array) $task)
            ->values()
            ->all();

        return Response::structured([
            'count' => count($tasks),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}