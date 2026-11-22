<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * The frozen return type of `WidgetTargetContextResolver::resolve()`: one
 * exact, authorized target. `targetId` is opaque end-to-end (D-046).
 */
final readonly class WidgetResolvedTarget
{
    /**
     * @param array<string, mixed> $routeParameters
     */
    public function __construct(
        public string $targetId,
        public string $targetType,
        public string $label,
        public string $routeName,
        public array $routeParameters = [],
    ) {
    }
}
