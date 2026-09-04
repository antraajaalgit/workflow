<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Never takes connection details from the application's DB_* configuration. */
class IsolatedDatabase
{
    private ?string $prefix = null;

    public function connect(): void
    {
        if (getenv('KARYA_MYSQL_TEST') !== '1') {
            config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            DB::purge('sqlite');

            return;
        }
        $username = getenv('KARYA_MYSQL_TEST_USERNAME');
        $password = getenv('KARYA_MYSQL_TEST_PASSWORD');
        if ($username !== 'workflow_task_api_test' || ! $password) {
            throw new RuntimeException('MySQL tests require the dedicated workflow_task_api_test database/account and KARYA_MYSQL_TEST_USERNAME/PASSWORD. Normal DB_* credentials are never used.');
        }
        $this->prefix = 't'.bin2hex(random_bytes(4)).'_';
        config(['database.default' => 'task_mysql_test', 'database.connections.task_mysql_test' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => getenv('KARYA_MYSQL_TEST_PORT') ?: '3306',
            'database' => 'workflow_task_api_test', 'username' => $username, 'password' => $password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => $this->prefix,
            'prefix_indexes' => true, 'strict' => true, 'engine' => 'InnoDB',
        ]]);
        DB::purge('task_mysql_test');
        if (DB::connection()->getDatabaseName() !== 'workflow_task_api_test') {
            throw new RuntimeException('Refusing a non-test database.');
        }
    }

    public function cleanup(): void
    {
        if (! $this->prefix || DB::getDefaultConnection() !== 'task_mysql_test') {
            return;
        }
        $connection = DB::connection();
        if ($connection->getDatabaseName() !== 'workflow_task_api_test' || $connection->getTablePrefix() !== $this->prefix) {
            throw new RuntimeException('Refusing unsafe test cleanup.');
        }
        // Only this individual test's randomly prefixed tables can be removed.
        $tables = array_filter(Schema::getTableListing(null, false), fn ($table) => str_starts_with($table, $this->prefix));
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                Schema::drop(substr($table, strlen($this->prefix)));
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
