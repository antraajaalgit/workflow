<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('nagare:reset', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder', '--force' => true]);
    $this->info('Nagare demo data reset.');
})->purpose('Reset Nagare to its demo data');
