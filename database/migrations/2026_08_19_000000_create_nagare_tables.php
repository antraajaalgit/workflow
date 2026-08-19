<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name'); $table->string('company'); $table->string('email')->nullable();
            $table->string('phone')->nullable(); $table->string('color', 20)->nullable(); $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 40)->primary(); $table->string('name'); $table->string('role', 20);
            $table->string('department', 40)->nullable(); $table->string('client_id', 40)->nullable();
            $table->string('company')->nullable(); $table->string('color', 20)->nullable(); $table->timestamps();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->string('id', 40)->primary(); $table->string('client_id', 40); $table->string('title');
            $table->text('description')->nullable(); $table->string('department', 40); $table->string('owner_id', 40)->nullable();
            $table->string('status', 30); $table->string('priority', 20); $table->unsignedBigInteger('created_at_ms');
            $table->unsignedBigInteger('stage_at_ms'); $table->unsignedBigInteger('due_date_ms')->nullable(); $table->string('recurring', 20)->nullable();
            $table->timestamps(); $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->string('id', 40)->primary(); $table->string('client_id', 40); $table->string('task_id', 40)->nullable();
            $table->string('from_id', 40); $table->string('from_role', 20); $table->text('text')->nullable();
            $table->longText('voice')->nullable(); $table->unsignedBigInteger('sent_at_ms'); $table->timestamps();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
        Schema::create('activities', function (Blueprint $table) {
            $table->string('id', 40)->primary(); $table->unsignedBigInteger('occurred_at_ms'); $table->text('text'); $table->string('type', 30); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id', 40)->primary(); $table->string('channel', 20); $table->string('recipient');
            $table->text('text'); $table->unsignedBigInteger('sent_at_ms'); $table->timestamps();
        });
        Schema::create('delegation_rules', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('sort_order'); $table->text('keywords'); $table->string('department', 40); $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary(); $table->text('value'); $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['settings','delegation_rules','notifications','activities','messages','tasks','users','clients'] as $table) Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }
};
