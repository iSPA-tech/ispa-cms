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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;

/**
 * Trait HasChildrenTrait
 *
 * Adds a computed attribute to determine if a model has children, either by loaded relation or by existence in the
 * database. Intended for use in hierarchical or tree-structured Eloquent models.
 *
 * Usage:
 *   - Add this trait to your Eloquent model.
 *   - Ensure your model defines a children() relationship.
 *
 * @package Cms\Core\Traits\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait HasChildrenTrait
{
    /** Projection alias filled by scopeWithChildrenExists(); never payload data. */
    private const CHILDREN_EXISTS_ALIAS = 'children_exists';

    /** Table alias for the correlated probe, so it cannot shadow the outer table. */
    private const CHILDREN_EXISTS_PROBE = 'children_probe';

    /**
     * `children_exists` is an internal attribute, so hide it everywhere this
     * trait is used — otherwise a listing that opts into the scope would start
     * shipping it in toArray()/toJson() alongside the real `has_children`.
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-03)
     */
    public function initializeHasChildrenTrait(): void
    {
        $this->mergeHidden([self::CHILDREN_EXISTS_ALIAS]);
    }

    /**
     * Resolve `has_children` inline instead of one EXISTS query per row.
     *
     * The accessor otherwise runs `children()->exists()` while the payload is
     * being serialized: ~105 queries on a flat category listing, and one per
     * post on a listing that carries a category per post.
     *
     * Opt-in rather than part of ResolvesAppendedRelations, and that is not an
     * oversight. The scope adds a select column, and a global scope also
     * reaches builders handed to `whereIn()` as a subquery, where a second
     * column turns a scalar subquery into an error. The batched alternative —
     * an afterQuery hook — cannot be registered from a global scope either:
     * Builder::get() applies scopes to a clone but runs the after-query
     * callbacks on the original builder, so a callback added by a scope is
     * silently dropped. Callers that serialize a listing therefore ask for this
     * by name.
     *
     * Built from the query builder rather than withExists(): `children` points
     * back at this same model, so withExists() would re-enter any scope that
     * installed this projection and recurse.
     *
     * @param  Builder $query
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-03)
     */
    public function scopeWithChildrenExists(Builder $query): Builder
    {
        if (InlineProjection::selected($query, self::CHILDREN_EXISTS_ALIAS)) {
            return $query;
        }

        $relation = $this->children();

        $probe = DB::table($relation->getRelated()->getTable() . ' as ' . self::CHILDREN_EXISTS_PROBE)
            ->selectRaw('1')
            ->whereColumn(
                self::CHILDREN_EXISTS_PROBE . '.' . $relation->getForeignKeyName(),
                $this->getTable() . '.' . $this->getKeyName()
            )
            ->limit(1);

        // Mirror the soft-delete filter the relation would have applied.
        if (method_exists($this, 'getDeletedAtColumn')) {
            $probe->whereNull(self::CHILDREN_EXISTS_PROBE . '.' . $this->getDeletedAtColumn());
        }

        return $query->addSelect([self::CHILDREN_EXISTS_ALIAS => $probe]);
    }

    /**
     * Attribute accessor to check if the model has children.
     *
     * Returns true if the children relation is loaded and not empty, or if children exist in the database.
     *
     * @return Attribute
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function hasChildren(): Attribute
    {
        // Cached on the instance: a belongsTo hands the same category object to
        // every row pointing at it, so without this the fallback below runs once
        // per row even though there is only one model.
        return (new Attribute(
            get: function () {
                // A query that resolved the answer up front already carries it
                if (array_key_exists(self::CHILDREN_EXISTS_ALIAS, $this->attributes)) {
                    return (bool) $this->attributes[self::CHILDREN_EXISTS_ALIAS];
                }
                // If the children relation is already loaded, check if it's not empty
                if ($this->relationLoaded('children')) {
                    return $this->children->isNotEmpty();
                }
                // Otherwise, check if any children exist in the database
                return $this->children()->exists();
            }
        ))->shouldCache();
    }
}
