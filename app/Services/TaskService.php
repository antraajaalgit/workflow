<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public array $deferredMailFailures = [];

    private function deliverAssignments(array $assignments): array
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($assignments) {
                $this->deferredMailFailures = array_merge($this->deferredMailFailures,
                    app(TaskEffects::class)->sendAssignments($assignments));
            });
            return [];
        }
        return app(TaskEffects::class)->sendAssignments($assignments);
    }
    public function find(string $id, bool $lock = false): array
    {
        $query = DB::table('tasks')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404, 'Task not found.');
        $task = (array) $row;
        $task['owner_ids'] = json_decode($task['owner_ids'] ?? '[]', true) ?: ($task['owner_id'] ? [$task['owner_id']] : []);
        $task['owner_id'] = $task['owner_ids'][0] ?? null;
        $task['attachments'] = json_decode($task['attachments'] ?? '[]', true) ?: [];
        foreach (['created_at_ms', 'stage_at_ms', 'due_date_ms', 'next_recurrence_at_ms'] as $field) {
            $task[$field] = $task[$field] === null ? null : (int) $task[$field];
        }

        return $task;
    }

    public function create(Request $request, object $actor): array
    {

        $result = app(StateConcurrency::class)->run(function () use ($request, $actor) {
            $actor = $this->freshActor($actor);
            abort_unless(in_array($actor->role, ['admin', 'team', 'client'], true), 403);
            $data = $this->validated($request, true);
            if ($actor->role === 'client') {
                $allowed = ['id', 'title', 'description', 'priority', 'due_date_ms', 'attachments', 'client_id'];
                abort_if(array_diff(array_keys($data), $allowed), 403, 'Clients can only submit their own briefs.');
                abort_unless($actor->client_id && (! isset($data['client_id']) || $data['client_id'] === $actor->client_id), 403);
                $data['client_id'] = $actor->client_id;
                $data['status'] = 'new';
                $data['department'] = DB::table('departments')->orderBy('name')->value('name') ?? 'General';
                if (! array_key_exists('due_date_ms', $data)) {
                    $data['due_date_ms'] = now()->addDay()->getTimestampMs();
                }
            } elseif ($actor->role !== 'admin') {
                abort_if(array_intersect(array_keys($data), ['recurring', 'next_recurrence_at_ms']), 403);
            }
            $now = now()->getTimestampMs();
            if (! isset($data['id'])) {
                do {
                    $data['id'] = 't_'.substr(str_pad(base_convert((string) random_int(0, 78364164095), 10, 36), 7, '0', STR_PAD_LEFT), -7);
                } while (DB::table('tasks')->where('id', $data['id'])->exists());
            }
            $data += ['client_id' => null, 'project_id' => null, 'description' => null,
                'department' => 'General', 'owner_id' => null, 'owner_ids' => [], 'status' => 'todo',
                'priority' => 'med', 'progress' => 'just_started', 'created_at_ms' => $now,
                'stage_at_ms' => $now, 'due_date_ms' => null, 'recurring' => null,
                'next_recurrence_at_ms' => null, 'attachments' => []];
            $data = $this->relationships($data);
            DB::table('tasks')->insert($this->encode($data) + ['created_at' => now(), 'updated_at' => now()]);

            $after = $this->find($data['id']);
            app(TaskEffects::class)->record(null, $after, $actor);

            return ['task' => $after, 'assignments' => app(TaskEffects::class)->assignments(null, $after)];
        });

        return $result['task'] + ['assignmentMailFailures' => $this->deliverAssignments($result['assignments'])];
    }

    public function update(string $id, Request $request, object $actor, ?string $operation = null): array
    {
        $result = app(StateConcurrency::class)->run(function () use ($id, $request, $actor, $operation) {
            $actor = $this->freshActor($actor);
            $task = $this->find($id, true);
            $this->authorize($actor, $task);
            $data = $this->validated($request, false, $operation);
            if ($actor->role !== 'admin') {
                abort_if(array_intersect(array_keys($data), ['client_id', 'project_id', 'department', 'recurring', 'next_recurrence_at_ms']), 403, 'Admin access required for these fields.');
            }
            if (isset($data['status']) && $data['status'] !== $task['status']) {
                $data['stage_at_ms'] = now()->getTimestampMs();
            }
            $merged = $this->relationships(array_replace($task, $data), $task);
            // Only write approved fields plus derived relationship/schedule values.
            foreach (['client_id', 'next_recurrence_at_ms'] as $field) {
                if ($merged[$field] !== $task[$field]) {
                    $data[$field] = $merged[$field];
                }
            }
            if ($data) {
                $data += ['owner_id' => $merged['owner_id'], 'owner_ids' => $merged['owner_ids']];
                DB::table('tasks')->where('id', $id)->update($this->encode($data) + ['updated_at' => now()]);
            }

            $after = $this->find($id);
            app(TaskEffects::class)->record($task, $after, $actor);

            return ['task' => $after, 'assignments' => app(TaskEffects::class)->assignments($task, $after)];
        });

        return $result['task'] + ['assignmentMailFailures' => $this->deliverAssignments($result['assignments'])];
    }

    public function delete(string $id, object $actor): void
    {
        app(StateConcurrency::class)->run(function () use ($id, $actor) {
            $actor = $this->freshActor($actor);
            $task = $this->find($id, true);
            $this->authorize($actor, $task);
            // Preserve task conversations, matching the dashboard and foreign key.
            DB::table('messages')->where('task_id', $id)->update(['task_id' => null]);
            DB::table('tasks')->where('id', $id)->delete();
            app(TaskEffects::class)->record($task, null, $actor);
        });
    }

    private function freshActor(object $actor): object
    {
        // A state save may have changed/deleted the account while this request waited for the lock.
        $current = DB::table('users')->where('id', $actor->id)->first();
        abort_unless($current, 401, 'Please sign in.');

        return $current;
    }

    private function authorize(object $actor, array $task): void
    {
        abort_unless($actor->role === 'admin' || ($actor->role === 'team' && in_array($actor->id, $task['owner_ids'], true)), 403, 'You cannot manage this task.');
    }

    private function validated(Request $request, bool $create, ?string $operation = null): array
    {
        $rules = [
            'title' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:40', 'exists:clients,id'],
            'project_id' => ['sometimes', 'nullable', 'string', 'max:40', 'exists:projects,id'],
            'department' => ['sometimes', 'required', 'string', 'max:40'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'max:40', 'exists:users,id'],
            'owner_ids' => ['sometimes', 'array', 'list'],
            'owner_ids.*' => ['required', 'string', 'max:40', 'distinct', 'exists:users,id'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['new', 'todo', 'in_progress', 'review', 'done', 'blocked'])],
            'priority' => ['sometimes', 'required', 'string', Rule::in(['low', 'med', 'high'])],
            'progress' => ['sometimes', 'required', 'string', Rule::in(['just_started', '25', '50', '75', 'completed'])],
            'due_date_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'recurring' => ['sometimes', 'nullable', Rule::in(['daily', 'alternate_days', 'weekly', 'monthly'])],
            'next_recurrence_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'attachments' => ['sometimes', 'array', 'list'],
            'attachments.*' => ['array:id,name,type,size,data'],
            'attachments.*.id' => ['required', 'string', 'max:40', 'regex:/\\A[A-Za-z0-9_-]+\\z/'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.type' => ['required', 'string', 'max:255'],
            'attachments.*.size' => ['required', 'integer', 'min:0'],
            'attachments.*.data' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! preg_match('~\A(?:data:[a-zA-Z0-9.+/-]+;base64,[A-Za-z0-9+/=\r\n]*|/api/chat-attachment\?file=[a-zA-Z0-9.%_-]+)\z~', $value)) {
                    $fail('Attachment data must be a base64 data URI or a local chat attachment URL.');
                }
            }],
        ];
        if ($create) {
            $rules['id'] = ['sometimes', 'required', 'string', 'max:40', 'regex:/\\A[A-Za-z0-9_-]+\\z/', 'unique:tasks,id'];
            foreach (['created_at_ms', 'stage_at_ms'] as $field) {
                $rules[$field] = ['sometimes', 'integer', 'min:0', 'max:9007199254740991'];
            }
        }
        if ($operation) {
            $fields = $operation === 'assignees' ? ['owner_id', 'owner_ids', 'owner_ids.*'] : [$operation];
            $rules = array_intersect_key($rules, array_flip($fields));
            if ($operation !== 'assignees') {
                $rules[$operation][0] = 'required';
            }
        }
        $data = $request->validate($rules);
        if ($operation === 'assignees' && ! array_key_exists('owner_id', $data) && ! array_key_exists('owner_ids', $data)) {
            throw ValidationException::withMessages(['owner_ids' => 'Supply owner_id or owner_ids.']);
        }
        if (array_key_exists('owner_ids', $data) || array_key_exists('owner_id', $data)) {
            // The explicit assignee list takes precedence over the legacy owner_id.
            $owners = $data['owner_ids'] ?? (isset($data['owner_id']) ? [$data['owner_id']] : []);
            foreach (array_unique(array_merge($owners, isset($data['owner_id']) ? [$data['owner_id']] : [])) as $id) {
                $user = DB::table('users')->where('id', $id)->first();
                if (! $user || $user->id !== $id || ! ($user->role === 'team' || ($user->role === 'admin' && filled($user->email)))) {
                    throw ValidationException::withMessages(['owner_ids' => 'Tasks can only be assigned to admins with email or team members.']);
                }
            }
            $data['owner_ids'] = array_values($owners);
            $data['owner_id'] = $owners[0] ?? null;
        }

        return $data;
    }

    private function relationships(array $data, ?array $previous = null): array
    {
        if ($data['project_id']) {
            $client = DB::table('projects')->where('id', $data['project_id'])->value('client_id');
            if ($client) {
                $data['client_id'] = $client;
            }
        }
        if (! $data['recurring']) {
            $data['next_recurrence_at_ms'] = null;
        } elseif (! $data['next_recurrence_at_ms'] || ($previous && $previous['recurring'] !== $data['recurring'])) {
            $data['next_recurrence_at_ms'] = app(RecurringTaskGenerator::class)->nextRunMs(now()->getTimestampMs(), $data['recurring']);
        }

        return $data;
    }

    private function encode(array $data): array
    {
        foreach (['owner_ids', 'attachments'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}
