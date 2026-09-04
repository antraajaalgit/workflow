<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TaskAssignment extends Mailable
{
    public function __construct(public array $task, public array $member, public ?array $project) {}

    public function build(): static
    {
        $task = $this->task;
        $member = $this->member;
        $projectName = $this->project['name'] ?? 'Standalone task';
        $dueDate = filled($task['due_date_ms'] ?? null) ? date('d M Y', (int) floor($task['due_date_ms'] / 1000)) : 'No due date';
        $status = ucfirst(str_replace('_', ' ', $task['status'] ?? 'todo'));
        $lines = ["Hello {$member['name']},", '', 'A new task has been assigned to you in Karya.', '',
            "Task: {$task['title']}", "Project: {$projectName}", "Status: {$status}", "Due date: {$dueDate}"];
        if (filled($task['description'] ?? null)) {
            $lines[] = "Description: {$task['description']}";
        }
        $lines[] = '';
        $lines[] = 'Open Karya to view the task: '.url('/');

        return $this->from(config('mail.chat_from.address'), config('mail.chat_from.name'))
            ->subject("New task assigned: {$task['title']}")
            ->text('emails.task-assignment', ['body' => implode("\n", $lines)]);
    }
}
