<?php

declare(strict_types=1);

namespace Cms\Core\Services\Widget\Hub;

use App\Contracts\Widget\WidgetDataProviderRegistrar;
use App\Services\WidgetQueryService;

/**
 * The data resolvers this site can serve: Core's `{query:type.name}` types (always, so the check works with the Widget
 * module off) plus every provider registered through the root {@see WidgetDataProviderRegistrar} (empty while the
 * Widget module is off).
 */
final class WidgetResolverInventory
{
    /**
     * @return array<string, int> resolver key => highest version served, sorted by key
     */
    public function available(): array
    {
        $available = [];
        foreach (array_keys(WidgetQueryService::QUERY_TYPES) as $type) {
            $available['query:' . $type] = 1;
        }
        foreach (app(WidgetDataProviderRegistrar::class)->providers() as $provider) {
            $key = 'provider:' . $provider->key();
            $available[$key] = max($available[$key] ?? 0, $provider->version());
        }
        ksort($available);

        return $available;
    }

    /**
     * What a bundle's `requires` asks for that this site cannot serve: each resolver missing or older than required,
     * and `renderer` when the bundle needs a newer renderer than {@see WidgetBundle::WIDGET_RENDERER_VERSION}.
     *
     * @param array{renderer?: int, resolvers?: array<string, int>} $requires
     * @return array<string, int> requirement => required version; empty when the site can serve the bundle
     */
    public function missing(array $requires): array
    {
        $missing = [];
        $renderer = (int) ($requires['renderer'] ?? 1);
        if ($renderer > WidgetBundle::WIDGET_RENDERER_VERSION) {
            $missing['renderer'] = $renderer;
        }

        $available = $this->available();
        foreach ($requires['resolvers'] ?? [] as $key => $version) {
            if (($available[$key] ?? 0) < (int) $version) {
                $missing[$key] = (int) $version;
            }
        }

        return $missing;
    }
}
