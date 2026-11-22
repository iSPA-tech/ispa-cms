<?php

declare(strict_types=1);

namespace Tests\Feature\PublicAuthority;

use App\Contracts\PublicAuthority\PublicAuthorityQuery;
use App\Contracts\PublicAuthority\PublicAuthorityResolver;
use App\Support\PublicAuthority\UnavailablePublicAuthorityResolver;
use Tests\TestCase;

final class PublicAuthorityResolverDefaultTest extends TestCase
{
    public function test_root_binding_is_unavailable_and_has_no_module_dependency(): void
    {
        $this->app->singleton(PublicAuthorityResolver::class, UnavailablePublicAuthorityResolver::class);

        $resolver = $this->app->make(PublicAuthorityResolver::class);
        $result = $resolver->resolve(new PublicAuthorityQuery('tax_management', null));

        $this->assertInstanceOf(UnavailablePublicAuthorityResolver::class, $resolver);
        $this->assertFalse($result->available);
        $this->assertNull($result->name);
        $this->assertStringNotContainsString('Cms\\', $resolver::class);
    }
}
