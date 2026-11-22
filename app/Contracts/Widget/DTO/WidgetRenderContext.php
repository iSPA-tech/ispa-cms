<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Request/environment context shared by every WidgetRenderer /
 * WidgetSlotRuntime call: the active project, whether preview CSS/dev
 * assets should be injected, and whether this is a preview or public
 * render. Root DTO — primitives only, no cms/Widget module types.
 */
final readonly class WidgetRenderContext
{
    public function __construct(
        public string $projectName,
        public bool $useCss = false,
        public bool $isPreview = false,
        public bool $editMarkers = false,
    ) {
    }
}
