<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Outcome of a WidgetDataProvider fetch. `items` is always a plain array
 * projection (no framework Collection leakage across the contract
 * boundary) so root/Core never needs to know about module-owned model
 * classes.
 */
final readonly class WidgetProviderResult
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        public array $items = [],
        public bool $ok = true,
        public ?string $errorCode = null,
    ) {
    }
}
