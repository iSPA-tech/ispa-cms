<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Core\Models\Field;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Interfaces\FieldInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for form field persistence, queries, and cached form-field lookups.
 *
 * Implements {@see FieldInterface} for {@see Field} persistence.
 * Provides cached field retrieval by form ID with eager-loaded items.
 *
 * @see \Cms\Core\Models\Field
 * @see \Cms\Core\Interfaces\FieldInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class FieldRepository extends RepositoriesAbstract implements FieldInterface
{
    /**
     * Initialize the repository with the Field model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(Field::class);
    }

    /**
     * Get field by form_id
     *
     * @param string|null $formId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-29, 21:53 ICT
     */
    public function getFieldByFormId(string $formId = null, bool $freshCache = false): mixed
    {
        $fieldCacheKey = sprintf(Constant::CACHE_FIELDS[Constant::FIELD_DETAIL_BY_ID], $formId);
        $ttl = $freshCache ? 1 : config('setting.cache.cache.form', 3600 * 24);

        return cache()->remember($fieldCacheKey, $ttl, function () use ($fieldCacheKey, $formId, $ttl) {
            CacheHelper::getInstance()->store($fieldCacheKey, 'Form field ' . $formId, $ttl);

            return $this->model()
                ->where('form_id', $formId)
                ->whereIn('status', $this->publishedStatusValues())
                ->with([
                    'items' => function ($query): void {
                        $query->where('status', true)->orderBy('priority', 'asc');
                    },
                ])
                ->first();
        });
    }

    /**
     * Return accepted published values for legacy and enum-backed field rows.
     *
     * Older deployments store `published` in the fields table while newer enum
     * seeders use `status_published`. Public comment/contact forms must read
     * both values until legacy rows are migrated.
     *
     * @return array<int, string>
     */
    protected function publishedStatusValues(): array
    {
        return array_values(array_unique([
            StatusEnum::PUBLISHED->value,
            'published',
        ]));
    }
}
