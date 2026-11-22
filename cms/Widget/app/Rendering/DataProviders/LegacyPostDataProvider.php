<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Services\WidgetQueryService;
use Throwable;

/**
 * Compatibility adapter for the single legacy `post.latest` query name
 * hardcoded in `App\Services\WidgetQueryService::getQueryData()`
 * (`:346-368`; docs/architecture/adr-widget-provider-lifecycle.md decision
 * 9), registered at version 1.
 *
 * Composes `WidgetQueryService::getQueryData('post.latest', $limit)`
 * directly -- the SAME Eloquent query (published `Cms\Content\Models\Post`
 * rows of `type='post'`, current language, with a resolvable category and
 * slug) the legacy `{query:post.latest ...}` widget-template syntax still
 * runs -- rather than re-implementing it.
 */
final class LegacyPostDataProvider implements WidgetDataProvider
{
    private const QUERY_NAME = 'post.latest';
    private const DEFAULT_LIMIT = 10;

    public function key(): string
    {
        return self::QUERY_NAME;
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
            $items = WidgetQueryService::getQueryData(self::QUERY_NAME, $limit)
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
