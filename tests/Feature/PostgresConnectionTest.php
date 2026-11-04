<?php

use Illuminate\Support\Facades\DB;

it('connects to the isolated PostgreSQL database in UTC without a schema', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.host'))->toBe('postgres-test')
        ->and(config('database.connections.pgsql.database'))->toBe('rie_test');

    $connection = DB::connection();

    expect($connection->selectOne('SELECT 1 AS result')?->result)->toBe(1)
        ->and($connection->selectOne('SELECT current_database() AS name')?->name)->toBe('rie_test')
        ->and($connection->selectOne('SHOW TIME ZONE')?->TimeZone)->toBe('UTC');
})->group('integration');
