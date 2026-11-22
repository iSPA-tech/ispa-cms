<?php

declare(strict_types=1);

namespace App\Enums;

enum SignedAppAudience: string
{
    case INTERNAL = 'internal';
    case BETA = 'beta';
    case PUBLIC = 'public';
}
