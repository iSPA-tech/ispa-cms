<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Models;

use Cms\Core\Models\Upload;
use Cms\Core\Support\InlineProjection;
use Cms\Core\Models\UploadTarget;
use Cms\Core\Services\ImageVariantService;
use Cms\Core\Services\UploadReferenceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Trait HasMediaTrait
 *
 * Provides relationships and helpers for managing media (images, logos, attachments, avatars)
 * associated with Eloquent models. Supports syncing, eager loading, and custom categories.
 *
 * Usage:
 *   - Add this trait to your Eloquent model.
 *   - Ensure your model supports the required relationships and pivot tables.
 *
 * @see \Cms\Core\Models\Upload
 * @see \Cms\Core\Models\UploadTarget
 * @package Cms\Core\Traits\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait HasMediaTrait
{
    /** @var string Category for images */
    public const CATEGORY_IMAGES = 'images';
    /** @var string Category for logo */
    public const CATEGORY_LOGO = 'logo';
    /** @var string Category for attachments */
    public const CATEGORY_ATTACHMENTS = 'attachments';
    /** @var string Category for avatar */
    public const CATEGORY_AVATAR = 'avatar';

    /**
     * Load the main image for a whole listing in two queries instead of one
     * lazy load per row plus a replication probe per thumbnail URL.
     *
     * `image` is an accessor that falls back to `$this->image()->first()` when
     * the relation is not loaded, and serializing the Upload it returns then
     * asks the R2 ledger whether each preset derivative was replicated —
     * measured at 1 + ~3.5 queries per row on a product listing.
     *
     * The ledger warm runs in an afterQuery hook so a caller cannot forget it;
     * ImageVariantService memoizes per request, so a second listing in the same
     * request pays nothing for paths it has already resolved.
     *
     * @param  Builder $query
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-02)
     */
    public function scopeWithImage(Builder $query): Builder
    {
        return $this->scopeWithMediaRelation($query, 'image');
    }

    /**
     * The same treatment for `logo`.
     *
     * `logo` is appended on Brand and carries the same cost as `image`: the
     * accessor falls back to a lazy load, and serializing the Upload probes the
     * R2 ledger once per preset. Measured on production, one brand detail page
     * spent 9 queries on the single brand logo — one per derivative — because
     * the caller eager loaded the relation but nothing warmed the ledger.
     *
     * @param  Builder $query
     * @return Builder
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-08-03)
     */
    public function scopeWithLogo(Builder $query): Builder
    {
        return $this->scopeWithMediaRelation($query, 'logo');
    }

    /**
     * Eager load a single-upload relation and warm the ledger for the batch.
     *
     * @param  Builder $query
     * @param  string  $relation
     * @return Builder
     */
    private function scopeWithMediaRelation(Builder $query, string $relation): Builder
    {
        // Registering the afterQuery hook twice would warm the ledger twice.
        if (InlineProjection::eagerLoaded($query, $relation)) {
            return $query;
        }

        return $query
            ->with($relation)
            ->afterQuery(static function (Collection $results) use ($relation): Collection {
                app(ImageVariantService::class)->warmReplicationLedger(
                    $results->pluck($relation)->filter()
                );

                return $results;
            });
    }

    /**
     * Relationship: Main image by category (single image).
     *
     * @param string $category
     *
     * @return HasOneThrough
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function mainImage(string $category = self::CATEGORY_IMAGES): HasOneThrough
    {
        return $this->image($category)->where(
            $this->getUploadPivotTable() . '.main',
            true
        );
    }

    /**
     * Relationship: Main (default) image by category.
     *
     * @param string $category
     *
     * @return HasOneThrough
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function image(string $category = self::CATEGORY_IMAGES): HasOneThrough
    {
        return $this->singleImageRelation($category);
    }

    /**
     * Build a single-image relation for the provided category.
     *
     * @param string $category
     *
     * @return HasOneThrough
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function singleImageRelation(string $category): HasOneThrough
    {
        $pivotTable = $this->getUploadPivotTable();

        return $this->hasOneThrough(
            Upload::class,
            UploadTarget::class,
            'target_id',
            'id',
            $this->getKeyName(),
            'upload_id'
        )
            ->where($pivotTable . '.target_type', $this->getMorphClass())
            ->where($pivotTable . '.category', $category)
            ->orderByDesc($pivotTable . '.main')
            ->orderBy($pivotTable . '.order')
            ->orderBy($pivotTable . '.upload_id');
    }

    /**
     * Resolve the upload pivot table name.
     *
     * @return string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function getUploadPivotTable(): string
    {
        return (new UploadTarget())->getTable();
    }

    /**
     * Accessor: Get single logo image directly.
     *
     * @return Model|null
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function getLogoAttribute(): ?Model
    {
        if ($this->relationLoaded('logo')) {
            return $this->getRelation('logo');
        }
        return $this->logo()->first();
    }

    /**
     * Relationship: Logo images (for eager loading).
     *
     * @return HasOneThrough
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function logo(): HasOneThrough
    {
        return $this->singleImageRelation(self::CATEGORY_LOGO);
    }

    /**
     * Accessor: Get single image directly.
     *
     * @return Model|null
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function getImageAttribute(): ?Model
    {
        if ($this->relationLoaded('images')) {
            $images = $this->getRelation('images');
            $mainImage = $images->firstWhere('pivot.main', true);
            if ($mainImage !== null) {
                return $mainImage;
            }
            return $images->sortBy(static fn($image) => $image->pivot->order ?? PHP_INT_MAX)->first();
        }
        if ($this->relationLoaded('image')) {
            return $this->getRelation('image');
        }
        return $this->image()->first();
    }

    /**
     * Sync images to relationship for a given category.
     *
     * @param array $data Array of image data arrays or Upload instances.
     * @param string $category
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function syncImages(array $data = [], string $category = self::CATEGORY_IMAGES): void
    {
        if (empty($data)) {
            $this->images($category)->detach();
            return;
        }
        $imageData = [];
        $mainAssigned = false;
        foreach (array_values($data) as $index => $image) {
            $image = $this->normalizeImageData($image);

            if (!$this->isValidImageData($image)) {
                continue;
            }
            $imageId = (int)$image['id'];
            $isMain = $image['main'] ?? ($index === 0 && !$mainAssigned);
            if ($mainAssigned && $isMain) {
                $isMain = false;
            }
            $mainAssigned = $mainAssigned || $isMain;
            $imageData[$imageId] = [
                'target_type' => $this->getMorphClass(),
                'order' => $image['order'] ?? $index,
                'category' => $category,
                'main' => $isMain,
            ];
        }
        if (! (bool) config('ispa-cms.upload.r2_orphan_guard', false) || $imageData === []) {
            $this->images($category)->sync($imageData);
            return;
        }

        DB::transaction(function () use ($category, $imageData): void {
            app(UploadReferenceGuard::class)->lockActive(array_keys($imageData));
            $this->images($category)->sync($imageData);
        });
    }

    /**
     * Relationship: Uploaded images by category.
     *
     * @param string $category
     *
     * @return BelongsToMany
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function images(string $category = self::CATEGORY_IMAGES): BelongsToMany
    {
        return $this->belongsToMany(Upload::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'target_id', 'upload_id')
            ->wherePivot('target_type', $this->getMorphClass())
            ->wherePivot('category', $category)
            ->withPivot(['main', 'order'])
            ->orderByPivot('main', 'desc')
            ->orderByPivot('order');
    }

    /**
     * Validate image data array (must contain a valid numeric 'id').
     *
     * @param array $image
     *
     * @return bool
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function isValidImageData(array $image): bool
    {
        return isset($image['id']) && is_numeric($image['id']) && (int)$image['id'] > 0;
    }

    /**
     * Normalize image data from request arrays or Upload instances.
     *
     * @param mixed $image
     *
     * @return array
     */
    protected function normalizeImageData(mixed $image): array
    {
        if ($image instanceof Upload) {
            return ['id' => $image->getKey()];
        }

        if (is_array($image)) {
            return $image;
        }

        return [];
    }

    /**
     * Sync attachments to relationship for a given category.
     *
     * @param array $data Array of attachment data (must contain 'id').
     * @param string $category
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function syncAttachments(array $data = [], string $category = self::CATEGORY_ATTACHMENTS): void
    {
        if (empty($data)) {
            $this->attachments($category)->detach();
            return;
        }
        $attachmentData = [];
        foreach ($data as $index => $item) {
            $attachmentData[$item['id']] = [
                'target_type' => $this->getMorphClass(),
                'order' => $index,
                'category' => $category,
                'main' => $index === 0,
            ];
        }
        $this->attachments($category)->sync($attachmentData);
    }

    /**
     * Relationship: Attachments files by category.
     *
     * @param string $category
     *
     * @return BelongsToMany
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function attachments(string $category = self::CATEGORY_ATTACHMENTS): BelongsToMany
    {
        return $this->belongsToMany(Upload::class, CoreTableConstant::UPLOAD_TARGET_PIVOT, 'target_id', 'upload_id')
            ->wherePivot('target_type', $this->getMorphClass())
            ->wherePivot('category', $category)
            ->orderByPivot('main', 'desc')
            ->orderByPivot('order');
    }

    /**
     * Sync avatar images to relationship for a given category.
     *
     * @param array $data Array of avatar image data (must contain 'id').
     * @param string $category
     *
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function syncAvatar(array $data = [], string $category = self::CATEGORY_AVATAR): void
    {
        if (!empty($data)) {
            $imageData = [];
            foreach ($data as $index => $image) {
                $imageData[$image['id']] = [
                    'target_type' => $this->getMorphClass(),
                    'order' => $index,
                    'category' => $category,
                    'main' => $index === 0,
                ];
            }
            $this->images($category)->sync($imageData);
        }
    }
}
