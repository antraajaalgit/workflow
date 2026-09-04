<?php

namespace App\Services;

use App\Mail\TaskAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TaskEffects
{
    public function owners(array $task): array
    {
        $ids = $task['owner_ids'] ?? $task['ownerIds'] ?? null;
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        return array_values(array_unique($ids ?: array_filter([$task['owner_id'] ?? $task['ownerId'] ?? null])));
    }

    /** Capture recipients while the write is locked; send only after commit. */
    public function assignments(?array $before, array $after): array
    {
        $added = array_diff($this->owners($after), $before ? $this->owners($before) : []);
        $projectId = $after['project_id'] ?? $after['projectId'] ?? null;
        $project = $projectId ? DB::table('projects')->where('id', $projectId)->first() : null;
        $task = $after + ['description' => $after['desc'] ?? null, 'due_date_ms' => $after['dueDate'] ?? null];

        return DB::table('users')->whereIn('id', $added)->where('role', 'team')->get()
            ->filter(fn ($member) => filled($member->email))
            ->map(fn ($member) => ['task' => $task, 'member' => (array) $member, 'project' => $project ? (array) $project : null])->all();
    }

    public function sendAssignments(array $assignments): array
    {
        $failures = [];
        foreach ($assignments as $assignment) {
            try {
                Mail::mailer('chat_smtp')->to($assignment['member']['email'], $assignment['member']['name'])
                    ->send(new TaskAssignment($assignment['task'], $assignment['member'], $assignment['project']));
            } catch (\Throwable $exception) {
                $failures[] = $assignment['member']['email'];
                Log::warning('Karya task assignment email could not be sent.', [
                    'task_id' => $assignment['task']['id'], 'recipient' => $assignment['member']['email'], 'error' => $exception->getMessage(),
                ]);
            }
        }

        return $failures;
    }

    /** API equivalents of persisted dashboard history and simulated outbound notifications. */
    public function record(?array $before, ?array $after, object $actor): void
    {
        $old = $before;
        $new = $after;
        if ($old) {
            unset($old['updated_at']);
        }
        if ($new) {
            unset($new['updated_at']);
        }
        if ($old === $new) {
            return;
        }
        $task = $after ?? $before;
        $client = ! empty($task['client_id']) ? DB::table('clients')->where('id', $task['client_id'])->first() : null;
        $action = ! $before ? 'created' : (! $after ? 'deleted' : 'updated');
        $type = ! $before ? 'brief' : 'info';
        $text = "{$actor->name} {$action} \"{$task['title']}\"";
        if ($before && $after && $before['status'] !== $after['status']) {
            $text = "{$actor->name} moved \"{$task['title']}\" to ".str_replace('_', ' ', $task['status']);
            $type = 'move';
            if ($task['status'] === 'done' && $client) {
                $this->notify($client->phone, $client->email,
                    "🎉 Good news {$client->name}! \"{$task['title']}\" is completed. Please review.", "Subject: Completed — {$task['title']}");
            }
        } elseif ($before && $after && $this->owners($before) !== $this->owners($after)) {
            $text = "{$actor->name} reassigned \"{$task['title']}\" to ".implode(', ', $this->owners($after));
        } elseif ($before && $after && $before['progress'] !== $after['progress']) {
            $text = "{$actor->name} changed progress of \"{$task['title']}\" to {$task['progress']}";
        }
        if (! $before && $actor->role === 'client' && $client) {
            $text = "New brief from {$client->company} awaiting assignment";
            $this->notify('+91 agency-team', 'team@antrajaal.com',
                "📥 New request from {$client->name} ({$client->company}): \"{$task['title']}\" → awaiting assignment", "Subject: New brief — {$task['title']}");
            $this->notify($client->phone, $client->email,
                "✅ Hi {$client->name}, we received \"{$task['title']}\". An admin will assign it shortly.", "Subject: We got your request — {$task['title']}");
        }
        DB::table('activities')->insert(['id' => (string) Str::uuid(), 'text' => $text, 'type' => $type,
            'occurred_at_ms' => now()->getTimestampMs(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notify(?string $phone, ?string $email, string $waText, string $emailText): void
    {
        foreach (['whatsapp' => [$phone, $waText], 'email' => [$email, $emailText]] as $channel => [$to, $text]) {
            if (! $to) {
                continue;
            }
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'channel' => $channel,
                'recipient' => $to, 'text' => $text, 'sent_at_ms' => now()->getTimestampMs(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
