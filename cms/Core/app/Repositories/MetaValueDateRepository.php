<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueDate;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueDateInterface;

/**
 * Eloquent repository for date/datetime meta value persistence and queries.
 *
 * Implements {@see MetaValueDateInterface} for {@see MetaValueDate} persistence.
 *
 * @see \Cms\Core\Models\MetaValueDate
 * @see \Cms\Core\Interfaces\MetaValueDateInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueDateRepository extends RepositoriesAbstract implements MetaValueDateInterface
{
    /**
     * Initialize the repository with the MetaValueDate model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueDate::class);
    }
}
