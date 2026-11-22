<?php

declare(strict_types=1);

namespace App\Contracts\PublicAuthority;

final readonly class PublicAuthorityQuery
{
    public function __construct(
        public string $authorityHint,
        public ?string $localityKey,
    ) {
    }
}
