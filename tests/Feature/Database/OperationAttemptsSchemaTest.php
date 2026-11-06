<?php

declare(strict_types=1);

use App\Domain\Enums\OperationAttemptPhase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperationAttemptData;
use Tests\Support\OperationData;

test('creates an unfinished attempt with null completion fields', function () {
    $operation = OperationData::valid();

    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id']);

    DB::table('operation_attempts')->insert($attemptData);

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    if ($attempt === null) {
        throw new RuntimeException('Failed to create operation attempt');
    }

    expect($attempt->outcome)->toBeNull()
        ->and($attempt->error_code)->toBeNull()
        ->and($attempt->finished_at)->toBeNull()
        ->and($attempt->duration_ms)->toBeNull();
});

test('allows distinct attempt sequences within an operation', function () {
    $operation = OperationData::valid();
    $operationId = (string) $operation['id'];

    DB::table('external_operations')->insert($operation);

    $firstAttemptData = OperationAttemptData::valid($operationId, ['sequence' => 1]);
    $secondAttemptData = OperationAttemptData::valid($operationId, ['sequence' => 2]);

    DB::table('operation_attempts')->insert([$firstAttemptData, $secondAttemptData]);

    $attempts = DB::table('operation_attempts')
        ->whereIn('id', [
            (string) $firstAttemptData['id'],
            (string) $secondAttemptData['id'],
        ])
        ->get();
    $firstAttempt = $attempts
        ->where('id', (string) $firstAttemptData['id'])
        ->first();
    $secondAttempt = $attempts
        ->where('id', (string) $secondAttemptData['id'])
        ->first();

    if ($firstAttempt === null || $secondAttempt === null) {
        throw new RuntimeException('Failed to create operation attempts');
    }

    expect($attempts->count())->toBe(2)
        ->and($firstAttempt->operation_id)->toBe($operationId)
        ->and($firstAttempt->sequence)->toBe(1)
        ->and($secondAttempt->operation_id)->toBe($operationId)
        ->and($secondAttempt->sequence)->toBe(2);
});

test('allows the same attempt sequence across operations', function () {
    $firstOperation = OperationData::valid();
    $secondOperation = OperationData::valid();
    DB::table('external_operations')->insert([$firstOperation, $secondOperation]);

    $firstOperationAttempt = OperationAttemptData::valid((string) $firstOperation['id'], ['sequence' => 1]);
    $secondOperationAttempt = OperationAttemptData::valid((string) $secondOperation['id'], ['sequence' => 1]);

    DB::table('operation_attempts')->insert([$firstOperationAttempt, $secondOperationAttempt]);

    $attempts = DB::table('operation_attempts')
        ->whereIn('id', [
            (string) $firstOperationAttempt['id'],
            (string) $secondOperationAttempt['id'],
        ])
        ->get();
    $firstAttempt = $attempts
        ->where('id', (string) $firstOperationAttempt['id'])
        ->first();
    $secondAttempt = $attempts
        ->where('id', (string) $secondOperationAttempt['id'])
        ->first();

    if ($firstAttempt === null || $secondAttempt === null) {
        throw new RuntimeException('Failed to create operation attempts');
    }

    expect($attempts->count())->toBe(2)
        ->and($firstAttempt->operation_id)->toBe((string) $firstOperation['id'])
        ->and($firstAttempt->sequence)->toBe(1)
        ->and($secondAttempt->operation_id)->toBe((string) $secondOperation['id'])
        ->and($secondAttempt->sequence)->toBe(1);
});

