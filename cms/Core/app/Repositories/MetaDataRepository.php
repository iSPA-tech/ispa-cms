<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaData;
use Cms\Core\Interfaces\MetaDataInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for key-value metadata record persistence and queries.
 *
 * Implements {@see MetaDataInterface} for {@see MetaData} persistence.
 *
 * @see \Cms\Core\Models\MetaData
 * @see \Cms\Core\Interfaces\MetaDataInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaDataRepository extends RepositoriesAbstract implements MetaDataInterface
{
    /**
     * Initialize the repository with the MetaData model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaData::class);
    }
}
