<?php

declare(strict_types=1);

namespace App\Contracts\SignedApp;

use Illuminate\Http\Request;

interface SignedAppClientProvider
{
    public function findActive(string $appId): ?SignedAppCredential;

    public function decryptSecret(SignedAppCredential $credential): string;

    public function accepted(
        Request $request,
        SignedAppCredential $credential,
        SignedAppPrincipal $principal,
    ): void;
}
