<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now(); $ms = (int) floor(microtime(true) * 1000); $ago = fn(int $m) => $ms - $m * 60000; $ahead = fn(int $m) => $ms + $m * 60000;
        $clients = [
            ['id'=>'c_lumen','name'=>'Priya Nair','company'=>'Lumen Cafe','email'=>'priya@lumencafe.com','phone'=>'+91 98xxx xxx01','color'=>'#7a5c3e'],
            ['id'=>'c_volt','name'=>'Karan Shah','company'=>'Volt Fitness','email'=>'karan@voltfit.in','phone'=>'+91 98xxx xxx02','color'=>'#3a6ea5'],
            ['id'=>'c_bloom','name'=>'Ananya Das','company'=>'Bloom Skincare','email'=>'ananya@bloom.co','phone'=>'+91 98xxx xxx03','color'=>'#3fa34d'],
        ];
        DB::table('clients')->insert(array_map(fn($x)=>$x+['created_at'=>$now,'updated_at'=>$now],$clients));
        $users = [
            ['id'=>'u_admin','name'=>'You (Owner)','role'=>'admin','department'=>null,'client_id'=>null,'company'=>null,'color'=>'#7a5c3e'],
            ['id'=>'u_riya','name'=>'Riya Kapoor','role'=>'team','department'=>'Design','client_id'=>null,'company'=>null,'color'=>'#c9a25f'],
            ['id'=>'u_arjun','name'=>'Arjun Mehta','role'=>'team','department'=>'Development','client_id'=>null,'company'=>null,'color'=>'#3a6ea5'],
            ['id'=>'u_sara','name'=>'Sara Khan','role'=>'team','department'=>'Content','client_id'=>null,'company'=>null,'color'=>'#3fa34d'],
            ['id'=>'u_dev','name'=>'Dev Sharma','role'=>'team','department'=>'Marketing','client_id'=>null,'company'=>null,'color'=>'#d94a3d'],
            ['id'=>'u_neha','name'=>'Neha Rao','role'=>'team','department'=>'SEO','client_id'=>null,'company'=>null,'color'=>'#8a6d3b'],
        ];
        foreach ($users as &$u) { $u['email']=null; $u['phone']=null; } unset($u);
        foreach ($clients as $c) $users[]=['id'=>$c['id'],'name'=>$c['name'],'role'=>'client','department'=>null,'client_id'=>$c['id'],'company'=>$c['company'],'email'=>$c['email'],'phone'=>$c['phone'],'color'=>$c['color']];
        DB::table('users')->insert(array_map(fn($x)=>$x+['created_at'=>$now,'updated_at'=>$now],$users));
        $tasks = [
            ['id'=>'t_poster','client_id'=>'c_lumen','title'=>'New menu poster for winter specials','description'=>'Need an A3 poster, warm tones, 6 dishes highlighted.','department'=>'Design','owner_id'=>'u_riya','status'=>'in_progress','priority'=>'high','created_at_ms'=>$ago(180),'stage_at_ms'=>$ago(31),'due_date_ms'=>$ahead(1440),'recurring'=>null],
            ['id'=>'t_carousel','client_id'=>'c_lumen','title'=>'Weekly Instagram carousel','description'=>'3 slides on new coffee blend.','department'=>'Content','owner_id'=>'u_sara','status'=>'review','priority'=>'med','created_at_ms'=>$ago(300),'stage_at_ms'=>$ago(12),'due_date_ms'=>$ahead(600),'recurring'=>'weekly'],
            ['id'=>'t_bug','client_id'=>'c_volt','title'=>'Landing page bug — signup form not submitting','description'=>'Form throws error on mobile Safari.','department'=>'Development','owner_id'=>'u_arjun','status'=>'in_progress','priority'=>'high','created_at_ms'=>$ago(90),'stage_at_ms'=>$ago(47),'due_date_ms'=>$ahead(300),'recurring'=>null],
            ['id'=>'t_ads','client_id'=>'c_volt','title'=>'Google Ads campaign for Jan promo','description'=>'Budget ₹20k, target 25-40 fitness audience.','department'=>'Marketing','owner_id'=>'u_dev','status'=>'todo','priority'=>'med','created_at_ms'=>$ago(60),'stage_at_ms'=>$ago(60),'due_date_ms'=>$ahead(2880),'recurring'=>null],
            ['id'=>'t_seo','client_id'=>'c_bloom','title'=>'SEO audit + keyword plan','description'=>'Full audit of bloom.co, top 20 keywords.','department'=>'SEO','owner_id'=>'u_neha','status'=>'in_progress','priority'=>'low','created_at_ms'=>$ago(240),'stage_at_ms'=>$ago(18),'due_date_ms'=>$ahead(4320),'recurring'=>'monthly'],
            ['id'=>'t_logo','client_id'=>'c_bloom','title'=>'Rebrand logo concepts','description'=>'3 directions, minimal, botanical feel.','department'=>'Design','owner_id'=>'u_riya','status'=>'todo','priority'=>'med','created_at_ms'=>$ago(120),'stage_at_ms'=>$ago(120),'due_date_ms'=>$ahead(5760),'recurring'=>null],
            ['id'=>'t_blog','client_id'=>'c_lumen','title'=>'Blog: "5 winter drinks" article','description'=>'800 words, SEO friendly.','department'=>'Content','owner_id'=>'u_sara','status'=>'done','priority'=>'low','created_at_ms'=>$ago(600),'stage_at_ms'=>$ago(200),'due_date_ms'=>$ago(60),'recurring'=>null],
            ['id'=>'t_report','client_id'=>'c_volt','title'=>'Monthly performance report','description'=>'Auto-generated every 1st.','department'=>'Marketing','owner_id'=>'u_dev','status'=>'new','priority'=>'med','created_at_ms'=>$ago(20),'stage_at_ms'=>$ago(20),'due_date_ms'=>$ahead(1440),'recurring'=>'monthly'],
        ];
        DB::table('tasks')->insert(array_map(fn($x)=>$x+['created_at'=>$now,'updated_at'=>$now],$tasks));
        $messages = [
            ['id'=>(string)Str::uuid(),'client_id'=>'c_lumen','task_id'=>'t_poster','from_id'=>'c_lumen','from_role'=>'client','text'=>'Hi team! Can we make the poster feel cozy and warm? Winter vibes 🙂','voice'=>null,'sent_at_ms'=>$ago(170)],
            ['id'=>(string)Str::uuid(),'client_id'=>'c_lumen','task_id'=>'t_poster','from_id'=>'u_riya','from_role'=>'team','text'=>'Absolutely Priya — starting on warm tones now. Will share a draft by evening.','voice'=>null,'sent_at_ms'=>$ago(160)],
            ['id'=>(string)Str::uuid(),'client_id'=>'c_lumen','task_id'=>'t_poster','from_id'=>'c_lumen','from_role'=>'client','text'=>'Perfect, thank you!','voice'=>null,'sent_at_ms'=>$ago(150)],
            ['id'=>(string)Str::uuid(),'client_id'=>'c_volt','task_id'=>'t_bug','from_id'=>'c_volt','from_role'=>'client','text'=>'The signup bug is urgent, we are losing leads. Please prioritise 🙏','voice'=>null,'sent_at_ms'=>$ago(88)],
            ['id'=>(string)Str::uuid(),'client_id'=>'c_volt','task_id'=>'t_bug','from_id'=>'u_arjun','from_role'=>'team','text'=>'On it Karan — reproduced on iOS Safari, fixing the validation now.','voice'=>null,'sent_at_ms'=>$ago(80)],
        ];
        DB::table('messages')->insert(array_map(fn($x)=>$x+['created_at'=>$now,'updated_at'=>$now],$messages));
        DB::table('activities')->insert([
            ['id'=>(string)Str::uuid(),'occurred_at_ms'=>$ago(20),'text'=>'New brief received from Volt Fitness → auto-delegated to Marketing','type'=>'brief','created_at'=>$now,'updated_at'=>$now],
            ['id'=>(string)Str::uuid(),'occurred_at_ms'=>$ago(47),'text'=>'Arjun moved "Landing page bug" to In Progress','type'=>'move','created_at'=>$now,'updated_at'=>$now],
            ['id'=>(string)Str::uuid(),'occurred_at_ms'=>$ago(88),'text'=>'Karan Shah (Volt Fitness) sent a message','type'=>'msg','created_at'=>$now,'updated_at'=>$now],
        ]);
        DB::table('notifications')->insert([
            ['id'=>(string)Str::uuid(),'channel'=>'whatsapp','recipient'=>'+91 98xxx xxx02','text'=>'✅ Your request "Landing page bug" was received and assigned to our Development team.','sent_at_ms'=>$ago(88),'created_at'=>$now,'updated_at'=>$now],
            ['id'=>(string)Str::uuid(),'channel'=>'email','recipient'=>'karan@voltfit.in','text'=>'Subject: We got your request — Landing page bug','sent_at_ms'=>$ago(88),'created_at'=>$now,'updated_at'=>$now],
        ]);
        $rules = [['logo,brand,poster,banner,design,graphic,ui,ux,mockup,creative','Design'],['website,web,app,bug,code,develop,api,landing page,wordpress,html','Development'],['blog,article,copy,caption,content,write,script,newsletter','Content'],['ad,ads,campaign,promo,social,instagram,facebook,marketing,launch','Marketing'],['seo,keyword,rank,search,backlink,meta,traffic','SEO']];
        DB::table('delegation_rules')->insert(array_map(fn($x,$i)=>['sort_order'=>$i,'keywords'=>$x[0],'department'=>$x[1],'created_at'=>$now,'updated_at'=>$now],$rules,array_keys($rules)));
        DB::table('settings')->insert([['key'=>'amberMin','value'=>'15','created_at'=>$now,'updated_at'=>$now],['key'=>'redMin','value'=>'25','created_at'=>$now,'updated_at'=>$now]]);
    }
}
