<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Services\WidgetQueryService;
use Throwable;

/**
 * Compatibility adapter for the two legacy `banner.*` query names hardcoded
 * in `App\Services\WidgetQueryService::getQueryData()` (`:369-370`;
 * docs/architecture/adr-widget-provider-lifecycle.md decision 9):
 * `banner.main_slide`, `banner.popup_ads`. One instance per legacy name,
 * both registered at version 1.
 *
 * Composes `WidgetQueryService::getQueryData()` directly, never
 * re-implementing the Banner query. NOTE (characterization, not a fix in
 * scope here): `banners.type` is a DB enum
 * (`database/migrations/2022_02_13_084852_create_banners_table.php`)
 * allowing `slider_main|slider_secondary|target|popup|970_90|728_90|336_280|300_600|320_50`
 * -- neither `main_slide` nor `popup_ads` is a member, so
 * `Banner::query()->where('type', 'main_slide')` structurally can never
 * match a row today. This adapter faithfully reproduces that (pre-existing,
 * out-of-scope) legacy behavior rather than silently correcting it.
 */
final class LegacyBannerDataProvider implements WidgetDataProvider
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
