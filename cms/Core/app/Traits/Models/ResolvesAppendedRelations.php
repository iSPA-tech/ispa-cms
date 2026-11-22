<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Models;

use Cms\Core\Support\InlineProjection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolve a model's query-backed appends up front instead of once per row.
 *
 * `slug`, `image` and `logo` are accessors that fall back to their own query
 * when the relation behind them is not loaded. All three sit in `$appends`, so
 * they fire during serialization — far away from whoever built the query — and
 * any call site that forgets silently reintroduces an N+1.
 * Measured on a public API before this existed: 200 upload lookups on a
 * single 100-post listing, 12 slug lookups on a 12-post one, and ~5,100 queries
 * on a vehicle segment page.
 *
 * **Eager loads only.** A global scope applies to every use of the builder,
 * including one handed to `whereIn()` as a subquery:
 *
 *     ->whereIn('product_id', Product::query()->select('id'))
 *
 * A scope that called `withSlug()` there would turn that one-column subquery
 * into two and the database would reject it outright ("sub-select returns 2
 * columns"). Aggregates and relation-existence subqueries have the same shape
 * problem. Eager loads carry none of it: `toBase()` drops them, so a subquery,
 * a `count()` and a `whereHas()` are all unaffected, and the cost is one query
 * per query rather than one per row.
 *
 * afterQuery hooks are ruled out for a second reason: Builder::get() applies
 * scopes to a clone but runs the after-query callbacks on the original builder,
 * so a callback registered from here is silently dropped. That is why
 * `has_children` is not handled at all — see
 * HasChildrenTrait::scopeWithChildrenExists(), which callers opt into by name.
 *
 * `withSlug()` remains as the sharper, opt-in alternative: it selects the slug
 * inline and saves the round trip entirely, but only a listing that knows it is
 * the outermost query may use it. When a caller has already done so, the scope
 * leaves the relation alone.
 *
 * Each step runs only when the model actually appends the attribute it feeds,
 * so a model that appends `image` without HasMediaTrait, or uses GetSlugTrait
 * without appending `slug`, is left alone.
 *
 * Opt out for a single query with:
 *   Model::query()->withoutGlobalScope(Model::APPENDED_RELATIONS_SCOPE)
 *
 * (through the using class — PHP forbids reading a trait constant directly)
 *
 * @see \Cms\Core\Traits\Models\GetSlugTrait::scopeWithSlug()
 * @see \Cms\Core\Traits\Models\HasMediaTrait::scopeWithImage()
 * @see \Cms\Core\Traits\Models\HasChildrenTrait::scopeWithChildrenExists()
 *
 * @package Cms\Core\Traits\Models
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
trait ResolvesAppendedRelations
{
    /** Name to pass to withoutGlobalScope(). */
    public const APPENDED_RELATIONS_SCOPE = 'core.appended-relations';

    /**
     * Register the scope once per concrete model class.
     *
     * @return void
     */
    protected static function bootResolvesAppendedRelations(): void
    {
        static::addGlobalScope(
            self::APPENDED_RELATIONS_SCOPE,
            static function (Builder $query): void {
                $model = $query->getModel();

                // Skip when the caller already selected the slug inline: the
                // projection answers the accessor on its own, and loading the
                // relation as well would be a wasted round trip.
                if ($model->hasAppended('slug')
                    && method_exists($model, 'latestSlug')
                    && ! InlineProjection::selected($query, $model::SLUG_PROJECTION_ALIAS)) {
                    $query->with('latestSlug');
                }

                // with('image') rather than withImage(): the scope's extra job
                // is an afterQuery hook that warms the R2 ledger, and Builder::get()
                // applies scopes to a clone while running after-query callbacks on
                // the original builder, so a hook added here would never fire.
                // Listings that serialize thumbnails call withImage() themselves.
                if ($model->hasAppended('image') && method_exists($model, 'image')) {
                    $query->with('image');
                }

                // `logo` has no scope of its own; the accessor falls back to
                // logo()->first(), so eager loading the relation is the fix.
                if ($model->hasAppended('logo') && method_exists($model, 'logo')) {
                    $query->with('logo');
                }
            }
        );
    }
}
