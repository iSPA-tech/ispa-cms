<?php

declare(strict_types=1);

namespace App\Support\PublicAuthority;

use App\Contracts\PublicAuthority\PublicAuthorityQuery;
use App\Contracts\PublicAuthority\PublicAuthorityResolver;
use App\Contracts\PublicAuthority\PublicAuthorityResult;

final class UnavailablePublicAuthorityResolver implements PublicAuthorityResolver
{
    public function resolve(PublicAuthorityQuery $query): PublicAuthorityResult
    {
        return PublicAuthorityResult::unavailable();
    }
}
