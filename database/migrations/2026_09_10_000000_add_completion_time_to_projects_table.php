<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('completed_at_ms')->nullable()->after('status');
            $table->index(['status', 'completed_at_ms'], 'projects_history_index');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_history_index');
            $table->dropColumn('completed_at_ms');
        });
    }
};
