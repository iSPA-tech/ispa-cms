<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Services\WidgetQueryService;

/**
 * Bound default for WidgetRenderer. Always derives Definitions from
 * canonical Core-owned relations (via CanonicalWidgetSchemaResolver),
 * allowlists keys through WidgetInputSanitizer, validates Widget/Theme
 * ownership, and treats any caller-supplied (e.g. copied Page block)
 * schema as advisory only. Wraps the existing
 * WidgetQueryService::preRender() Inline path unchanged so Inline output
 * stays semantically identical whether cms/Widget is enabled or disabled.
 */
final class LegacyWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly CanonicalWidgetSchemaResolver $schemaResolver,
        private readonly WidgetInputSanitizer $sanitizer,
    ) {
    }

    public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
    {
        // Emergency block wins over everything else: a blocked widget renders nothing on every path
        // (page blocks, Site Layout areas, previews). Resolved per call because the state is scoped
        // to the request while this renderer is a singleton.
        if (app(WidgetBlockState::class)->isBlocked($spec->widgetId)) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: 'WIDGET_BLOCKED');
        }

        if (!$this->schemaResolver->isValidPairing($spec->widgetId, $spec->themeId)) {
            // Widget/Theme mismatch or unknown pairing: reject without any
            // lookup/exception detail leaking to the caller.
            return new WidgetRenderResult(html: '', ok: false, errorCode: 'WIDGET_SCHEMA_INVALID');
        }

        // Resolved once: the sanitizer, the edit markers and repeater expansion share the same schema.
        $definitions = $this->schemaResolver->resolve($spec->widgetId, $spec->themeId);
        $sanitizedValues = $this->sanitizer->sanitize($spec->widgetId, $spec->themeId, $spec->values, $definitions);

        $editMarkers = $context->isPreview && $context->editMarkers;
        $inputTypes = [];
        if ($editMarkers) {
            foreach ($definitions as $name => $definition) {
                $inputTypes[(string) $name] = (string) ($definition['type'] ?? '');
            }
        }

        $html = WidgetQueryService::preRender(
            $spec->widgetId,
            $spec->themeId,
            $sanitizedValues,
            $context->useCss,
            $editMarkers,
            $inputTypes,
            $definitions
        );

        return new WidgetRenderResult(html: $html, ok: true);
    }
}
