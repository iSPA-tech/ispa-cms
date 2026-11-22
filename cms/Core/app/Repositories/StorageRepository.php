<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Storage;
use Cms\Core\Interfaces\StorageInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for file/media storage record persistence and queries.
 *
 * Implements {@see StorageInterface} for {@see Storage} persistence.
 *
 * @see \Cms\Core\Models\Storage
 * @see \Cms\Core\Interfaces\StorageInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StorageRepository extends RepositoriesAbstract implements StorageInterface
{
    /**
     * Initialize the repository with the Storage model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(Storage::class);
    }
}
