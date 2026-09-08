<?php

// Test-only worker: cannot connect to the application's database or migrate anything.
require __DIR__.'/../vendor/autoload.php';

use App\Services\LeaveService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

[$script, $path, $signal, $requestId] = $argv;
$resolved = realpath($path);
if (! $resolved || realpath(dirname($resolved)) !== realpath(sys_get_temp_dir())
    || ! str_starts_with(basename($resolved), 'karya-attendance-') || $signal !== $path.'.start') {
    throw new RuntimeException('Only an isolated attendance test file is allowed.');
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
    'driver' => 'sqlite', 'database' => $resolved, 'prefix' => '', 'foreign_key_constraints' => true,
    'busy_timeout' => 1000,
]]);
DB::purge('sqlite');
echo "ready\n";
flush();
$deadline = microtime(true) + 10;
while (! is_file($signal)) {
    if (microtime(true) >= $deadline) {
        exit(2);
    }
    usleep(10000);
    clearstatcache(true, $signal);
}
try {
    app(LeaveService::class)->approve('admin', $requestId);
    exit(0);
} catch (QueryException $exception) {
    if (str_contains($exception->getMessage(), 'database is locked')) {
        exit(75);
    }
    throw $exception;
}
