<?php

declare(strict_types=1);

namespace App\Contracts\PublicAuthority;

final readonly class PublicAuthorityResult
{
    /**
     * @param array<string, scalar|null> $routeData
     */
    public function __construct(
        public bool $available,
        public ?string $name = null,
        public ?string $dutyDeskContact = null,
        public ?string $address = null,
        public array $routeData = [],
        public ?string $verificationStatus = null,
        public ?string $verifiedAt = null,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(available: false);
    }
}
