<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Contracts\Widget\WidgetDataProvider;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;

/**
 * Single harmless no-op bound as the default for every root registrar
 * contract (Slot, Template Context, Target Context, Data Provider).
 * Project/module declarations that resolve these contracts while
 * cms/Widget is disabled land here safely and do nothing; cms/Widget
 * rebinds each contract to its real registry independently in boot().
 */
final class NullWidgetExtensionRegistrar implements
    WidgetSlotRegistrar,
    WidgetTemplateContextRegistrar,
    WidgetTargetContextRegistrar,
    WidgetDataProviderRegistrar
{
    public function registerSlot(WidgetSlotDefinition $definition): void
    {
        // No-op: cms/Widget is disabled (or not yet rebound).
    }

    public function registerTemplateContext(string $templateKey, array $slotKeys): void
    {
        // No-op: cms/Widget is disabled (or not yet rebound).
    }

    public function registerTargetContext(WidgetTargetContextDefinition $definition): void
    {
        // No-op: cms/Widget is disabled (or not yet rebound).
    }

    public function registerProvider(WidgetDataProvider $provider): void
    {
        // No-op: cms/Widget is disabled (or not yet rebound).
    }

    public function providers(): array
    {
        return [];
    }
}
