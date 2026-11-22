<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

/**
 * Root registrar contract for WidgetDataProvider declarations. Bound by
 * default to App\Support\Widget\NullWidgetExtensionRegistrar (harmless
 * no-op). The cms/Widget module rebinds this to WidgetDataProviderRegistry in boot().
 */
interface WidgetDataProviderRegistrar
{
    public function registerProvider(WidgetDataProvider $provider): void;

    /**
     * Every registered provider (each key/version pair once); empty while cms/Widget is off.
     *
     * @return list<WidgetDataProvider>
     */
    public function providers(): array;
}
