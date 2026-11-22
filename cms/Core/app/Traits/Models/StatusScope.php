<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Models;

use App\Enums\StatusEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait providing reusable Eloquent query scopes for status filtering.
 *
 * Adds named scopes for common status values (published, draft, pending,
 * archived) as well as a generic whereStatus scope that accepts a single
 * StatusEnum, a raw string, or an array of either. Models using this trait
 * must have a `status` column.
 *
 * @see \App\Enums\StatusEnum
 * @package Cms\Core\Traits\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait StatusScope
{
    public function getStatusAttribute(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    public function setStatusAttribute(mixed $value): void
    {
        $this->attributes['status'] = is_string($value) ? trim($value) : $value;
    }

    /**
     * Scope the query to rows matching one or more status values.
     *
     * Accepts a single StatusEnum, a plain string, an array of either,
     * or null (which returns the query unchanged).
     *
     * @param  Builder                          $query   Eloquent query builder
     * @param  StatusEnum|string|array|null      $status  Status value(s) to filter by
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function scopeWhereStatus(Builder $query, StatusEnum|string|array|null $status): Builder
    {
        if ($status === null || $status === '' || $status === []) {
            return $query;
        }

        if (is_array($status)) {
            $values = array_map(
                fn ($s) => $s instanceof StatusEnum ? $s->value : $s,
                $status,
            );

            return $query->whereIn('status', $values);
        }

        return $query->where('status', $status instanceof StatusEnum ? $status->value : $status);
    }

    /**
     * Scope the query to only published records.
     *
     * @param  Builder $query  Eloquent query builder
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereStatus(StatusEnum::PUBLISHED);
    }

    /**
     * Scope the query to only draft records.
     *
     * @param  Builder $query  Eloquent query builder
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->whereStatus(StatusEnum::DRAFT);
    }

    /**
     * Scope the query to only pending records.
     *
     * @param  Builder $query  Eloquent query builder
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereStatus(StatusEnum::PENDING);
    }

    /**
     * Scope the query to only archived records.
     *
     * @param  Builder $query  Eloquent query builder
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereStatus(StatusEnum::ARCHIVED);
    }
}
