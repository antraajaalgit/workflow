<?php

namespace App\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;

final class TaskFields
{
    public static function editable(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Task title, at most 255 characters.'),
            'description' => $schema->string()->nullable()->description('Detailed description; null clears it.'),
            'priority' => $schema->string()->enum(['low', 'med', 'high']),
            'department' => $schema->string()->description('Department name from ListDepartmentsTool, at most 40 characters.'),
            'project_id' => $schema->string()->nullable()->description('Project ID; null detaches. A linked project may determine the client.'),
            'client_id' => $schema->string()->nullable()->description('Client ID; null clears unless a linked project supplies a client.'),
            'due_date_ms' => $schema->integer()->min(0)->max(9007199254740991)->nullable()->description('Unix milliseconds; null clears the due date.'),
            'recurring' => $schema->string()->enum(['daily', 'alternate_days', 'weekly', 'monthly'])->nullable()->description('Recurrence; null disables it.'),
            'next_recurrence_at_ms' => $schema->integer()->min(0)->max(9007199254740991)->nullable()->description('Next recurrence in Unix milliseconds; Karya derives it when omitted or null.'),
        ];
    }

    public static function projectTask(JsonSchema $schema): array
    {
        return self::editable($schema) + [
            'id' => $schema->string()->description('Existing task ID in this project; omit to create a task with a required title.'),
            'owner_ids' => $schema->array()->items($schema->string())->unique()->description('Assignee user IDs; an empty array unassigns all.'),
            'status' => $schema->string()->enum(['new', 'todo', 'in_progress', 'review', 'done', 'blocked']),
            'progress' => $schema->string()->enum(['just_started', '25', '50', '75', 'completed']),
        ];
    }
}
