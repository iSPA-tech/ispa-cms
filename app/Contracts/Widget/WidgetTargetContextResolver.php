<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetTargetPage;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;

/**
 * Frozen per-target-type resolver contract (adr-widget-project-
 * authorization.md decisions 2-3, D-032/D-046). There is no single default
 * binding in AppServiceProvider: each WidgetTargetContextDefinition names
 * the FQCN of the resolver service responsible for its target type (e.g. a
 * project-owned Page adapter), registered through
 * WidgetTargetContextRegistrar. With no adapter registered, no target
 * metadata is resolved.
 *
 * The resolver alone is the authority for project ownership/readability,
 * distinguishing absent-in-authorized-scope 404 from denied 403, and for
 * emitting only safe projected metadata with opaque string identity.
 * Production adapters emit UUID target IDs, never numeric database IDs.
 */
interface WidgetTargetContextResolver
{
    public function search(AuthorizedProjectContext $context, WidgetTargetSearchQuery $query): WidgetTargetPage;

    public function resolve(AuthorizedProjectContext $context, string $targetId): WidgetResolvedTarget;
}
