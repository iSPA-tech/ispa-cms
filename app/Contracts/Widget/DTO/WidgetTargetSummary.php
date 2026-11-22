<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Safe projection of one search result row. `targetId` is opaque end-to-end
 * (D-046): production adapters emit a UUID, never a numeric database ID.
 * `routeName`/`routeParameters` are the canonical public route — callers
 * must never branch on target type or reconstruct URLs themselves.
 */
final readonly class WidgetTargetSummary
{
    /**
     * @param array<string, mixed> $routeParameters
     */
    public function __construct(
        public string $targetId,
        public string $label,
        public string $routeName,
        public array $routeParameters = [],
    ) {
    }
}
