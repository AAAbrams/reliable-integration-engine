<?php

declare(strict_types=1);

use App\Domain\Enums\ExternalOperationState;
use Illuminate\Database\QueryException;
use Tests\Support\OperationData;

test('creates an operation with initial database defaults', function () {
    $data = OperationData::valid();

    DB::table('external_operations')->insert($data);

    $operation = DB::table('external_operations')
        ->where('id', (string) $data['id'])
        ->selectRaw(
            '
            *,
            (available_at = CURRENT_TIMESTAMP(6))::int AS available_ok,
            (created_at = CURRENT_TIMESTAMP(6))::int AS created_ok,
            (updated_at = CURRENT_TIMESTAMP(6))::int AS updated_ok
            '
        )
        ->first();

    if ($operation === null) {
        throw new RuntimeException('Operation was not found');
    }

    expect((string) $operation->state)->toBe(ExternalOperationState::Queued->value)
        ->and($operation->provider_operation_id)->toBeNull()
        ->and($operation->attempts)->toBe(0)
        ->and((int) $operation->available_ok)->toBe(1)
        ->and($operation->lock_token)->toBeNull()
        ->and($operation->lease_expires_at)->toBeNull()
        ->and($operation->result)->toBeNull()
        ->and($operation->error_code)->toBeNull()
        ->and($operation->completed_at)->toBeNull()
        ->and((int) $operation->created_ok)->toBe(1)
        ->and((int) $operation->updated_ok)->toBe(1);
});

test('allows the same idempotency key for different clients', function () {
    $key = 'shared-idempotency-key';
    $first = OperationData::valid([
        'client_id' => 'client-1',
        'idempotency_key' => $key,
    ]);
    $second = OperationData::valid([
        'client_id' => 'client-2',
        'idempotency_key' => $key,
    ]);

    DB::table('external_operations')->insert([$first, $second]);

    $count = DB::table('external_operations')
        ->where('idempotency_key', $key)
        ->count();

    expect($count)->toBe(2);
});

test('allows different idempotency keys for the same client', function () {
    $client = 'shared-client';
    $first = OperationData::valid(['client_id' => $client]);
    $second = OperationData::valid(['client_id' => $client]);

    DB::table('external_operations')->insert([$first, $second]);

    $count = DB::table('external_operations')
        ->where('client_id', $client)
        ->count();

    expect($count)->toBe(2);
});

test('rejects a duplicate idempotency key for the same client', function () {
    $client = 'shared-client';
    $key = 'shared-idempotency-key';
    $first = OperationData::valid([
        'client_id' => $client,
        'idempotency_key' => $key,
    ]);
    $second = OperationData::valid([
        'client_id' => $client,
        'idempotency_key' => $key,
    ]);
    DB::table('external_operations')->insert($first);

    $exception = null;

    try {
        DB::transaction(function () use ($second) {
            DB::table('external_operations')->insert($second);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23505');

    $count = DB::table('external_operations')
        ->where('client_id', $client)
        ->where('idempotency_key', $key)
        ->count();

    expect($count)->toBe(1);
});

test('preserves a positive operation attempt count', function () {
    $data = OperationData::valid(['attempts' => 1]);

    DB::table('external_operations')->insert($data);

    $attempt = DB::table('external_operations')
        ->where('id', (string) $data['id'])
        ->value('attempts');

    expect($attempt)->toBe(1);
});

test('rejects a negative operation attempt count', function () {
    $data = OperationData::valid(['attempts' => -1]);

    $exception = null;

    try {
        DB::transaction(function () use ($data) {
            DB::table('external_operations')->insert($data);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23514');

    $count = DB::table('external_operations')
        ->where('id', (string) $data['id'])
        ->count();

    expect($count)->toBe(0);
});

test('accepts every declared operation state', function (string $state) {
    $data = OperationData::valid(['state' => $state]);

    DB::table('external_operations')->insert($data);

    $gotState = DB::table('external_operations')
        ->where('id', (string) $data['id'])
        ->value('state');

    expect($gotState)->toBe($state);
})->with([
    'queued',
    'running',
    'waiting_remote',
    'retry_wait',
    'completed',
    'failed',
]);

test('rejects an unknown operation state', function () {
    $data = OperationData::valid(['state' => 'unknown']);

    $exception = null;

    try {
        DB::transaction(function () use ($data) {
            DB::table('external_operations')->insert($data);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23514');

    $count = DB::table('external_operations')
        ->where('id', (string) $data['id'])
        ->count();

    expect($count)->toBe(0);
});