test('rejects a duplicate attempt sequence within an operation across phases', function () {
    $operation = OperationData::valid();
    $operationId = (string) $operation['id'];

    DB::table('external_operations')->insert($operation);

    $firstAttempt = OperationAttemptData::valid($operationId, ['sequence' => 1]);
    DB::table('operation_attempts')->insert($firstAttempt);

    $secondAttempt = OperationAttemptData::valid(
        $operationId,
        [
            'sequence' => 1,
            'phase' => OperationAttemptPhase::Poll->value,
        ]
    );

    $exception = null;

    try {
        DB::transaction(function () use ($secondAttempt) {
            DB::table('operation_attempts')->insert($secondAttempt);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23505');

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $firstAttempt['id'])
        ->first();

    if ($attempt === null) {
        throw new RuntimeException('Failed to create operation attempt');
    }

    expect($attempt->operation_id)->toBe($operationId)
        ->and($attempt->sequence)->toBe(1);
});

test('rejects nonpositive attempt sequences', function (int $sequence) {
    $operation = OperationData::valid();

    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id'], ['sequence' => $sequence]);

    $exception = null;

    try {
        DB::transaction(function () use ($attemptData) {
            DB::table('operation_attempts')->insert($attemptData);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23514');

    $count = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->count();

    expect($count)->toBe(0);
})->with([0, -1]);

test('rejects an attempt for a nonexistent operation', function () {
    $attemptData = OperationAttemptData::valid((string) Str::uuid());

    $exception = null;

    try {
        DB::transaction(function () use ($attemptData) {
            DB::table('operation_attempts')->insert($attemptData);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23503');

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    expect($attempt)->toBeNull();
});

test('cascades operation deletion to its attempts', function () {
    $operation = OperationData::valid();
    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id']);
    DB::table('operation_attempts')->insert($attemptData);

    DB::table('external_operations')->delete((string) $operation['id']);

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    expect($attempt)->toBeNull();
});

test('accepts every declared operation attempt phase', function (string $phase) {
    $operation = OperationData::valid();
    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id'], ['phase' => $phase]);
    DB::table('operation_attempts')->insert($attemptData);

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    if ($attempt === null) {
        throw new RuntimeException('Failed to create operation attempt');
    }

    expect($attempt->operation_id)->toBe((string) $operation['id'])
        ->and($attempt->phase)->toBe($phase);
})->with(['poll', 'submit']);

test('rejects an unknown operation attempt phase', function () {
    $operation = OperationData::valid();
    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id'], ['phase' => 'unknown']);

    $exception = null;

    try {
        DB::transaction(function () use ($attemptData) {
            DB::table('operation_attempts')->insert($attemptData);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23514');

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    expect($attempt)->toBeNull();
});

test('preserves completion fields for zero and positive durations', function (int $durationMs) {
    $operation = OperationData::valid();
    DB::table('external_operations')->insert($operation);

    $currentTime = now('UTC');
    $nullableFields = [
        'outcome' => 'some info',
        'error_code' => '1000',
        'started_at' => $currentTime->toISOString(),
        'finished_at' => $currentTime->copy()
            ->addMilliseconds($durationMs)
            ->toISOString(),
        'duration_ms' => $durationMs,
    ];
    $attemptData = OperationAttemptData::valid(
        (string) $operation['id'],
        $nullableFields
    );
    DB::table('operation_attempts')->insert($attemptData);

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    if ($attempt === null) {
        throw new RuntimeException('Failed to create operation attempt');
    }

    expect($attempt->operation_id)->toBe((string) $operation['id'])
        ->and($attempt->outcome)->toBe($nullableFields['outcome'])
        ->and($attempt->error_code)->toBe($nullableFields['error_code'])
        ->and(Carbon::parse($attempt->finished_at)->toISOString())->toBe($nullableFields['finished_at'])
        ->and($attempt->duration_ms)->toBe($nullableFields['duration_ms']);
})->with([0, 10]);

test('rejects a negative attempt duration', function () {
    $operation = OperationData::valid();
    DB::table('external_operations')->insert($operation);

    $attemptData = OperationAttemptData::valid((string) $operation['id'], ['duration_ms' => -1]);

    $exception = null;

    try {
        DB::transaction(function () use ($attemptData) {
            DB::table('operation_attempts')->insert($attemptData);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception?->errorInfo[0] ?? null)->toBe('23514');

    $attempt = DB::table('operation_attempts')
        ->where('id', (string) $attemptData['id'])
        ->first();

    expect($attempt)->toBeNull();
});
