<?php

namespace App\Console\Commands;

use App\Services\CompletedTaskPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeCompletedTasks extends Command
{
    protected $signature = 'tasks:purge-completed {--dry-run : Count eligible tasks without deleting tasks or files}';
    protected $description = 'Permanently delete completed non-template tasks after exactly 30 elapsed days';

    public function handle(CompletedTaskPurger $purger): int
    {
        try {
            $result = $purger->run((bool) $this->option('dry-run'));
            if ($this->option('dry-run')) {
                $this->info("Dry run: {$result['eligible']} completed tasks eligible for permanent deletion. No data or files changed.");
                return self::SUCCESS;
            }
            $message = "Completed task cleanup finished: {$result['deleted']} tasks deleted.";
            $this->info($message);
            Log::info($message, $result);
            if ($result['failed'] || $result['pending_files']) {
                $this->warn("{$result['failed']} task failures; {$result['pending_files']} attachment deletions pending retry.");
                return self::FAILURE;
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Completed task cleanup interrupted.', ['error_type' => $exception::class]);
            $this->error('Completed task cleanup could not finish. Check the application log.');
            return self::FAILURE;
        }
    }
}
