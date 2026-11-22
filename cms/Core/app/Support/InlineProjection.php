<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;

/**
 * Idempotence check for the scopes that select an accessor's answer inline.
 *
 * `withSlug()` and `withChildrenExists()` add a correlated subselect under a
 * fixed alias. A model that registers them through a global scope AND is also
 * queried by a caller that asks for them explicitly would otherwise carry the
 * same subquery twice — valid SQL, wasted work. Both paths must keep working on
 * their own, so neither can simply be deleted; they check here instead.
 *
 * Lives in its own class rather than a shared trait method because a model can
 * use several of these traits at once, and two traits declaring the same method
 * is a fatal conflict.
 *
 * @package Cms\Core\Support
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
final class InlineProjection
{
    /**
     * Has this alias already been added to the query's select list?
     *
     * @param  Builder $query
     * @param  string  $alias
     * @return bool
     */
    public static function selected(Builder $query, string $alias): bool
    {
        foreach ((array) $query->getQuery()->columns as $column) {
            if (! $column instanceof Expression) {
                continue;
            }

            // The grammar decides the quoting, so match the bare alias rather
            // than guessing backticks or double quotes.
            if (str_contains((string) $column->getValue($query->getQuery()->getGrammar()), $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this relation already queued for eager loading on the query?
     *
     * @param  Builder $query
     * @param  string  $relation
     * @return bool
     */
    public static function eagerLoaded(Builder $query, string $relation): bool
    {
        return array_key_exists($relation, $query->getEagerLoads());
    }
}
