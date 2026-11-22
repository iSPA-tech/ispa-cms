<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * A code-registered Slot declaration: stable key, display label key, and
 * cardinality. Frozen cardinality values (plan "Scope and cardinality"):
 * `single`, `replace`, `stack`. Root DTO — primitives only.
 */
final readonly class WidgetSlotDefinition
{
    public const CARDINALITY_SINGLE = 'single';
    public const CARDINALITY_REPLACE = 'replace';
    public const CARDINALITY_STACK = 'stack';

    public function __construct(
        public string $key,
        public string $labelKey,
        public string $cardinality = self::CARDINALITY_STACK,
    ) {
    }
}
