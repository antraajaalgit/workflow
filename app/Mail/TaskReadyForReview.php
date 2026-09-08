<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TaskReadyForReview extends Mailable
{
    public function __construct(
        public array $task,
        public ?string $projectName,
        public ?string $clientName,
        public array $assignees,
    ) {}

    public function build(): static
    {
        $dueDate = filled($this->task['due_date_ms'] ?? null)
            ? date('d M Y', (int) floor($this->task['due_date_ms'] / 1000)) : 'No due date';
        $priority = ['low' => 'Low', 'med' => 'Medium', 'high' => 'High'][$this->task['priority']] ?? $this->task['priority'];
        $taskUrl = rtrim(config('app.url'), '/').'/?'.http_build_query(['task' => $this->task['id']], '', '&', PHP_QUERY_RFC3986);

        return $this->from(config('mail.chat_from.address'), config('mail.chat_from.name'))
            ->subject('Task Ready for Review: '.$this->task['title'])
            ->view('emails.task-ready-for-review-html', compact('dueDate', 'priority', 'taskUrl'))
            ->text('emails.task-ready-for-review', compact('dueDate', 'priority', 'taskUrl'));
    }
}
