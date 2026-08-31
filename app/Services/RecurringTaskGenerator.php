<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringTaskGenerator
{
    public function generateDueTasks(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $nowMs = $at->getTimestampMs();
        $created = 0;

        DB::transaction(function () use ($nowMs, &$created): void {
            $templates = DB::table('tasks')
                ->whereNotNull('recurring')
                ->whereNotNull('next_recurrence_at_ms')
                ->where('next_recurrence_at_ms', '<=', $nowMs)
                ->lockForUpdate()
                ->get();

            foreach ($templates as $template) {
                if (!$this->isSupportedFrequency($template->recurring)) {
                    continue;
                }

                $nextRunMs = (int) $template->next_recurrence_at_ms;
                $dueOffsetMs = $template->due_date_ms !== null
                    ? (int) $template->due_date_ms - (int) $template->created_at_ms
                    : null;
                while ($nextRunMs <= $nowMs) {
                    $followingRunMs = $this->nextRunMs($nextRunMs, $template->recurring);
                    $occurrence = (array) $template;
                    $occurrence['id'] = (string) Str::uuid();
                    $occurrence['status'] = 'todo';
                    $occurrence['progress'] = 'just_started';
                    $occurrence['created_at_ms'] = $nextRunMs;
                    $occurrence['stage_at_ms'] = $nextRunMs;
                    $occurrence['due_date_ms'] = $dueOffsetMs !== null ? $nextRunMs + $dueOffsetMs : null;
                    $occurrence['recurring'] = null;
                    $occurrence['next_recurrence_at_ms'] = null;
                    $occurrence['created_at'] = now();
                    $occurrence['updated_at'] = now();

                    DB::table('tasks')->insert($occurrence);
                    $created++;
                    $nextRunMs = $followingRunMs;
                }

                DB::table('tasks')->where('id', $template->id)->update([
                    'next_recurrence_at_ms' => $nextRunMs,
                    'updated_at' => now(),
                ]);
            }
        });

        return $created;
    }

    private function isSupportedFrequency(?string $frequency): bool
    {
        return in_array($frequency, ['daily', 'alternate_days', 'weekly', 'monthly'], true);
    }

    private function nextRunMs(int $fromMs, string $frequency): int
    {
        $from = CarbonImmutable::createFromTimestampMs($fromMs);

        return match ($frequency) {
            'daily' => $from->addDay()->getTimestampMs(),
            'alternate_days' => $from->addDays(2)->getTimestampMs(),
            'weekly' => $from->addWeek()->getTimestampMs(),
            'monthly' => $from->addMonthNoOverflow()->getTimestampMs(),
        };
    }
}
