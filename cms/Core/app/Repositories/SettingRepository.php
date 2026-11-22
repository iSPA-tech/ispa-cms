<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Setting;
use Cms\Core\Interfaces\SettingInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Class SettingRepository
 * Eloquent repository for the Setting model.
 *
 * @extends RepositoriesAbstract
 * @implements SettingInterface
 * @see Setting
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class SettingRepository extends RepositoriesAbstract implements SettingInterface
{
    /**
     * Initialize the repository and bind the Setting model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Setting::class);
    }

    /**
     * Update via Eloquent model so that casts (e.g. EncryptedSettingValue) fire.
     *
     * The parent implementation uses a query-builder UPDATE which bypasses casts.
     * @param  array $condition
     * @param  array $data
     * @return bool
     */
    public function update(array $condition, array $data): bool
    {
        $model = $this->findBy($condition);

        if (!$model) {
            return false;
        }

        $model->fill($data);

        return $model->save();
    }
}
