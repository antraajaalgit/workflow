Hello,

A task is ready for review in Karya.

Task: {!! $task['title'] !!}

Project: {!! $projectName ?? 'Standalone task' !!}

Client: {!! $clientName ?? 'No client' !!}

Assignees: {!! $assignees ? implode(', ', $assignees) : 'Unassigned' !!}

Current status: Review
Priority: {!! $priority !!}

Due date: {!! $dueDate !!}

Task ID: {!! $task['id'] !!}

@if(filled($task['description'] ?? null))
Description:
{!! $task['description'] !!}

@endif
Review Task: {!! $taskUrl !!}
