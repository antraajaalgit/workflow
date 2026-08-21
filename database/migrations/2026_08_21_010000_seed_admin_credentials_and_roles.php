<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        DB::table('users')->where('role', 'team')->update(['role_id' => 2]);
        DB::table('users')->where('role', 'client')->update(['role_id' => 0]);
        DB::table('users')->where(function ($query) {
            $query->whereNull('password')->orWhere('password', '');
        })->get()->each(fn($user) => DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(Str::random(32))]));

        $now = now();
        $admins = [
            ['id'=>'u_admin_sales','name'=>'Sales Admin','email'=>'sales@antraajaal.com','password'=>'Jagmeet29','color'=>'#7a5c3e'],
            ['id'=>'u_admin_ceo','name'=>'CEO Admin','email'=>'ceo@antraajaal.com','password'=>'Agam123','color'=>'#c9a25f'],
            ['id'=>'u_admin_agam','name'=>'Agam Bahri','email'=>'agambahri@antraajaal.com','password'=>'Pluto0403','color'=>'#3a6ea5'],
        ];
        foreach ($admins as $admin) {
            DB::table('users')->updateOrInsert(['email'=>$admin['email']], [
                'id'=>$admin['id'],'name'=>$admin['name'],'role'=>'admin','role_id'=>1,'department'=>null,
                'client_id'=>null,'company'=>'Antrajaal','password'=>Hash::make($admin['password']),
                'phone'=>null,'color'=>$admin['color'],'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        DB::table('users')->where('role', 'admin')->whereNotIn('email', array_column($admins, 'email'))->delete();
    }

    public function down(): void
    {
        DB::table('users')->whereIn('email', ['sales@antraajaal.com','ceo@antraajaal.com','agambahri@antraajaal.com'])->delete();
    }
};
