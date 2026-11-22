<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueBigNum;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueBigNumInterface;

/**
 * Eloquent repository for large numeric meta value persistence and queries.
 *
 * Implements {@see MetaValueBigNumInterface} for {@see MetaValueBigNum} persistence.
 *
 * @see \Cms\Core\Models\MetaValueBigNum
 * @see \Cms\Core\Interfaces\MetaValueBigNumInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueBigNumRepository extends RepositoriesAbstract implements MetaValueBigNumInterface
{
    /**
     * Initialize the repository with the MetaValueBigNum model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueBigNum::class);
    }
}
