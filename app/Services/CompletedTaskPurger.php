<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompletedTaskPurger
{
    public const RETENTION_MS = 30 * 24 * 60 * 60 * 1000;

    public function run(bool $dryRun = false): array
    {
        $cutoff = now()->getTimestampMs() - self::RETENTION_MS;
        $tasks = app(TaskService::class);
        if ($dryRun) return ['eligible' => $tasks->completedBefore($cutoff)->count(), 'deleted' => 0, 'failed' => 0, 'pending_files' => DB::table('task_attachment_deletions')->count()];
        // Retry durable leftovers from a previous crash/storage failure, without a worker.
        app(TaskAttachmentCleanup::class)->drain();
        $deleted = $failed = 0;
        $tasks->completedBefore($cutoff)->select('id')->chunkById(100, function ($rows) use ($tasks, $cutoff, &$deleted, &$failed) {
            foreach ($rows as $row) {
                try {
                    if ($tasks->purgeCompleted($row->id, $cutoff)) $deleted++;
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::error('Completed task cleanup failed.', ['task_id' => $row->id, 'error_type' => $exception::class, 'error_code' => $exception->getCode()]);
                }
            }
        });
        return ['deleted' => $deleted, 'failed' => $failed, 'pending_files' => DB::table('task_attachment_deletions')->count()];
    }
}
