<?php

declare(strict_types=1);

namespace App\Support\SignedApp;

use App\Contracts\SignedApp\SignedAppClientProvider;
use App\Contracts\SignedApp\SignedAppCredential;
use App\Contracts\SignedApp\SignedAppPrincipal;
use Illuminate\Http\Request;
use LogicException;

final class NullSignedAppClientProvider implements SignedAppClientProvider
{
    public function findActive(string $appId): ?SignedAppCredential
    {
        return null;
    }

    public function decryptSecret(SignedAppCredential $credential): string
    {
        throw new LogicException('No signed app client provider is available.');
    }

    public function accepted(
        Request $request,
        SignedAppCredential $credential,
        SignedAppPrincipal $principal,
    ): void {
    }
}
