<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Services\WidgetQueryService;
use Throwable;

/**
 * Compatibility adapter for the three legacy `product.*` query names
 * hardcoded in `App\Services\WidgetQueryService::getQueryData()`
 * (`:302-372`; docs/architecture/adr-widget-provider-lifecycle.md decision
 * 9): `product.latest`, `product.featured`, `product.hot`. One instance per
 * legacy name, all registered at version 1 -- the registered `{key,
 * version}` identity itself IS the compatibility "alias"; there is no
 * separate alias-mapping table (evidence: WidgetQueryService has no alias
 * mechanism today, only these three literal match arms).
 *
 * Composes `WidgetQueryService::getQueryData()` directly -- the SAME
 * Eloquent query the legacy `{query:product.latest ...}` widget-template
 * syntax still runs -- rather than re-implementing the Product query
 * (frozen contract: "do not fork business logic"). Any Throwable escaping
 * that call is caught and reported as
 * {@see WidgetDataProviderRegistry::ERROR_UNAVAILABLE}, never left to
 * propagate -- this is the adapter's real outage seam.
 *
 * NOTE (characterization, not a fix in scope here): `product.hot`'s
 * `where('is_hot', true)` references a column that does not exist on
 * `products` (verified against every cms/Product migration and against
 * `Schema::getColumnListing('products')` in the sqlite test database). On a
 * strict grammar (e.g. production MySQL, which quotes identifiers with
 * backticks) this throws "Unknown column 'is_hot'", which the try/catch
 * above converts to `ERROR_UNAVAILABLE` -- the exact path
 * WidgetDataProviderRegistryTest's fake-throwing-provider test proves
 * generically. Under sqlite specifically (this codebase's test database),
 * SQLite's documented double-quoted-identifier-falls-back-to-string-literal
 * behavior means `WHERE "is_hot" = ?` silently compares the STRING
 * `'is_hot'` to the bound value instead of erroring, so the query succeeds
 * with zero rows rather than throwing. Both are safe: this adapter never
 * surfaces a raw exception either way. Repairing the missing column is an
 * unrelated pre-existing legacy gap, out of scope for this task.
 */
final class LegacyProductDataProvider implements WidgetDataProvider
{
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly string $queryName,
    ) {
    }

    public function key(): string
    {
        return $this->queryName;
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
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            return new WidgetProviderResult(items: [], ok: false, errorCode: WidgetDataProviderRegistry::ERROR_INVALID);
        }

        try {
            $items = WidgetQueryService::getQueryData($this->queryName, $limit)
                ->map(static fn ($model): array => $model->toArray())
                ->values()
                ->all();

            return new WidgetProviderResult(items: $items, ok: true);
        } catch (Throwable $e) {
            // Report before degrading: a production DB outage must never
            // become an invisible empty widget.
            report($e);

            return new WidgetProviderResult(items: [], ok: false, errorCode: WidgetDataProviderRegistry::ERROR_UNAVAILABLE);
        }
    }
}
