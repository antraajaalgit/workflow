<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\RecurringTaskGenerator;

Artisan::command('nagare:reset', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder', '--force' => true]);
    $this->info('Nagare demo data reset.');
})->purpose('Reset Nagare to its demo data');

Artisan::command('nagare:generate-recurring-tasks', function (RecurringTaskGenerator $generator) {
    $count = $generator->generateDueTasks();
    $this->info("Created {$count} recurring task occurrence(s).");
})->purpose('Create task occurrences whose recurrence schedule is due');

Schedule::command('nagare:generate-recurring-tasks')
    ->everyMinute()
    ->withoutOverlapping();
