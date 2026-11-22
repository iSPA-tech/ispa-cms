<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;

/**
 * Root registrar contract for code-declared Slots. Bound by default to
 * App\Support\Widget\NullWidgetExtensionRegistrar (a harmless no-op), so
 * project/module Blade providers can always safely declare Slots even when
 * cms/Widget is disabled. The cms/Widget module rebinds this to
 * WidgetSlotRegistry in boot(). External/project declarations must resolve this contract from an
 * application-booted callback, which runs after the module's boot()-time
 * rebind.
 */
interface WidgetSlotRegistrar
{
    public function registerSlot(WidgetSlotDefinition $definition): void;
}
