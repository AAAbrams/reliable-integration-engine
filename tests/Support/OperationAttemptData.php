<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Enums\OperationAttemptPhase;
use Illuminate\Support\Str;

final class OperationAttemptData
{
    /**
     * @param  array<string, int|string|null>  $overrides
     * @return array<string, int|string|null>
     */
    public static function valid(string $operationId, array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid(),
            'operation_id' => $operationId,
            'sequence' => 1,
            'phase' => OperationAttemptPhase::Submit->value,
            'started_at' => now('UTC')->toISOString(),
        ], $overrides);
    }
}
