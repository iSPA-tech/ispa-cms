<?php

declare(strict_types=1);

namespace App\Contracts\PublicAuthority;

interface PublicAuthorityResolver
{
    public function resolve(PublicAuthorityQuery $query): PublicAuthorityResult;
}
