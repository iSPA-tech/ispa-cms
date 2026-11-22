<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Models;

use App\Models\Seo;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Abstract base model class for common relationships and sync methods.
 *
 * This class should not be instantiated directly. Extend it in your models
 * to inherit common functionality such as image, attachment, SEO, and slug handling.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Seo[] $seos  SEO entries for all languages
 * @property-read \App\Models\Seo $seo  SEO entry for the current language
 *
 * @package Cms\Core\Models
 * @author malayvuong
 * @since 6.62.6 - 2023-04-04
 */
abstract class AbstractBaseModel extends Model
{
    use HasUuid;
    /**
     * Sync SEO to relationship
     *
     * @param array $data
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2023-05-21, 11:02 ICT
     */
    public function syncSeo(array $data = []): void
    {
        if (empty($data) || empty($this->id)) {
            return;
        }

        foreach ($data as $item) {
            if (!$this->isValidSeoData($item)) {
                continue;
            }

            $unique = !empty($item['id'])
                ? ['id' => (int)$item['id']]
                : ['language_id' => $item['language_id'] ?? null];

            $payload = [
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'hashtag' => $item['hashtag'] ?? '',
                'language_id' => $item['language_id'] ?? null,
            ];

            $this->seos()->updateOrCreate($unique, $payload);
        }
    }

    /**
     * SEOs relationship
     *
     * @return MorphMany
     * @author malayvuong
     * @since 1.0.0
     */
    public function seos(): MorphMany
    {
        return $this->morphMany(Seo::class, 'target');
    }

    /**
     * SEO relationship by language
     *
     * @return MorphOne
     * @author malayvuong
     * @since 6.62.6 - 2023-08-22, 20:24 ICT
     */
    public function seo(): MorphOne
    {
        return $this->morphOne(Seo::class, 'target')
            ->where('language_id', get_lang_id()); // Simple approach, no need for `lang('id', ...)`
    }

    /**
     * Get Hashtag
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2024-07-05, 08:38 ICT
     */
    public function getHashtags(): array
    {
        $langId = get_lang_id();
        $hashtag = iSeo('hashtag');

        // Try to get SEO data
        if ($this->relationLoaded('seos')) {
            $seo = $this->seos->firstWhere('language_id', $langId);
        } else {
            $seo = $this->seos()->where('language_id', $langId)->first();
        }

        if ($seo && !empty($seo->hashtag)) {
            $hashtag = $seo->hashtag;
        }

        if (empty($hashtag)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', is_array($hashtag) ? $hashtag : explode(',', (string)$hashtag)),
            static fn($s) => $s !== ''
        ));
    }

    /**
     * Validate SEO data array
     */
    protected function isValidSeoData(array $seo): bool
    {
        return !empty($seo) && isset($seo['language_id']);
    }
}
