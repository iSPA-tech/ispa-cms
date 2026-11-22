<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Models;

use App\Models\Slug;
use App\Services\SlugService;
use Cms\Core\Support\InlineProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Trait GetSlugTrait
 *
 * Provides slug management for Eloquent models, including relationships and helpers for syncing,
 * retrieving, and generating slugs. Intended for use in models that require SEO-friendly URLs or
 * language-specific slugs. Integrates with the SlugService and supports multi-language setups.
 *
 * Usage:
 *   - Add this trait to your Eloquent model to enable slug relationships and helpers.
 *   - Ensure your model implements or provides a getCurrentLanguageId() method if language context is needed.
 *
 * @see \App\Models\Slug
 * @see \App\Services\SlugService
 * @package Cms\Core\Traits\Models
 * @author  malayvuong
 * @since   6.19.6 - 2022-05-01
 */
trait GetSlugTrait
{
    /**
     * Projection alias filled by scopeWithSlug(); never part of a payload.
     *
     * Public because ResolvesAppendedRelations reads it off the model to tell
     * whether the caller already selected the slug inline.
     */
    public const SLUG_PROJECTION_ALIAS = 'slug_value';

    /**
     * Keep both ways of resolving the slug out of the payload.
     *
     * `slug_value` is an internal column alias, and `latestSlug` is the relation
     * the accessor reads — neither is model data. Without this, a listing that
     * opts into withSlug(), or one that eager loads the relation, would start
     * shipping an extra key in toArray()/toJson() that no consumer asked for.
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-02)
     */
    public function initializeGetSlugTrait(): void
    {
        $this->mergeHidden([self::SLUG_PROJECTION_ALIAS, 'latestSlug']);
    }

    /**
     * Select the slug inline so a listing resolves it in its own query instead
     * of one lazy `latestSlug` load per row.
     *
     * A correlated subquery rather than a join on purpose: a target may own
     * several slug rows (renames keep their history), and a join multiplies the
     * parent rows when it does.
     *
     * No select() call here — Query\Builder::addSelect already establishes
     * "{table}.*" when the builder has no projection yet, and forcing one would
     * overwrite a projection the caller chose.
     *
     * The subquery must stay an exact mirror of latestSlug(), which is a
     * MorphOne pinned to getMorphClass(). Widening it here — to also match a
     * subclass name that syncSlug() may have stamped — makes the projected
     * answer differ from the lazy one, and the accessor would report a
     * different slug depending on how the row was loaded.
     *
     * @param  Builder $query
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-02)
     */
    public function scopeWithSlug(Builder $query): Builder
    {
        // A model can pick this up from ResolvesAppendedRelations and still be
        // queried by a caller that asks for it explicitly; adding the subquery
        // twice is valid SQL but duplicated work.
        if (InlineProjection::selected($query, self::SLUG_PROJECTION_ALIAS)) {
            return $query;
        }

        return $query->addSelect([self::SLUG_PROJECTION_ALIAS => Slug::query()
            ->select('slug')
            ->whereColumn('target_id', $this->getTable() . '.id')
            ->where('target_type', $this->getMorphClass())
            ->latest('id')
            ->limit(1)]);
    }

    /**
     * The category stored on this model's slug rows: the kind of record the slug points to (`gallery`, `category`,
     * `product`, ...). Resolvers look a slug up by this category, so two kinds of record may share a slug under
     * different URLs. A model whose slugs live under several URLs overrides it (a post returns its type).
     *
     * @param int|string|null $targetId
     *
     * @return string|null
     */
    public static function slugCategoryFor(int|string|null $targetId): ?string
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * Get all slugs associated with this model (polymorphic relationship).
     *
     * @return MorphMany
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function slugs(): MorphMany
    {
        // Returns all related slugs for this model instance
        return $this->morphMany(Slug::class, 'target');
    }

    /**
     * Sync (create or update) a slug for this model based on provided data.
     *
     * @param  array $data  Must contain at least 'slug'. Optionally 'id', 'language_id', 'target_id'. The category comes
     *                      from the model (slugCategoryFor), not from the caller.
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function syncSlug(array $data = []): void
    {
        // If no slug provided, do nothing
        if (empty($data) || empty($data['slug'])) {
            return;
        }

        $languageId = $data['language_id'] ?? null;
        $existingSlug = null;

        // Try to find an existing slug by ID if provided
        if (!empty($data['id'])) {
            $existingSlug = $this->slugs()->where('id', $data['id'])->first();
        }

        // Otherwise, try to find by language
        if (!$existingSlug && !empty($languageId)) {
            $existingSlug = $this->slugs()->where('language_id', $languageId)->first();
        }

        // Fallback: get the first slug
        if (!$existingSlug) {
            $existingSlug = $this->slugs()->first();
        }

        $languageId = $languageId ?? $existingSlug?->language_id;
        $targetId = $data['target_id'] ?? $existingSlug?->target_id ?? $this->getKey();

        // Generate or update the slug using the SlugService
        $generatedSlug = app(SlugService::class)->create([
            'slug' => trim((string)$data['slug']),
            'id' => $existingSlug?->id,
            'target_id' => $targetId,
            'target_type' => get_class($this),
            'language_id' => $languageId,
        ]);

        if (empty($generatedSlug)) {
            return;
        }

        // Determine unique key for updateOrCreate
        $unique = $existingSlug?->id
            ? ['id' => $existingSlug->id]
            : ['language_id' => $languageId];

        $payload = [
            'slug' => $generatedSlug,
            'category' => static::slugCategoryFor($targetId),
            'language_id' => $languageId ?? $existingSlug?->language_id,
        ];

        // Create or update the slug record
        $this->slugs()->updateOrCreate($unique, $payload);
    }

    /**
     * Accessor for the 'slug' attribute.
     *
     * Returns the latest slug string, or an empty string if none exists.
     * Setting this attribute will create a new slug record with the current language context.
     *
     * @return Attribute
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function slug(): Attribute
    {
        return new Attribute(
        // Get the latest slug string
            get: function () {
                // A listing that selected the slug inline already carries it;
                // only fall back to a relation load when it did not.
                if (array_key_exists(self::SLUG_PROJECTION_ALIAS, $this->attributes)) {
                    return (string) ($this->attributes[self::SLUG_PROJECTION_ALIAS] ?? '');
                }

                return $this->latestSlug?->slug ?? '';
            },
            // Set a new slug (creates a new record)
            set: fn($value) => $this->slugs()->create([
                'slug' => $value,
                'language_id' => $this->getCurrentLanguageId(), // Add language context if needed
            ])
        );
    }

    /**
     * Get the latest slug associated with this model (polymorphic one-to-one).
     *
     * @return MorphOne
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function latestSlug(): MorphOne
    {
        // Returns the most recently created slug for this model
        return $this->morphOne(Slug::class, 'target')->latestOfMany();
    }

    /**
     * Get the current language ID for the model context.
     *
     * Override this method in your model if you have a custom way to determine language.
     *
     * @return int|null
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function getCurrentLanguageId(): ?int
    {
        // If the model implements its own getCurrentLanguageId, use it
        if (method_exists($this, 'getCurrentLanguageId')) {
            return $this->getCurrentLanguageId();
        }

        // Fallback to a global helper if available
        return function_exists('get_lang_id') ? get_lang_id() : null;
    }
}
