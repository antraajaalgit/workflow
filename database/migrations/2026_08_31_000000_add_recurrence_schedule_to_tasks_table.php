<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('next_recurrence_at_ms')->nullable()->after('recurring');
            $table->index('next_recurrence_at_ms');
        });

        DB::table('tasks')->whereNotNull('recurring')->orderBy('id')->each(function (object $task): void {
            $created = CarbonImmutable::createFromTimestampMs((int) $task->created_at_ms);
            $nextRunMs = match ($task->recurring) {
                'daily' => $created->addDay()->getTimestampMs(),
                'alternate_days' => $created->addDays(2)->getTimestampMs(),
                'weekly' => $created->addWeek()->getTimestampMs(),
                'monthly' => $created->addMonthNoOverflow()->getTimestampMs(),
                default => null,
            };

            if ($nextRunMs !== null) {
                DB::table('tasks')->where('id', $task->id)->update([
                    'next_recurrence_at_ms' => $nextRunMs,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['next_recurrence_at_ms']);
            $table->dropColumn('next_recurrence_at_ms');
        });
    }
};
