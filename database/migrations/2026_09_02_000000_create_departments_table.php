<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 80)->unique();
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('departments')->insert([
            ['id'=>'dept_design','name'=>'Design','color'=>'#7a5c3e','created_at'=>$now,'updated_at'=>$now],
            ['id'=>'dept_development','name'=>'Development','color'=>'#3a6ea5','created_at'=>$now,'updated_at'=>$now],
            ['id'=>'dept_content','name'=>'Content','color'=>'#a97e2e','created_at'=>$now,'updated_at'=>$now],
            ['id'=>'dept_marketing','name'=>'Marketing','color'=>'#d94a3d','created_at'=>$now,'updated_at'=>$now],
            ['id'=>'dept_seo','name'=>'SEO','color'=>'#3fa34d','created_at'=>$now,'updated_at'=>$now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
