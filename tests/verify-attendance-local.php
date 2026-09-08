<?php

// Manual, read-only verification of the already-migrated LOCAL MySQL installation.
require __DIR__.'/../vendor/autoload.php';

use App\Services\AttendanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDriverName() !== 'mysql'
    || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
    || $connection->getConfig('read') || $connection->getConfig('write')) {
    throw new RuntimeException('Verification requires a single local MySQL connection.');
}

// No fixtures, writes, migrations or role changes. MySQL enforces read-only access here.
$connection->statement('SET TRANSACTION READ ONLY');
$connection->beginTransaction();
try {
    $tables = ['attendance_records', 'attendance_working_day_overrides', 'leave_requests', 'leave_request_days'];
    $present = [];
    foreach ($tables as $table) {
        $present[$table] = Schema::hasTable($table);
    }
    $indexes = Schema::getIndexes('attendance_records');
    $unique = count(array_filter($indexes, fn ($index) => $index['unique'] && $index['columns'] === ['user_id', 'attendance_date'])) === 1;
    $before = DB::table('attendance_records')->count();
    $employee = DB::table('users')->where('role', 'team')->where('role_id', 2)->value('id');
    $state = $employee ? app(AttendanceService::class)->today($employee) : null;
    $result = [
        'connection' => 'local_mysql',
        'migration_applied' => DB::table('migrations')->where('migration', '2026_09_08_000000_create_attendance_and_leave_tables')->exists(),
        'tables_present' => $present, 'attendance_user_date_unique' => $unique,
        'today_service_checked' => $state !== null,
        'timezone' => $state['timezone'] ?? config('attendance.timezone'),
        'attendance_count_unchanged' => $before === DB::table('attendance_records')->count(),
        'dedicated_mysql_test_credentials_available' => getenv('KARYA_MYSQL_TEST_USERNAME') === 'workflow_task_api_test' && (bool) getenv('KARYA_MYSQL_TEST_PASSWORD'),
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $connection->rollBack();
    DB::disconnect();
}
