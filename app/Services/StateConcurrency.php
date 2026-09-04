<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Coordinates state reads, legacy non-task saves, and targeted transactional writers. */
class StateConcurrency
{
    public function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        // Nested task operations share the outer writer's lock and transaction.
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction($operation);
        }
        $mysql = $connection->getDriverName() === 'mysql';
        $name = 'karya-state-'.substr(hash('sha256', $connection->getDatabaseName()), 0, 40);
        if ($mysql) {
            $lock = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name], false);
            abort_unless((int) $lock->acquired === 1, 503, 'State is busy. Please retry.');
        }
        try {
            return $connection->transaction($operation);
        } finally {
            if ($mysql) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
            }
        }
    }

    public function revision(): string
    {
        $state = [];
        foreach (['clients', 'departments', 'users', 'projects', 'tasks', 'messages', 'activities', 'notifications', 'delegation_rules', 'settings'] as $table) {
            if (Schema::hasTable($table)) {
                $state[$table] = DB::table($table)->orderBy($table === 'settings' ? 'key' : 'id')->get()->all();
            }
        }

        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function check(mixed $revision): void
    {
        abort_unless(is_string($revision) && $revision && hash_equals($this->revision(), $revision), 409,
            'Application data changed. Reload before saving; your changes have not been saved.');
    }
}
