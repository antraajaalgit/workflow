<!doctype html>
<html lang="en">
<body style="font-family:Arial,sans-serif;color:#292524;line-height:1.6">
<h1 style="font-size:24px">Task Ready for Review</h1>
<p>Hello,</p>
<p>A task is ready for review in Karya.</p>
<p><strong>Task:</strong> {{ $task['title'] }}<br>
<strong>Project:</strong> {{ $projectName ?? 'Standalone task' }}<br>
<strong>Client:</strong> {{ $clientName ?? 'No client' }}<br>
<strong>Assignees:</strong> {{ $assignees ? implode(', ', $assignees) : 'Unassigned' }}<br>
<strong>Current status:</strong> Review<br>
<strong>Priority:</strong> {{ $priority }}<br>
<strong>Due date:</strong> {{ $dueDate }}<br>
<strong>Task ID:</strong> {{ $task['id'] }}</p>
@if(filled($task['description'] ?? null))
<p><strong>Description:</strong><br>{!! nl2br(e($task['description'])) !!}</p>
@endif
<p style="margin:28px 0"><a href="{{ $taskUrl }}" style="display:inline-block;background:#4338ca;color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">Review Task</a></p>
<p>If the button does not work, open this link:<br><a href="{{ $taskUrl }}">{{ $taskUrl }}</a></p>
</body>
</html>
