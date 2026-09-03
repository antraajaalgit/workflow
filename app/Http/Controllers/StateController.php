<?php

namespace App\Http\Controllers;

use App\Services\RecurringTaskGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StateController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->requireUser($request);
        return response()->json($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        // $data = $request->validate([
        //     'clients' => ['present','array'], 'users' => ['present','array'], 'projects' => ['present','array'], 'tasks' => ['present','array'],
        //     'messages' => ['present','array'], 'activity' => ['present','array'], 'notifications' => ['present','array'],
        //     'rules' => ['present','array'], 'settings' => ['required','array'],
        // ]);
        $data = $request->validate([
    'clients' => ['present','array'],
    'departments' => ['present','array'],
    'departments.*.id' => ['required','string','max:40','distinct'],
    'departments.*.name' => ['required','string','max:80','distinct:ignore_case'],
    'departments.*.color' => ['sometimes','nullable','regex:/^#[0-9a-fA-F]{6}$/'],

   'users' => ['present', 'array'],

'users.*.id' => [
    'required',
    'string',
],

'users.*.name' => [
    'required',
    'string',
],

'users.*.role' => [
    'required',
    'in:admin,team,client',
],

'users.*.roleId' => [
    'sometimes',
    'nullable',
    'integer',
],

'users.*.dept' => [
    'sometimes',
    'nullable',
    'string',
    'max:40',
],

'users.*.clientId' => [
    'sometimes',
    'nullable',
    'string',
    'max:40',
],

'users.*.company' => [
    'sometimes',
    'nullable',
    'string',
    'max:255',
],

'users.*.email' => [
    'nullable',
    'email',
    'distinct:ignore_case',
],

'users.*.phone' => [
    'sometimes',
    'nullable',
    'string',
    'max:40',
],

'users.*.color' => [
    'sometimes',
    'nullable',
    'string',
    'max:20',
],

'users.*.image' => [
    'sometimes',
    'nullable',
    'string',
    'max:255',
],

'users.*.password' => [
    'sometimes',
    'nullable',
    'string',
    'min:8',
],

    'projects' => ['present','array'],
    'tasks' => ['present','array'],
    'messages' => ['present','array'],
    'activity' => ['present','array'],
    'notifications' => ['present','array'],
    'rules' => ['present','array'],
    'settings' => ['required','array'],
], [
    'users.*.email.distinct' =>
        'This email is already being used by another account.',
]);
        if ($actor->role_id !== 1) {
            abort_if(collect($data['users'])->contains(fn ($user) => filled($user['password'] ?? null)), 403, 'Only admins can change passwords.');
            $submitted = collect($data['users'])->map(fn($u) => [$u['id'], $u['role'], strtolower($u['email'] ?? ''), $u['image'] ?? null])->sort()->values()->all();
            $stored = DB::table('users')->get()->map(fn($u) => [$u->id, $u->role, strtolower($u->email ?? ''), $u->image])->sort()->values()->all();
            abort_unless($submitted === $stored, 403, 'Only admins can manage accounts.');
            $submittedDepartments = collect($data['departments'])->map(fn($d) => [$d['id'], $d['name'], $d['color'] ?? null])->sort()->values()->all();
            $storedDepartments = DB::table('departments')->get()->map(fn($d) => [$d->id, $d->name, $d->color])->sort()->values()->all();
            abort_unless($submittedDepartments === $storedDepartments, 403, 'Only admins can manage departments.');
        }
        $assignableUserIds = collect($data['users'])
            ->filter(fn($user) => $user['role'] === 'team' || ($user['role'] === 'admin' && filled($user['email'] ?? null)))
            ->pluck('id');
        $departmentNames = collect($data['departments'])->pluck('name');
        foreach ($data['users'] as $user) {
            abort_unless($user['role'] !== 'team' || $departmentNames->contains($user['dept'] ?? null), 422, 'Every team member must belong to an existing department.');
        }
        foreach ($data['tasks'] as $task) {
            $ownerIds = $task['ownerIds'] ?? (filled($task['ownerId'] ?? null) ? [$task['ownerId']] : []);
            abort_unless(is_array($ownerIds), 422, 'Task assignees must be provided as a list.');
            abort_unless(collect($ownerIds)->every(fn ($ownerId) => $assignableUserIds->contains($ownerId)), 422, 'Tasks can only be assigned to admins or team members.');
        }
        $taskAssignments = $actor->role_id === 1 ? $this->newTeamTaskAssignments($data) : [];
        $teamPasswordChanges = $actor->role_id === 1 ? $this->teamPasswordChanges($data) : [];
        $mailFailures = DB::transaction(fn () => $this->replaceState($data, $actor));
        foreach ($teamPasswordChanges as $member) {
            if (!$this->sendWelcomeEmail($member, $actor, true)) $mailFailures[] = $member['email'];
        }
        $assignmentMailFailures = [];
        foreach ($taskAssignments as $assignment) {
            if (!$this->sendTaskAssignmentEmail($assignment)) $assignmentMailFailures[] = $assignment['member']['email'];
        }
        return response()->json(['saved' => true, 'mailFailures' => $mailFailures, 'assignmentMailFailures' => $assignmentMailFailures]);
    }

    public function reset(): JsonResponse
    {
        $this->requireAdmin(request());
        DB::transaction(function () {
            $this->clear();
            (new DatabaseSeeder)->run();
        });
        return response()->json($this->state());
    }

    public function session(Request $request): JsonResponse
    {
        $id = $request->session()->get('nagare_user_id');
        $user = $id ? DB::table('users')->where('id', $id)->first() : null;
        return response()->json(['userId' => $user?->id, 'user' => $user ? $this->publicUser($user) : null, 'csrfToken' => csrf_token()]);
    }

    public function signIn(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($input['email'])])->first();
        if (!$user || !Hash::check($input['password'], $user->password)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }
        $request->session()->regenerate();
        $request->session()->put('nagare_user_id', $user->id);
        return response()->json(['userId' => $user->id, 'user' => $this->publicUser($user), 'csrfToken' => csrf_token()]);
    }

    public function signOut(Request $request): JsonResponse
    {
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['signedOut' => true, 'csrfToken' => csrf_token()]);
    }

    private function state(): array
    {
        return [
            'departments' => Schema::hasTable('departments') ? DB::table('departments')->orderBy('name')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'color'=>$r->color])->all() : [],
            'users' => DB::table('users')->get()->map(fn($r)=>$this->publicUser($r))->all(),
            'clients' => DB::table('clients')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color])->all(),
            'projects' => Schema::hasTable('projects') ? DB::table('projects')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'name'=>$r->name,'desc'=>$r->description,'status'=>$r->status,'dueDate'=>$r->due_date_ms ? (int)$r->due_date_ms : null])->all() : [],
            'tasks' => DB::table('tasks')->get()->map(function($r){$ownerIds=json_decode($r->owner_ids??'[]',true)?:array_values(array_filter([$r->owner_id]));return ['id'=>$r->id,'clientId'=>$r->client_id,'projectId'=>$r->project_id ?? null,'title'=>$r->title,'desc'=>$r->description,'dept'=>$r->department,'ownerId'=>$ownerIds[0]??null,'ownerIds'=>$ownerIds,'status'=>$r->status,'priority'=>$r->priority,'progress'=>$r->progress ?? 'just_started','createdAt'=>(int)$r->created_at_ms,'stageAt'=>(int)$r->stage_at_ms,'dueDate'=>$r->due_date_ms ? (int)$r->due_date_ms : null,'recurring'=>$r->recurring,'nextRecurrenceAt'=>$r->next_recurrence_at_ms ? (int)$r->next_recurrence_at_ms : null,'attachments'=>json_decode($r->attachments ?? '[]', true) ?: []];})->all(),
            'messages' => DB::table('messages')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'taskId'=>$r->task_id,'fromId'=>$r->from_id,'fromRole'=>$r->from_role,'text'=>$r->text ?? '', 'voice'=>$r->voice,'attachments'=>json_decode($r->attachments ?? '[]', true) ?: [],'at'=>(int)$r->sent_at_ms])->all(),
            'activity' => DB::table('activities')->orderByDesc('occurred_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'at'=>(int)$r->occurred_at_ms,'text'=>$r->text,'type'=>$r->type])->all(),
            'notifications' => DB::table('notifications')->orderByDesc('sent_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'channel'=>$r->channel,'to'=>$r->recipient,'text'=>$r->text,'at'=>(int)$r->sent_at_ms])->all(),
            'rules' => DB::table('delegation_rules')->orderBy('sort_order')->get()->map(fn($r)=>['kw'=>$r->keywords,'dept'=>$r->department])->all(),
            'settings' => ['amberMin'=>(int)(DB::table('settings')->where('key','amberMin')->value('value') ?? 15),'redMin'=>(int)(DB::table('settings')->where('key','redMin')->value('value') ?? 25)],
        ];
    }

    private function replaceState(array $s, object $actor): array
    {
        $passwords = DB::table('users')->pluck('password', 'id');
        $existingIds = $passwords->keys()->all();
        $newUsers = array_values(array_filter($s['users'], fn($u) => !in_array($u['id'], $existingIds, true)));
        foreach ($newUsers as $user) {
            validator($user, ['email'=>['required','email'],'password'=>['required','string','min:8']])->validate();
        }
        $this->clear(); $now = now();
        DB::table('departments')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['departments']));
        DB::table('clients')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'company'=>$x['company'],'email'=>$x['email']??null,'phone'=>$x['phone']??null,'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['clients']));
        DB::table('users')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'role'=>$x['role'],'role_id'=>$x['role']==='admin'?1:($x['role']==='team'?2:0),'department'=>$x['dept']??null,'client_id'=>$x['clientId']??null,'company'=>$x['company']??null,'email'=>filled($x['email']??null)?strtolower($x['email']):null,'password'=>isset($x['password'])?Hash::make($x['password']):$passwords[$x['id']],'phone'=>$x['phone']??null,'color'=>$x['color']??null,'image'=>$x['image']??null,'created_at'=>$now,'updated_at'=>$now],$s['users']));
        DB::table('projects')->insert(array_map(fn($x)=>['id'=>$x['id'],'client_id'=>$x['clientId']??null,'name'=>$x['name'],'description'=>$x['desc']??null,'status'=>$x['status']??'active','due_date_ms'=>$x['dueDate']??null,'created_at'=>$now,'updated_at'=>$now],$s['projects']));
        $hasAttachments = Schema::hasColumn('tasks', 'attachments');
        DB::table('tasks')->insert(array_map(function ($x) use ($now, $hasAttachments) {
            $ownerIds=array_values(array_unique($x['ownerIds']??array_values(array_filter([$x['ownerId']??null]))));
            $task = ['id'=>$x['id'],'client_id'=>$x['clientId']??null,'project_id'=>$x['projectId']??null,'title'=>$x['title'],'description'=>$x['desc']??null,'department'=>filled($x['dept']??null)?$x['dept']:'General','owner_id'=>$ownerIds[0]??null,'owner_ids'=>json_encode($ownerIds),'status'=>$x['status'],'priority'=>$x['priority'],'progress'=>$x['progress']??'just_started','created_at_ms'=>$x['createdAt'],'stage_at_ms'=>$x['stageAt'],'due_date_ms'=>$x['dueDate']??null,'recurring'=>$x['recurring']??null,'next_recurrence_at_ms'=>$x['nextRecurrenceAt']??null,'created_at'=>$now,'updated_at'=>$now];
            if ($hasAttachments) $task['attachments'] = json_encode($x['attachments'] ?? []);
            return $task;
        }, $s['tasks']));
        $clientIds = collect($s['clients'])->pluck('id')->flip();
        $taskIds = collect($s['tasks'])->pluck('id')->flip();
        DB::table('messages')->insert(array_map(fn($x)=>[
            'id'=>$x['id'],
            'client_id'=>$clientIds->has($x['clientId']??null)?$x['clientId']:null,
            'task_id'=>$taskIds->has($x['taskId']??null)?$x['taskId']:null,
            'from_id'=>$x['fromId'],
            'from_role'=>$x['fromRole'],
            'text'=>$x['text']??null,
            'voice'=>$x['voice']??null,
            'attachments'=>json_encode($x['attachments']??[]),
            'sent_at_ms'=>$x['at'],
            'created_at'=>$now,
            'updated_at'=>$now,
        ],$s['messages']));
        DB::table('activities')->insert(array_map(fn($x)=>['id'=>$x['id'],'occurred_at_ms'=>$x['at'],'text'=>$x['text'],'type'=>$x['type'],'created_at'=>$now,'updated_at'=>$now],$s['activity']));
        DB::table('notifications')->insert(array_map(fn($x)=>['id'=>$x['id'],'channel'=>$x['channel'],'recipient'=>$x['to'],'text'=>$x['text'],'sent_at_ms'=>$x['at'],'created_at'=>$now,'updated_at'=>$now],$s['notifications']));
        DB::table('delegation_rules')->insert(array_map(fn($x,$i)=>['sort_order'=>$i,'keywords'=>$x['kw'],'department'=>$x['dept'],'created_at'=>$now,'updated_at'=>$now],$s['rules'],array_keys($s['rules'])));
        DB::table('settings')->insert([['key'=>'amberMin','value'=>(string)$s['settings']['amberMin'],'created_at'=>$now,'updated_at'=>$now],['key'=>'redMin','value'=>(string)$s['settings']['redMin'],'created_at'=>$now,'updated_at'=>$now]]);
        $mailFailures = [];
        foreach ($newUsers as $user) {
            if (!$this->sendWelcomeEmail($user, $actor)) $mailFailures[] = $user['email'];
        }
        return $mailFailures;
    }

    private function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['settings','delegation_rules','notifications','activities','messages','tasks','projects','users','clients','departments'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function uploadChatAttachments(Request $request): JsonResponse
    {
        $this->requireUser($request);
        $request->validate(['files' => ['required','array','max:10'], 'files.*' => ['file','mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv,txt,zip','max:10240']]);
        abort_if(collect($request->file('files'))->sum(fn($file) => $file->getSize()) > 10 * 1024 * 1024, 422, 'Attachments can be up to 10 MB in total.');
        $attachments = collect($request->file('files'))->map(function ($file) {
            $extension = preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()) ?: 'bin';
            $path = $file->storeAs('chat-attachments', Str::uuid().'.'.$extension, 'public');
            return ['id'=>(string)Str::uuid(),'name'=>$file->getClientOriginalName(),'type'=>$file->getMimeType() ?: 'application/octet-stream','size'=>$file->getSize(),'data' => '/api/chat-attachment?file=' . rawurlencode(basename($path))];
        })->values();
        return response()->json(['attachments' => $attachments]);
    }

    public function uploadTeamMemberImage(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048']]);
        $file = $request->file('image');
        $extension = preg_replace('/[^a-z0-9]/i', '', $file->getClientOriginalExtension()) ?: 'jpg';
        $path = $file->storeAs('team-member-images', Str::uuid().'.'.$extension, 'public');

        return response()->json(['url' => '/api/team-member-image?file='.rawurlencode(basename($path))]);
    }

    public function showTeamMemberImage(Request $request): BinaryFileResponse
    {
        $this->requireUser($request);
        $file = (string) $request->query('file', '');
        abort_unless($file !== '' && $file === basename($file) && preg_match('/^[a-f0-9-]+\.[a-z0-9]+$/i', $file), 404);
        $path = 'team-member-images/'.$file;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function generateRecurringTasks(Request $request, RecurringTaskGenerator $generator): JsonResponse
    {
        $this->requireUser($request);
        return response()->json(['created' => $generator->generateDueTasks()]);
    }

    public function sendChatEmail(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        abort_unless(in_array($actor->role, ['admin', 'team'], true), 403, 'Only staff can email clients.');

        $data = $request->validate([
            'clientId' => ['required', 'string', 'max:40'],
            'taskId' => ['nullable', 'string', 'max:40'],
            'text' => ['nullable', 'string', 'max:10000'],
            'hasVoice' => ['sometimes', 'boolean'],
            'attachmentNames' => ['sometimes', 'array', 'max:10'],
            'attachmentNames.*' => ['string', 'max:255'],
        ]);

        $client = DB::table('clients')->where('id', $data['clientId'])->first();
        abort_unless($client, 404, 'Client not found.');
        abort_unless(filled($client->email), 422, 'This client does not have an email address.');

        $taskTitle = filled($data['taskId'] ?? null)
            ? DB::table('tasks')->where('id', $data['taskId'])->value('title')
            : null;
        $subject = $taskTitle ? "New message about {$taskTitle}" : 'New message from your Antrajaal team';
        $lines = ["Hello {$client->name},", '', "{$actor->name} from Antrajaal sent you a message:", ''];
        if (filled($data['text'] ?? null)) $lines[] = $data['text'];
        if ($data['hasVoice'] ?? false) $lines[] = '[A voice note is available in Karya.]';
        if (!empty($data['attachmentNames'])) $lines[] = '[Attachments in Karya: '.implode(', ', $data['attachmentNames']).']';
        $lines[] = '';
        $lines[] = 'Open Karya to view the conversation and reply: '.url('/');

        try {
            Mail::mailer('chat_smtp')->raw(implode("\n", $lines), function ($message) use ($client, $subject) {
                $message->from(config('mail.chat_from.address'), config('mail.chat_from.name'))
                    ->to($client->email, $client->name)
                    ->subject($subject);
            });
        } catch (\Throwable $exception) {
            Log::warning('Nagare client chat email could not be sent.', [
                'client_id' => $client->id,
                'recipient' => $client->email,
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['message' => 'The chat message was saved, but its email could not be sent.'], 502);
        }

        return response()->json(['sent' => true]);
    }

    // public function showChatAttachment(Request $request, string $file): BinaryFileResponse
    // {
    //     $this->requireUser($request);
    //     abort_unless($file === basename($file) && preg_match('/^[a-f0-9-]+\.[a-z0-9]+$/i', $file), 404);
    //     $path = 'chat-attachments/'.$file;
    //     abort_unless(Storage::disk('public')->exists($path), 404);
    //     return response()->file(Storage::disk('public')->path($path), [
    //         'Cache-Control' => 'private, max-age=86400',
    //         'X-Content-Type-Options' => 'nosniff',
    //     ]);
    // }

    public function showChatAttachment(
    Request $request
): BinaryFileResponse
{
    $this->requireUser($request);

    $file = (string) $request->query('file', '');

    abort_unless(
        $file !== '' &&
        $file === basename($file) &&
        preg_match('/^[a-f0-9-]+\.[a-z0-9]+$/i', $file),
        404
    );

    $path = 'chat-attachments/'.$file;

    abort_unless(
        Storage::disk('public')->exists($path),
        404
    );

    return response()->file(
        Storage::disk('public')->path($path),
        [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]
    );
}

    private function requireUser(Request $request): object
    {
        $id = $request->session()->get('nagare_user_id');
        abort_unless($id && ($user = DB::table('users')->where('id', $id)->first()), 401, 'Please sign in.');
        return $user;
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless($this->requireUser($request)->role_id === 1, 403, 'Admin access required.');
    }

    private function publicUser(object $r): array
    {
        return ['id'=>$r->id,'name'=>$r->name,'role'=>$r->role,'roleId'=>(int)$r->role_id,'dept'=>$r->department,'clientId'=>$r->client_id,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color,'image'=>$r->image];
    }

    private function sendWelcomeEmail(array $user, object $actor, bool $passwordChanged = false): bool
    {
        $role = $user['role'] === 'team' ? 'team member' : 'client';
        $loginDetails = "Email: {$user['email']}\nPassword: {$user['password']}\n\nSign in at: ".url('/');
        $sent = true;
        $memberIntro = $passwordChanged
            ? "Your Karya {$role} password has been updated."
            : "Your Karya {$role} account is ready.";
        $memberSubject = $passwordChanged ? 'Your updated Karya login details' : 'Your Karya login details';

        try {
            Mail::raw("Hello {$user['name']},\n\n{$memberIntro}\n\n{$loginDetails}", function ($message) use ($user, $memberSubject) {
                $message->to($user['email'], $user['name'])->subject($memberSubject);
            });
        } catch (\Throwable $exception) {
            Log::warning('Nagare welcome email could not be sent.', [
                'recipient' => $user['email'],
                'error' => $exception->getMessage(),
            ]);
            $sent = false;
        }

        if ($user['role'] === 'team' && filled($actor->email ?? null) && strcasecmp($actor->email, $user['email']) !== 0) {
            $adminIntro = $passwordChanged
                ? "The Karya password for team member {$user['name']} has been updated."
                : "A new Karya team member account has been created for {$user['name']}.";
            $adminSubject = $passwordChanged
                ? "Updated Karya login details for {$user['name']}"
                : "Karya login details for {$user['name']}";
            try {
                Mail::raw("Hello {$actor->name},\n\n{$adminIntro}\n\n{$loginDetails}", function ($message) use ($actor, $adminSubject) {
                    $message->to($actor->email, $actor->name)
                        ->subject($adminSubject);
                });
            } catch (\Throwable $exception) {
                Log::warning('Karya admin copy of team member login details could not be sent.', [
                    'member_id' => $user['id'],
                    'recipient' => $actor->email,
                    'error' => $exception->getMessage(),
                ]);
                $sent = false;
            }
        }

        return $sent;
    }

    private function teamPasswordChanges(array $state): array
    {
        $existingUserIds = DB::table('users')->pluck('id')->all();

        return collect($state['users'])
            ->filter(fn ($user) =>
                $user['role'] === 'team'
                && in_array($user['id'], $existingUserIds, true)
                && filled($user['password'] ?? null)
            )
            ->values()
            ->all();
    }

    private function newTeamTaskAssignments(array $state): array
    {
        $existingTaskIds = DB::table('tasks')->pluck('id')->all();
        $teamMembers = collect($state['users'])
            ->filter(fn ($user) => $user['role'] === 'team' && filled($user['email'] ?? null))
            ->keyBy('id');
        $projects = collect($state['projects'])->keyBy('id');

        return collect($state['tasks'])
            ->reject(fn ($task) => in_array($task['id'], $existingTaskIds, true))
            ->flatMap(function ($task) use ($teamMembers, $projects) {
                $project = filled($task['projectId'] ?? null) ? $projects->get($task['projectId']) : null;
                $ownerIds=$task['ownerIds']??array_values(array_filter([$task['ownerId']??null]));
                return collect($ownerIds)->filter(fn($id)=>$teamMembers->has($id))->map(fn($id)=>['task'=>$task,'member'=>$teamMembers->get($id),'project'=>$project]);
            })
            ->values()
            ->all();
    }

    private function sendTaskAssignmentEmail(array $assignment): bool
    {
        $task = $assignment['task'];
        $member = $assignment['member'];
        $projectName = $assignment['project']['name'] ?? 'Standalone task';
        $dueDate = filled($task['dueDate'] ?? null)
            ? date('d M Y', (int) floor($task['dueDate'] / 1000))
            : 'No due date';
        $status = ucfirst(str_replace('_', ' ', $task['status'] ?? 'todo'));
        $lines = [
            "Hello {$member['name']},",
            '',
            'A new task has been assigned to you in Karya.',
            '',
            "Task: {$task['title']}",
            "Project: {$projectName}",
            "Status: {$status}",
            "Due date: {$dueDate}",
        ];
        if (filled($task['desc'] ?? null)) $lines[] = "Description: {$task['desc']}";
        $lines[] = '';
        $lines[] = 'Open Karya to view the task: '.url('/');

        try {
            Mail::mailer('chat_smtp')->raw(implode("\n", $lines), function ($message) use ($member, $task) {
                $message->from(config('mail.chat_from.address'), config('mail.chat_from.name'))
                    ->to($member['email'], $member['name'])
                    ->subject("New task assigned: {$task['title']}");
            });
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Karya task assignment email could not be sent.', [
                'task_id' => $task['id'],
                'recipient' => $member['email'],
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }
}
