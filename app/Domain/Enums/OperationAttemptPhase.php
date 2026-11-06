<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum OperationAttemptPhase: string
{
    case Poll = 'poll';

    case Submit = 'submit';
}
