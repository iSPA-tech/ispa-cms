<?php

declare(strict_types=1);

namespace App\Contracts\Widget\DTO;

/**
 * Outcome of a WidgetRenderer/WidgetSlotRuntime render call. `ok = false`
 * signals "nothing to render" (module disabled, no runtime bound, invalid
 * Widget/Theme pairing) without ever leaking exception detail — callers
 * (e.g. the root <x-widget-slot> component) fall back to their own supplied
 * fallback content or empty output.
 */
final readonly class WidgetRenderResult
{
    public function __construct(
        public string $html = '',
        public bool $ok = true,
        public ?string $errorCode = null,
    ) {
    }
}
