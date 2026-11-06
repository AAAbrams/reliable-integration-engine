<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\AppEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment(AppEnvironment::Testing)) {
            throw new RuntimeException('Database tests require testing environment');
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('Database tests require PostgreSQL');
        }

        $connection = DB::connection();

        $identity = $connection->selectOne('SELECT current_database() AS db_name, current_user AS username');
        if ($identity->db_name !== 'rie_test' || $identity->username !== 'rie_test') {
            throw new RuntimeException('Unsafe test database connection');
        }
    }
}
