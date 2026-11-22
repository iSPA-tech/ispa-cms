<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;

/**
 * Module-owned WidgetRenderer implementation (plan Chunk 1 Task 8; Chunk 2
 * Task 24 DoD 1279 telemetry).
 *
 * Rebound over the root App\Support\Widget\LegacyWidgetRenderer default by
 * Cms\Widget\Providers\WidgetServiceProvider::boot() with a plain
 * singleton() call, replicating the verified Achievements precedent
 * (Cms\Achievements\Providers\AchievementsServiceProvider::boot()). See
 * docs/architecture/adr-widget-module-boundary.md decision 3 for why the
 * rebind must happen in boot(), not register(): Nwidart auto-discovers
 * every enabled module provider's register() before
 * App\Providers\AppServiceProvider::register() runs, so only a
 * boot()-time rebind reliably wins regardless of provider ordering.
 *
 * Chunk 1 Task 8 scope: Site Layout Slot/Placement eligibility does not
 * exist yet (Task 9c+), so every WidgetRenderSpec handled here today is an
 * Inline/legacy spec and is delegated unconditionally to
 * LegacyWidgetRenderAdapter, which itself composes the same root Support
 * classes the legacy default uses. Site-Layout Placement rendering is a
 * SEPARATE path owned by {@see \Cms\Widget\SiteLayout\WidgetSlotRuntimeService}
 * (Chunk 2 Task 24), composing the SAME underlying root
 * {@see \App\Support\Widget\LegacyWidgetRenderer} directly rather than
 * routing through this class -- LegacyWidgetRenderAdapter's own docblock
 * freezes it as Inline-only and forbids it from gaining a Site Layout
 * path, so this class's single delegation call stays unconditional and
 * unchanged.
 *
 * Task 24 adds ONLY safe telemetry around the existing delegation (DoD
 * 1279: "render... telemetry into the real... render service") -- never
 * Input Values, never rendered HTML; only project/widget-theme identity
 * and the outcome. DELIBERATELY constructible with a nullable,
 * self-defaulting telemetry argument -- this task's allowed_paths does not
 * include WidgetServiceProvider.php, so the EXISTING
 * `$this->app->singleton(WidgetRenderer::class, WidgetRenderService::class)`
 * class-string binding (reflection-resolved, zero closure) keeps working
 * unmodified while still receiving a real WidgetRuntimeTelemetry instance.
 */
final class WidgetRenderService implements WidgetRenderer
{
    private readonly WidgetRuntimeTelemetry $telemetry;

    public function __construct(
        private readonly LegacyWidgetRenderAdapter $legacyAdapter,
        ?WidgetRuntimeTelemetry $telemetry = null,
    ) {
        $this->telemetry = $telemetry ?? new WidgetRuntimeTelemetry();
    }

    public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
    {
        $result = $this->legacyAdapter->render($spec, $context);

        $this->telemetry->recordRender(
            $context->projectName,
            sprintf('legacy:%d:%d', $spec->widgetId, $spec->themeId),
            $result->ok,
            $result->errorCode,
            1,
        );

        return $result;
    }
}
