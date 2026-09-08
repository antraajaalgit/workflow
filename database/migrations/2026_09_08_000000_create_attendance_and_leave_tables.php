<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->date('attendance_date');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->enum('status', ['present', 'absent', 'paid_leave', 'unpaid_leave', 'weekly_off']);
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_checkout')->default(false);
            $table->unsignedInteger('worked_minutes')->nullable();
            foreach (['check_in', 'check_out'] as $prefix) {
                $table->decimal($prefix.'_latitude', 10, 7)->nullable();
                $table->decimal($prefix.'_longitude', 10, 7)->nullable();
                $table->decimal($prefix.'_accuracy', 10, 3)->nullable();
            }
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('attendance_working_day_overrides', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->date('work_date');
            $table->enum('type', ['overtime'])->default('overtime');
            $table->string('approved_by', 40)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_by', 40)->nullable();
            $table->text('revocation_note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'work_date'], 'attendance_override_user_date_unique');
            $table->index('work_date');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('reviewed_by', 40)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('qualifying_days')->default(0);
            $table->unsignedInteger('paid_leave_days')->default(0);
            $table->unsignedInteger('unpaid_leave_days')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('leave_request_id', 40);
            $table->date('leave_date');
            $table->enum('type', ['paid', 'unpaid']);
            $table->unique(['leave_request_id', 'leave_date']);
            $table->index(['leave_date', 'type']);
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_working_day_overrides');
        Schema::dropIfExists('attendance_records');
    }
};
