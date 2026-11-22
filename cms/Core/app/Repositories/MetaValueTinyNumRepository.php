<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueTinyNum;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueTinyNumInterface;

/**
 * Eloquent repository for small integer meta value persistence and queries.
 *
 * Implements {@see MetaValueTinyNumInterface} for {@see MetaValueTinyNum} persistence.
 *
 * @see \Cms\Core\Models\MetaValueTinyNum
 * @see \Cms\Core\Interfaces\MetaValueTinyNumInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueTinyNumRepository extends RepositoriesAbstract implements MetaValueTinyNumInterface
{
    /**
     * Initialize the repository with the MetaValueTinyNum model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueTinyNum::class);
    }
}
