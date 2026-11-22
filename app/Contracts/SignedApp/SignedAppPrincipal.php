<?php

declare(strict_types=1);

namespace App\Contracts\SignedApp;

use App\Enums\SignedAppAudience;

final readonly class SignedAppPrincipal
{
    public function __construct(
        public string $clientKey,
        public string $appId,
        public string $platform,
        public SignedAppAudience $audience,
    ) {
    }
}
