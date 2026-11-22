<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for User repository operations within the Core module.
 *
 * @extends RepositoryInterface
 * @package Cms\Core\Interfaces
 */
interface UserInterface extends RepositoryInterface
{
    /**
     * Get User by UUID
     *
     * @param string $uuid
     * @param array $select
     *
     * @return Model|null
     * @author vuongnm
     * @since 6.62.6 - 2023-01-09, 10:49 ICT
     */
    public function findByUuid(string $uuid, array $select = ['*']): ?Model;

    /**
     * Get user info by email OR username
     *
     * @param string $emailOrUsername
     * @param array $with
     *
     * @return Model|Collection|Builder|array|null
     * @author malayvuong
     * @since 6.19.6 - 2022-06-16, 23:38 ICT
     */
    public function getByEmailOrUsername(string $emailOrUsername = '', array $with = []): Model|Collection|Builder|array|null;
}
