<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Support\Widget\LegacyWidgetRenderer;

/**
 * Inline/compatibility-only rendering adapter (plan Chunk 1 Task 8).
 *
 * Wraps the root-owned App\Support\Widget\LegacyWidgetRenderer unchanged so
 * cms/Widget never re-implements canonical-schema resolution or input
 * sanitization — both stay root-owned per
 * docs/architecture/adr-widget-module-boundary.md decision 4. This class
 * composes the exact same root Support class the default WidgetRenderer
 * binding uses instead of duplicating its
 * canonical-schema + sanitize + WidgetQueryService::preRender() path, which
 * is what keeps module-owned output byte-identical to the legacy default.
 *
 * Scope freeze: this adapter is Inline/compatibility rendering ONLY. It has
 * no Site Layout, Slot, or Placement awareness — it accepts only the
 * legacy WidgetRenderSpec (Widget/Theme id + advisory values) and cannot be
 * selected by a Placement. It MUST NOT gain a Site Layout path outside
 * Task 9c's approval gate; deliberately `final` with a single public
 * `render()` method so no subclass or additional method can smuggle one in.
 * See cms/Widget/tests/Feature/Rendering/WidgetRenderCompatibilityTest.php
 * ("exposes no Site Layout surface") for the executable guard.
 */
final class LegacyWidgetRenderAdapter
{
    public function __construct(private readonly LegacyWidgetRenderer $legacyRenderer)
    {
    }

    public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
    {
        return $this->legacyRenderer->render($spec, $context);
    }
}
