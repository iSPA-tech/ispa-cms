<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use JsonException;
use App\Models\Province;
use App\Repositories\Interfaces\ProvinceInterface;

class ProvinceRepository extends RepositoriesAbstract implements ProvinceInterface
{
    public function __construct()
    {
        $this->setModel(Province::class);
    }

    /**
     * Get parent data via UUID with children relationship
     *
     * @param string|null $uuid
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-14, 17:33 ICT
     */
    public function getProvince(string $uuid = null): mixed
    {
        $cacheKey = 'province_' . $uuid;
        $ttl = 60 * 60 * 24; // 1 day

        return cache()->remember($cacheKey, $ttl, function () use ($uuid) {
            return $this->findBy(['uuid' => $uuid], ['*'], ['children:uuid,name,parent_id']);
        });
    }

    /**
     * Get all provinces list
     *
     * @param int $parentId
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-04-14, 14:18 ICT
     * @author malayvuong
     */
    public function getProvinces(int $parentId = 0): mixed
    {
        $cacheKey = 'provinces_' . $parentId;
        $ttl = 60 * 60; // 1 hour to updated priority

        return cache()->remember($cacheKey, $ttl, function () use ($parentId) {
            $data = $this->get([
                'when' => [
                    [
                        $parentId > 0,
                        fn($q) => $q->where('parent_id', $parentId),
                    ],
                    [
                        $parentId === 0,
                        fn($q) => $q->whereNull('parent_id'),
                    ],
                ],
                'order' => ['priority' => 'DESC', 'name' => 'ASC'],
                'select' => ['id', 'uuid', 'name', 'global_name', 'parent_id', 'config'],
            ]);

            return $data->map(function ($item) {
                // Decode JSON config to array, then return item with phoneCode in array
                $item->phoneCode = $item->config['phoneCode'] ?? null;
                unset($item->config);
                return $item;
            })->all();
        });
    }
}
