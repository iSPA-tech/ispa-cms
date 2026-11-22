<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Typed, capped target search criteria (D-046, FR-50). `perPage` is always
 * clamped to MAX_PER_PAGE so a resolver can never be asked to return an
 * unbounded page. `targetType` selects which registered
 * WidgetTargetContextDefinition/resolver handles the query.
 */
final readonly class WidgetTargetSearchQuery
{
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 20;

    public int $page;
    public int $perPage;

    public function __construct(
        public string $targetType,
        public string $keywords = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->page = max(1, $page);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
