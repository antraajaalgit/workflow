<?php

namespace App\Http\Controllers;

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

'users.*.email' => [
    'nullable',
    'email',
    'distinct:ignore_case',
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
            $submitted = collect($data['users'])->map(fn($u) => [$u['id'], $u['role'], strtolower($u['email'] ?? '')])->sort()->values()->all();
            $stored = DB::table('users')->get()->map(fn($u) => [$u->id, $u->role, strtolower($u->email ?? '')])->sort()->values()->all();
            abort_unless($submitted === $stored, 403, 'Only admins can manage accounts.');
        }
        $mailFailures = DB::transaction(fn () => $this->replaceState($data));
        return response()->json(['saved' => true, 'mailFailures' => $mailFailures]);
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
            'users' => DB::table('users')->get()->map(fn($r)=>$this->publicUser($r))->all(),
            'clients' => DB::table('clients')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color])->all(),
            'projects' => Schema::hasTable('projects') ? DB::table('projects')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'name'=>$r->name,'desc'=>$r->description,'status'=>$r->status,'dueDate'=>$r->due_date_ms ? (int)$r->due_date_ms : null])->all() : [],
            'tasks' => DB::table('tasks')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'projectId'=>$r->project_id ?? null,'title'=>$r->title,'desc'=>$r->description,'dept'=>$r->department,'ownerId'=>$r->owner_id,'status'=>$r->status,'priority'=>$r->priority,'createdAt'=>(int)$r->created_at_ms,'stageAt'=>(int)$r->stage_at_ms,'dueDate'=>$r->due_date_ms ? (int)$r->due_date_ms : null,'recurring'=>$r->recurring,'attachments'=>json_decode($r->attachments ?? '[]', true) ?: []])->all(),
            'messages' => DB::table('messages')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'taskId'=>$r->task_id,'fromId'=>$r->from_id,'fromRole'=>$r->from_role,'text'=>$r->text ?? '', 'voice'=>$r->voice,'attachments'=>json_decode($r->attachments ?? '[]', true) ?: [],'at'=>(int)$r->sent_at_ms])->all(),
            'activity' => DB::table('activities')->orderByDesc('occurred_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'at'=>(int)$r->occurred_at_ms,'text'=>$r->text,'type'=>$r->type])->all(),
            'notifications' => DB::table('notifications')->orderByDesc('sent_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'channel'=>$r->channel,'to'=>$r->recipient,'text'=>$r->text,'at'=>(int)$r->sent_at_ms])->all(),
            'rules' => DB::table('delegation_rules')->orderBy('sort_order')->get()->map(fn($r)=>['kw'=>$r->keywords,'dept'=>$r->department])->all(),
            'settings' => ['amberMin'=>(int)(DB::table('settings')->where('key','amberMin')->value('value') ?? 15),'redMin'=>(int)(DB::table('settings')->where('key','redMin')->value('value') ?? 25)],
        ];
    }

    private function replaceState(array $s): array
    {
        $passwords = DB::table('users')->pluck('password', 'id');
        $existingIds = $passwords->keys()->all();
        $newUsers = array_values(array_filter($s['users'], fn($u) => !in_array($u['id'], $existingIds, true)));
        foreach ($newUsers as $user) {
            validator($user, ['email'=>['required','email'],'password'=>['required','string','min:8']])->validate();
        }
        $this->clear(); $now = now();
        DB::table('clients')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'company'=>$x['company'],'email'=>$x['email']??null,'phone'=>$x['phone']??null,'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['clients']));
        DB::table('users')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'role'=>$x['role'],'role_id'=>$x['role']==='admin'?1:($x['role']==='team'?2:0),'department'=>$x['dept']??null,'client_id'=>$x['clientId']??null,'company'=>$x['company']??null,'email'=>filled($x['email']??null)?strtolower($x['email']):null,'password'=>isset($x['password'])?Hash::make($x['password']):$passwords[$x['id']],'phone'=>$x['phone']??null,'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['users']));
        DB::table('projects')->insert(array_map(fn($x)=>['id'=>$x['id'],'client_id'=>$x['clientId']??null,'name'=>$x['name'],'description'=>$x['desc']??null,'status'=>$x['status']??'active','due_date_ms'=>$x['dueDate']??null,'created_at'=>$now,'updated_at'=>$now],$s['projects']));
        $hasAttachments = Schema::hasColumn('tasks', 'attachments');
        DB::table('tasks')->insert(array_map(function ($x) use ($now, $hasAttachments) {
            $task = ['id'=>$x['id'],'client_id'=>$x['clientId']??null,'project_id'=>$x['projectId']??null,'title'=>$x['title'],'description'=>$x['desc']??null,'department'=>$x['dept'],'owner_id'=>$x['ownerId']??null,'status'=>$x['status'],'priority'=>$x['priority'],'created_at_ms'=>$x['createdAt'],'stage_at_ms'=>$x['stageAt'],'due_date_ms'=>$x['dueDate']??null,'recurring'=>$x['recurring']??null,'created_at'=>$now,'updated_at'=>$now];
            if ($hasAttachments) $task['attachments'] = json_encode($x['attachments'] ?? []);
            return $task;
        }, $s['tasks']));
        DB::table('messages')->insert(array_map(fn($x)=>['id'=>$x['id'],'client_id'=>$x['clientId'],'task_id'=>$x['taskId']??null,'from_id'=>$x['fromId'],'from_role'=>$x['fromRole'],'text'=>$x['text']??null,'voice'=>$x['voice']??null,'attachments'=>json_encode($x['attachments']??[]),'sent_at_ms'=>$x['at'],'created_at'=>$now,'updated_at'=>$now],$s['messages']));
        DB::table('activities')->insert(array_map(fn($x)=>['id'=>$x['id'],'occurred_at_ms'=>$x['at'],'text'=>$x['text'],'type'=>$x['type'],'created_at'=>$now,'updated_at'=>$now],$s['activity']));
        DB::table('notifications')->insert(array_map(fn($x)=>['id'=>$x['id'],'channel'=>$x['channel'],'recipient'=>$x['to'],'text'=>$x['text'],'sent_at_ms'=>$x['at'],'created_at'=>$now,'updated_at'=>$now],$s['notifications']));
        DB::table('delegation_rules')->insert(array_map(fn($x,$i)=>['sort_order'=>$i,'keywords'=>$x['kw'],'department'=>$x['dept'],'created_at'=>$now,'updated_at'=>$now],$s['rules'],array_keys($s['rules'])));
        DB::table('settings')->insert([['key'=>'amberMin','value'=>(string)$s['settings']['amberMin'],'created_at'=>$now,'updated_at'=>$now],['key'=>'redMin','value'=>(string)$s['settings']['redMin'],'created_at'=>$now,'updated_at'=>$now]]);
        $mailFailures = [];
        foreach ($newUsers as $user) {
            if (!$this->sendWelcomeEmail($user)) $mailFailures[] = $user['email'];
        }
        return $mailFailures;
    }

    private function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['settings','delegation_rules','notifications','activities','messages','tasks','projects','users','clients'] as $table) if (Schema::hasTable($table)) DB::table($table)->delete();
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
        return ['id'=>$r->id,'name'=>$r->name,'role'=>$r->role,'roleId'=>(int)$r->role_id,'dept'=>$r->department,'clientId'=>$r->client_id,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color];
    }

    private function sendWelcomeEmail(array $user): bool
    {
        $role = $user['role'] === 'team' ? 'team member' : 'client';
        try {
            Mail::raw("Hello {$user['name']},\n\nYour Nagare {$role} account is ready.\n\nEmail: {$user['email']}\nPassword: {$user['password']}\n\nSign in at: ".url('/'), function ($message) use ($user) {
                $message->to($user['email'], $user['name'])->subject('Your Nagare login details');
            });
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Nagare welcome email could not be sent.', [
                'recipient' => $user['email'],
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }
}
