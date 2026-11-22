<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * What to render: a legacy Widget/Theme identity plus caller-supplied
 * values. Values are advisory input only — the bound WidgetRenderer is
 * responsible for allowlisting them against the canonical schema before
 * use (see CanonicalWidgetSchemaResolver). Root DTO — primitives only.
 */
final readonly class WidgetRenderSpec
{
    /**
     * @param array<string, mixed> $values caller-supplied (possibly stale/copied) input values
     */
    public function __construct(
        public int $widgetId,
        public int $themeId,
        public array $values = [],
    ) {
    }
}
