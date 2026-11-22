<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Capped page of WidgetTargetSummary rows, the frozen return type of
 * `WidgetTargetContextResolver::search()`.
 */
final readonly class WidgetTargetPage
{
    /**
     * @param list<WidgetTargetSummary> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
