<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use Cms\Core\Interfaces\MenuItemInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * `menu.by_group@1` compatibility provider (architecture.md "Data Provider
 * Registry": "`menu.by_group@1` reuses the existing `MenuItemInterface`/
 * repository contract and its public cache."). This adapter is a thin
 * translation layer only: it calls through to the Core-owned
 * {@see MenuItemInterface} binding (`Cms\Core\Repositories\MenuItemRepository`
 * in production, which itself delegates to
 * `Cms\Core\Services\Cache\CorePublicMenuCacheService`'s fingerprint-keyed
 * cache) and never re-implements caching or menu-tree assembly (Task 11
 * non-goal: "NO menu cache changes").
 */
final class MenuByGroupDataProvider implements WidgetDataProvider
{
    public function __construct(
        private readonly MenuItemInterface $menuItems,
    ) {
    }

    public function key(): string
    {
        return 'menu.by_group';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult
    {
        $group = $arguments['group'] ?? null;
        if (!is_string($group) || $group === '') {
            return new WidgetProviderResult(items: [], ok: false, errorCode: WidgetDataProviderRegistry::ERROR_INVALID);
        }

        $freshCache = (bool) ($arguments['fresh_cache'] ?? false);

        try {
            $result = $this->menuItems->getMenuItemListByMenuGroup($group, $freshCache);

            return new WidgetProviderResult(items: $this->normalizeItems($result), ok: true);
        } catch (Throwable $e) {
            // Report before degrading: a production DB outage must never
            // become an invisible empty widget.
            report($e);

            return new WidgetProviderResult(items: [], ok: false, errorCode: WidgetDataProviderRegistry::ERROR_UNAVAILABLE);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(mixed $result): array
    {
        if ($result instanceof Collection) {
            $result = $result->all();
        }

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): array => match (true) {
                $item instanceof Model => $item->toArray(),
                is_array($item) => $item,
                default => (array) $item,
            },
            $result
        ));
    }
}
