<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Content\Widget;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Contracts\Widget\DTO\WidgetTargetPage;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\DTO\WidgetTargetSummary;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTargetContextResolver;
use App\Enums\StatusEnum;
use App\Support\Widget\StandardWidgetSiteAreas;
use Cms\Content\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Shared "page" Target Context of the Widget Site Layout ("Riêng trang này"): a
 * Content Post with `type = 'page'`, served by the `reader` route
 * (`/about/{slug}`), which announces the same target through
 * {@see \App\Support\Widget\WidgetRenderTarget}.
 *
 * Contract (same as the pilot project's resolver it generalises):
 * - identity is the Post `uuid`, never the numeric id;
 * - unknown uuid, or a Post that is not a page -> 404 (ModelNotFoundException);
 * - an existing page that is not published -> 403 (AuthorizationException),
 *   with no label/route metadata in the message;
 * - search() returns readable (published) pages only.
 *
 * Posts carry no project column: one deployment serves one project, so the
 * authorized project context needs no extra filter here.
 *
 * Implements only ROOT contracts; with cms/Widget off the registrar is the
 * no-op NullWidgetExtensionRegistrar.
 */
final class PageWidgetTargetContextResolver implements WidgetTargetContextResolver
{
    public const TARGET_TYPE = 'page';

    public const ROUTE_NAME = 'reader';

    public const LABEL_KEY = 'widget_site_layout.target_context.page';

    /**
     * Registers this resolver as the "page" Target Context, after the standard
     * areas (it needs `page.static`). Skipped when the project turned the
     * standard areas off, or registers its own "page" resolver and sets
     * `content.widget_page_target` false (the pilot project does: its published
     * release stores its own resolver class in `registry_contracts`).
     */
    public static function registerTargetContext(Container $app): void
    {
        if (config('content.widget_page_target', true) === false || !StandardWidgetSiteAreas::register($app)) {
            return;
        }

        /** @var WidgetTargetContextRegistrar $targetContexts */
        $targetContexts = $app->make(WidgetTargetContextRegistrar::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: self::LABEL_KEY,
            templateContextKey: StandardWidgetSiteAreas::TEMPLATE_PAGE_STATIC,
            resolverService: self::class,
        ));
    }

    public function search(AuthorizedProjectContext $context, WidgetTargetSearchQuery $query): WidgetTargetPage
    {
        $builder = $this->pageQuery()->where('status', StatusEnum::PUBLISHED->value);

        if ($query->keywords !== '') {
            $builder->where('name', 'like', '%' . $query->keywords . '%');
        }

        $total = (clone $builder)->count();

        $items = [];
        foreach ($builder->orderBy('name')->forPage($query->page, $query->perPage)->get() as $post) {
            $items[] = new WidgetTargetSummary(
                targetId: (string) $post->uuid,
                label: (string) $post->name,
                routeName: self::ROUTE_NAME,
                routeParameters: ['slug' => (string) $post->slug],
            );
        }

        return new WidgetTargetPage(items: $items, page: $query->page, perPage: $query->perPage, total: $total);
    }

    public function resolve(AuthorizedProjectContext $context, string $targetId): WidgetResolvedTarget
    {
        $post = $this->pageQuery()->where('uuid', $targetId)->first();

        if ($post === null) {
            throw new ModelNotFoundException(
                "Widget target '{$targetId}' was not found in the authorized project scope."
            );
        }

        if ($post->status !== StatusEnum::PUBLISHED->value) {
            throw new AuthorizationException('Widget target is not readable in the authorized project scope.');
        }

        return new WidgetResolvedTarget(
            targetId: (string) $post->uuid,
            targetType: self::TARGET_TYPE,
            label: (string) $post->name,
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => (string) $post->slug],
        );
    }

    /** Every page with a uuid, whatever its status, so resolve() can tell 403 from 404. */
    private function pageQuery(): Builder
    {
        return Post::query()->where('type', 'page')->whereNotNull('uuid');
    }
}
