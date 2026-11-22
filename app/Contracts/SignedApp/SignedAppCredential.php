<?php

declare(strict_types=1);

namespace App\Contracts\SignedApp;

use App\Enums\SignedAppAudience;

final readonly class SignedAppCredential
{
    /**
     * @param array<int, string> $allowedPlatforms
     */
    public function __construct(
        public string $clientKey,
        public string $appId,
        public string $encryptedSecret,
        public array $allowedPlatforms,
        public SignedAppAudience $audience,
    ) {
    }
}
