<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->json('owner_ids')->nullable()->after('owner_id'));
        DB::table('tasks')->whereNotNull('owner_id')->orderBy('id')->each(fn ($task) =>
            DB::table('tasks')->where('id', $task->id)->update(['owner_ids' => json_encode([$task->owner_id])])
        );
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('owner_ids'));
    }
};
