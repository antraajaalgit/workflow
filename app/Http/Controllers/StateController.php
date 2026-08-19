<?php

namespace App\Http\Controllers;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StateController extends Controller
{
    public function show(): JsonResponse { return response()->json($this->state()); }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clients' => ['required','array'], 'users' => ['required','array'], 'tasks' => ['required','array'],
            'messages' => ['required','array'], 'activity' => ['required','array'], 'notifications' => ['required','array'],
            'rules' => ['required','array'], 'settings' => ['required','array'],
        ]);
        DB::transaction(fn () => $this->replaceState($data));
        return response()->json(['saved' => true]);
    }

    public function reset(): JsonResponse
    {
        DB::transaction(function () {
            $this->clear();
            (new DatabaseSeeder)->run();
        });
        return response()->json($this->state());
    }

    public function session(Request $request): JsonResponse { return response()->json(['userId' => $request->session()->get('nagare_user_id'), 'csrfToken' => csrf_token()]); }

    public function signIn(Request $request): JsonResponse
    {
        $input = $request->validate(['userId' => ['required','string', Rule::exists('users', 'id')]]);
        $request->session()->regenerate(); $request->session()->put('nagare_user_id', $input['userId']);
        return response()->json(['userId' => $input['userId'], 'csrfToken' => csrf_token()]);
    }

    public function signOut(Request $request): JsonResponse
    {
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['signedOut' => true, 'csrfToken' => csrf_token()]);
    }

    private function state(): array
    {
        return [
            'users' => DB::table('users')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'role'=>$r->role,'dept'=>$r->department,'clientId'=>$r->client_id,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color])->all(),
            'clients' => DB::table('clients')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'company'=>$r->company,'email'=>$r->email,'phone'=>$r->phone,'color'=>$r->color])->all(),
            'tasks' => DB::table('tasks')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'title'=>$r->title,'desc'=>$r->description,'dept'=>$r->department,'ownerId'=>$r->owner_id,'status'=>$r->status,'priority'=>$r->priority,'createdAt'=>(int)$r->created_at_ms,'stageAt'=>(int)$r->stage_at_ms,'dueDate'=>$r->due_date_ms ? (int)$r->due_date_ms : null,'recurring'=>$r->recurring,'attachments'=>json_decode($r->attachments ?? '[]', true) ?: []])->all(),
            'messages' => DB::table('messages')->get()->map(fn($r)=>['id'=>$r->id,'clientId'=>$r->client_id,'taskId'=>$r->task_id,'fromId'=>$r->from_id,'fromRole'=>$r->from_role,'text'=>$r->text ?? '', 'voice'=>$r->voice,'at'=>(int)$r->sent_at_ms])->all(),
            'activity' => DB::table('activities')->orderByDesc('occurred_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'at'=>(int)$r->occurred_at_ms,'text'=>$r->text,'type'=>$r->type])->all(),
            'notifications' => DB::table('notifications')->orderByDesc('sent_at_ms')->get()->map(fn($r)=>['id'=>$r->id,'channel'=>$r->channel,'to'=>$r->recipient,'text'=>$r->text,'at'=>(int)$r->sent_at_ms])->all(),
            'rules' => DB::table('delegation_rules')->orderBy('sort_order')->get()->map(fn($r)=>['kw'=>$r->keywords,'dept'=>$r->department])->all(),
            'settings' => ['amberMin'=>(int)(DB::table('settings')->where('key','amberMin')->value('value') ?? 15),'redMin'=>(int)(DB::table('settings')->where('key','redMin')->value('value') ?? 25)],
        ];
    }

    private function replaceState(array $s): void
    {
        $this->clear(); $now = now();
        DB::table('clients')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'company'=>$x['company'],'email'=>$x['email']??null,'phone'=>$x['phone']??null,'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['clients']));
        DB::table('users')->insert(array_map(fn($x)=>['id'=>$x['id'],'name'=>$x['name'],'role'=>$x['role'],'department'=>$x['dept']??null,'client_id'=>$x['clientId']??null,'company'=>$x['company']??null,'email'=>$x['email']??null,'phone'=>$x['phone']??null,'color'=>$x['color']??null,'created_at'=>$now,'updated_at'=>$now],$s['users']));
        $hasAttachments = Schema::hasColumn('tasks', 'attachments');
        DB::table('tasks')->insert(array_map(function ($x) use ($now, $hasAttachments) {
            $task = ['id'=>$x['id'],'client_id'=>$x['clientId'],'title'=>$x['title'],'description'=>$x['desc']??null,'department'=>$x['dept'],'owner_id'=>$x['ownerId']??null,'status'=>$x['status'],'priority'=>$x['priority'],'created_at_ms'=>$x['createdAt'],'stage_at_ms'=>$x['stageAt'],'due_date_ms'=>$x['dueDate']??null,'recurring'=>$x['recurring']??null,'created_at'=>$now,'updated_at'=>$now];
            if ($hasAttachments) $task['attachments'] = json_encode($x['attachments'] ?? []);
            return $task;
        }, $s['tasks']));
        DB::table('messages')->insert(array_map(fn($x)=>['id'=>$x['id'],'client_id'=>$x['clientId'],'task_id'=>$x['taskId']??null,'from_id'=>$x['fromId'],'from_role'=>$x['fromRole'],'text'=>$x['text']??null,'voice'=>$x['voice']??null,'sent_at_ms'=>$x['at'],'created_at'=>$now,'updated_at'=>$now],$s['messages']));
        DB::table('activities')->insert(array_map(fn($x)=>['id'=>$x['id'],'occurred_at_ms'=>$x['at'],'text'=>$x['text'],'type'=>$x['type'],'created_at'=>$now,'updated_at'=>$now],$s['activity']));
        DB::table('notifications')->insert(array_map(fn($x)=>['id'=>$x['id'],'channel'=>$x['channel'],'recipient'=>$x['to'],'text'=>$x['text'],'sent_at_ms'=>$x['at'],'created_at'=>$now,'updated_at'=>$now],$s['notifications']));
        DB::table('delegation_rules')->insert(array_map(fn($x,$i)=>['sort_order'=>$i,'keywords'=>$x['kw'],'department'=>$x['dept'],'created_at'=>$now,'updated_at'=>$now],$s['rules'],array_keys($s['rules'])));
        DB::table('settings')->insert([['key'=>'amberMin','value'=>(string)$s['settings']['amberMin'],'created_at'=>$now,'updated_at'=>$now],['key'=>'redMin','value'=>(string)$s['settings']['redMin'],'created_at'=>$now,'updated_at'=>$now]]);
    }

    private function clear(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['settings','delegation_rules','notifications','activities','messages','tasks','users','clients'] as $table) DB::table($table)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
