<?php

require __DIR__.'/../vendor/autoload.php';

use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

[$script, $path, $signal, $operation] = $argv;
$resolved = realpath($path);
if (! $resolved || realpath(dirname($resolved)) !== realpath(sys_get_temp_dir())
    || ! str_starts_with(basename($resolved), 'karya-attendance-api-') || $signal !== $path.'.start'
    || ! in_array($operation, ['checkIn', 'checkOut'], true)) {
    throw new RuntimeException('Only an isolated attendance API test is allowed.');
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
    'driver' => 'sqlite', 'database' => $resolved, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 1000,
]]);
DB::purge('sqlite');
// Synthetic fixture coordinates, never application defaults.
config(['attendance.geofence' => ['office_latitude' => 0, 'office_longitude' => 0,
    'radius_metres' => 100, 'max_accuracy_metres' => 50]]);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 '.($operation === 'checkIn' ? '09:30:00' : '18:30:00'), 'Asia/Kolkata'));
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
    app(AttendanceService::class)->$operation('one', ['latitude' => 0, 'longitude' => 0, 'accuracy' => 10]);
    exit(0);
} catch (HttpExceptionInterface $exception) {
    if ($exception->getStatusCode() === 409) {
        exit(9);
    }
    throw $exception;
}
