<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Seo;
use Cms\Core\Repositories\Interfaces\SeoInterface;

class SeoRepository extends RepositoriesAbstract implements SeoInterface
{
    public function __construct() {
        $this->setModel(Seo::class);
    }

    /**
     * Store SEO
     *
     * @param
     * @param  array|null  $data
     * @return bool
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-19, 17:40 ICT
     */
    public function storeSeo($model, ?array $data = []): bool
    {
        //  NOTE: Do not use the updateOrInsert via eloquent!
        $check = $this->findBy([
            'target_type' => get_class($model),
            'target_id' => $model->id ?? 0,
        ]);
        if (!$check) {
            $check = $this->reset()->model();
        }
        return $check->fill([
            'target_type' => get_class($model),
            'target_id' => $model->id ?? 0,
            'title' => ($data['title'] ?? ''),
            'description' => ($data['description'] ?? ''),
            'hashtag' => ($data['hashtag'] ?? []),
            'custom' => $data['custom'] ?? [],
            'facebook' => $data['facebook'] ?? [],
            'twitter' => $data['twitter'] ?? [],
            'config' => $data['config'] ?? [],
        ])->save();
    }
}
