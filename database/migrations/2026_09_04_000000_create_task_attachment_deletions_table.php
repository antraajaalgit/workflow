<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('task_attachment_deletions', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('path', 300)->unique();
            $table->timestamps();
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->index(['status', 'progress', 'stage_at_ms'], 'tasks_completed_cleanup_index'));
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('tasks_completed_cleanup_index'));
        Schema::dropIfExists('task_attachment_deletions');
    }
};
