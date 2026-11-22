<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

/**
 * Root registrar contract for Template Context declarations (which Slot
 * keys a code-registered template key exposes). Bound by default to
 * App\Support\Widget\NullWidgetExtensionRegistrar (harmless no-op).
 * The cms/Widget module rebinds this to WidgetTemplateContextRegistry in boot().
 */
interface WidgetTemplateContextRegistrar
{
    /**
     * @param list<string> $slotKeys
     */
    public function registerTemplateContext(string $templateKey, array $slotKeys): void;
}
