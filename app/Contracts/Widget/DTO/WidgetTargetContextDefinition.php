<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Code-registered Target Context declaration (architecture.md "Slot and
 * Scope Contract" / adr-widget-project-authorization.md decision 2): names
 * a stable target type, its display label key, the Template Context key it
 * applies to, and the FQCN of the WidgetTargetContextResolver service that
 * implements project ownership/readability checks and typed search/resolve
 * for this target type. Root DTO — primitives only.
 */
final readonly class WidgetTargetContextDefinition
{
    public function __construct(
        public string $targetType,
        public string $labelKey,
        public string $templateContextKey,
        public string $resolverService,
    ) {
    }
}
