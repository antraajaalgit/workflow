<?php

namespace App\Services;

use App\Http\Controllers\StateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** One user action, one transaction. Called only inside StateConcurrency::run. */
class DashboardTaskOperations
{
    public array $mailFailures = [];

    public function __construct(public TaskService $tasks) {}

    private function row(string $table, string $id): object
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row && $row->id === $id, 404);
        return $row;
    }

    private function changeTasks($query, array $fields, object $actor): void
    {
        foreach ($query->pluck('id') as $id) {
            $this->tasks->update($id, new Request($fields), $actor);
        }
    }

    

    private function activity(string $text, object $actor): void
{
    DB::table('activities')->insert([
        'id' => (string) Str::uuid(),
        'text' => ($actor->name ?? 'Unknown admin').' '.$text,
        'type' => 'brief',
        'occurred_at_ms' => now()->getTimestampMs(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

    //public function project(Request $request, object $actor, ?string $id): void
    public function project(Request $request, object $actor, ?string $id): array
    {
        $project = $id ? $this->row('projects', $id) : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string', 'exists:clients,id'],
            'tasks' => ['required', 'array', 'list', 'min:1'], 'tasks.*' => ['array'],
            'tasks.*.id' => ['nullable', 'string', 'max:40', 'distinct'],
            'delete_ids' => ['present', 'array', 'list'], 'delete_ids.*' => ['string', 'max:40', 'distinct'],
        ]);
        // TaskService validates each row's explicit field allowlist below.
        $data['tasks'] = $request->input('tasks');
        $id ??= 'p_'.Str::lower(Str::random(12));
        $values = ['name' => $data['name'], 'description' => $data['description'] ?? null,
            'client_id' => $data['client_id'] ?? null, 'due_date_ms' => null, 'updated_at' => now()];
        if ($project) DB::table('projects')->where('id', $id)->update($values);
        else DB::table('projects')->insert($values + ['id' => $id, 'status' => 'active', 'created_at' => now()]);
        $retained = array_filter(array_column($data['tasks'], 'id'));
        abort_if(array_intersect($retained, $data['delete_ids']), 422, 'A task cannot be edited and deleted together.');
        foreach ($data['delete_ids'] as $taskId) {
            abort_unless($this->tasks->find($taskId)['project_id'] === $id, 422, 'Task does not belong to this project.');
            $this->tasks->delete($taskId, $actor);
        }
        foreach ($data['tasks'] as $task) {
            $taskId = $task['id'] ?? null;
            unset($task['id']);
            $task['project_id'] = $id;
            $task['client_id'] = $values['client_id'];
            if ($taskId) {
                abort_unless($this->tasks->find($taskId)['project_id'] === $id, 422, 'Task does not belong to this project.');
                $this->tasks->update($taskId, new Request($task), $actor);
            } else $this->tasks->create(new Request($task), $actor);
        }
        // Also retain correct relationships for tasks not present in this form.
        $this->changeTasks(DB::table('tasks')->where('project_id', $id)->whereNotIn('id', $retained), ['client_id' => $values['client_id']], $actor);
        //  $this->activity('Project "'.$data['name'].'" '.($project ? 'updated' : 'created').' with '.count($data['tasks']).' assigned task'.(count($data['tasks']) === 1 ? '' : 's'));
        $this->activity(
            ($project ? 'updated' : 'created').' project "'.$data['name'].'" with '.count($data['tasks']).' assigned task'.(count($data['tasks']) === 1 ? '' : 's'),
            $actor
        );
        return (array) DB::table('projects')
            ->where('id', $id)
            ->first();
    }

    public function deleteProject(string $id, object $actor): void
    {
        $project = $this->row('projects', $id);
        foreach (DB::table('tasks')->where('project_id', $id)->pluck('id') as $taskId) $this->tasks->delete($taskId, $actor);
        DB::table('projects')->where('id', $id)->delete();
        $this->activity('deleted project "'.$project->name.'" ('.$id.')', $actor);
    }

    private function linkProject(string $id, ?string $clientId, object $actor): void
    {
        $this->row('projects', $id);
        DB::table('projects')->where('id', $id)->update(['client_id' => $clientId, 'updated_at' => now()]);
        $this->changeTasks(DB::table('tasks')->where('project_id', $id), ['client_id' => $clientId], $actor);
    }

    public function client(Request $request, object $actor, ?string $id): array
    {
        $client = $id ? $this->row('clients', $id) : null;
        $login = $id ? DB::table('users')->where('role', 'client')->where(fn ($q) => $q->where('client_id', $id)->orWhere('id', $id))->first() : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'company' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($login?->id, 'id')],
            'phone' => ['required', 'string', 'max:40'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'project_id' => ['nullable', 'string', 'exists:projects,id'],
            'password' => [$client ? 'prohibited' : 'required', 'string', 'min:8'],
        ]);
        $id ??= 'c_'.Str::lower(Str::random(12));
        $profile = array_intersect_key($data, array_flip(['name', 'company', 'email', 'phone', 'color']));
        $profile['email'] = strtolower($profile['email']);
        if ($client) DB::table('clients')->where('id', $id)->update($profile + ['updated_at' => now()]);
        else DB::table('clients')->insert($profile + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        if ($login) DB::table('users')->where('id', $login->id)->update($profile + ['client_id' => $id, 'updated_at' => now()]);
        elseif (! $client) {
            DB::table('users')->insert($profile + ['id' => $id, 'role' => 'client', 'role_id' => 0,
                'client_id' => $id, 'password' => Hash::make($data['password']), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(function () use ($data, $actor) {
                if (! app(StateController::class)->sendWelcomeEmail($data + ['role' => 'client'], $actor)) $this->mailFailures[] = $data['email'];
            });
        }
        $oldProject = $client ? DB::table('projects')->where('client_id', $id)->first() : null;
        $selected = $data['project_id'] ?? null;
        if ($oldProject && $oldProject->id !== $selected) $this->linkProject($oldProject->id, null, $actor);
        if ($selected) $this->linkProject($selected, $id, $actor);
        // $this->activity('Client '.$profile['company'].' '.($client ? 'updated' : 'added'));
        $this->activity(
            ($client ? 'updated' : 'added').' client '.$profile['company'],
            $actor
        );
        return ['id' => $id] + $profile;
    }

    public function deleteClient(string $id, object $actor): void
    {
        $client = $this->row('clients', $id);
        foreach (DB::table('tasks')->where('client_id', $id)->pluck('id') as $taskId) $this->tasks->delete($taskId, $actor);
        foreach (DB::table('projects')->where('client_id', $id)->pluck('id') as $projectId) {
            // Preserve any historically inconsistent cross-client task, detaching its project explicitly.
            $this->changeTasks(DB::table('tasks')->where('project_id', $projectId), ['project_id' => null], $actor);
            DB::table('projects')->where('id', $projectId)->delete();
        }
        DB::table('messages')->where('client_id', $id)->delete();
        foreach (DB::table('users')->where('role', 'client')->where(fn ($q) => $q->where('client_id', $id)->orWhere('id', $id))->pluck('id') as $userId) {
            $this->removeOwner($userId, $actor);
            DB::table('users')->where('id', $userId)->delete();
        }
        DB::table('clients')->where('id', $id)->delete();
        $this->activity('deleted client "'.$client->company.'" ('.$id.')', $actor);
    }

    private function removeOwner(string $id, object $actor): void
    {
        foreach (DB::table('tasks')->get() as $task) {
            $owners = app(TaskEffects::class)->owners((array) $task);
            if (in_array($id, $owners, true) || $task->owner_id === $id) {
                $this->tasks->update($task->id, new Request(['owner_ids' => array_values(array_diff($owners, [$id]))]), $actor);
            }
        }
    }

    public function deleteMember(string $id, object $actor): void
    {
        $member = $this->row('users', $id);
        abort_unless($member->role === 'team', 403, 'Only team members can be removed here.');
        $this->removeOwner($id, $actor);
        DB::table('users')->where('id', $id)->delete();
        $this->activity('deleted team member "'.$member->name.'" ('.$id.')', $actor);
    }

    public function department(Request $request, object $actor, ?string $id): array
    {
        $department = $id ? $this->row('departments', $id) : null;
        $data = $request->validate(['name' => ['required', 'string', 'max:40', Rule::unique('departments', 'name')->ignore($id, 'id')],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        if ($department) {
            $this->changeTasks(DB::table('tasks')->where('department', $department->name), ['department' => $data['name']], $actor);
            DB::table('users')->where('department', $department->name)->update(['department' => $data['name'], 'updated_at' => now()]);
            DB::table('departments')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            $id = 'dept_'.Str::lower(Str::random(12));
            DB::table('departments')->insert($data + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->activity(($department ? 'updated' : 'created').' department "'.$data['name'].'" ('.$id.')', $actor);
        return ['id' => $id] + $data;
    }

    public function deleteDepartment(string $id, object $actor): void
    {
        $department = $this->row('departments', $id);
        abort_if(DB::table('users')->where('role', 'team')->where('department', $department->name)->exists(), 422, 'Cannot delete a department with team members.');
        $fallback = DB::table('departments')->where('id', '!=', $id)->orderBy('name')->value('name') ?? 'General';
        $this->changeTasks(DB::table('tasks')->where('department', $department->name), ['department' => $fallback], $actor);
        DB::table('departments')->where('id', $id)->delete();
        $this->activity('deleted department "'.$department->name.'" ('.$id.'); tasks moved to "'.$fallback.'"', $actor);
    }

    public function brief(Request $request, object $actor): void
    {
        abort_unless($actor->role === 'client', 403);
        $data = $request->validate(['task' => ['required', 'array'], 'voice' => ['required', 'string', 'max:20000000',
            'regex:~\Adata:audio/[a-zA-Z0-9.+-]+(?:;codecs=[a-zA-Z0-9.+-]+)?;base64,[A-Za-z0-9+/=\r\n]+\z~']]);
        $task = $this->tasks->create(new Request($data['task']), $actor);
        DB::table('messages')->insert(['id' => (string) Str::uuid(), 'task_id' => $task['id'], 'client_id' => $task['client_id'],
            'from_id' => $actor->id, 'from_role' => $actor->role, 'text' => '', 'voice' => $data['voice'], 'attachments' => '[]',
            'sent_at_ms' => now()->getTimestampMs(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
