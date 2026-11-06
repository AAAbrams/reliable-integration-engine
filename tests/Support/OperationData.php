<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;
use JsonException;

final class OperationData
{
    /**
     * @param  array<string, string|int|null>  $overrides
     * @return array<string, string|int|null>
     *
     * @throws JsonException
     */
    public static function valid(array $overrides = []): array
    {
        $snapshot = json_encode([
            'document_id' => '123',
            'document_type_id' => '1',
        ], JSON_THROW_ON_ERROR);

        return array_replace([
            'id' => (string) Str::uuid(),
            'client_id' => 'test-client',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', $snapshot),
            'request_snapshot' => $snapshot,
        ], $overrides);
    }
}
