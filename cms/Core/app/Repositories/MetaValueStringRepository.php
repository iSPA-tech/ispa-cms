<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueString;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueStringInterface;

/**
 * Eloquent repository for short string meta value persistence and queries.
 *
 * Implements {@see MetaValueStringInterface} for {@see MetaValueString} persistence.
 *
 * @see \Cms\Core\Models\MetaValueString
 * @see \Cms\Core\Interfaces\MetaValueStringInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueStringRepository extends RepositoriesAbstract implements MetaValueStringInterface
{
    /**
     * Initialize the repository with the MetaValueString model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueString::class);
    }
}
