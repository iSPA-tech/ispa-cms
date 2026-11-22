<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for Upload repository operations and helpers for syncing media relations.
 *
 * @extends RepositoryInterface
 * @package Cms\Core\Interfaces
 */
interface UploadInterface extends RepositoryInterface
{
    /**
     * Get file upload detail
     *
     * @param array $condition
     *
     * @return object|array|null
     * @author malayvuong
     * @since 6.62.6 - 2022-08-16, 21:38 ICT
     */
    public function findByCache(array $condition): object|array|null;

    /**
     * Update attach file in article or somewhere else
     *
     * @param array $data
     * @param object|null $model
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2022-10-05, 00:10 ICT
     */
    public function updateAttach(array $data, object $model = null): void;

    /**
     * Update Images target
     * @param  object $model
     * @return mixed
     */
    public function updateImage(object $model = null);

    /**
     * Update Images target
     * @param  object $model
     * @return mixed
     */
    public function updateLogo(object $model = null);

    /**
     * Update content image
     * @param  object $model
     * @return mixed
     */
    public function updateContent(object $model = null);

    /**
     * Get new object of images in content attribute via model
     * @param  object $model
     * @return mixed
     */
    public function getContent(object $model = null);
}
