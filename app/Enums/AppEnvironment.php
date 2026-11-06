<?php

declare(strict_types=1);

namespace App\Enums;

enum AppEnvironment: string
{
    case Testing = 'testing';
    case Production = 'production';
    case Development = 'development';
}
