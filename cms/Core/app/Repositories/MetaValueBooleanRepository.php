<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueBoolean;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueBooleanInterface;

/**
 * Eloquent repository for boolean meta value persistence and queries.
 *
 * Implements {@see MetaValueBooleanInterface} for {@see MetaValueBoolean} persistence.
 *
 * @see \Cms\Core\Models\MetaValueBoolean
 * @see \Cms\Core\Interfaces\MetaValueBooleanInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueBooleanRepository extends RepositoriesAbstract implements MetaValueBooleanInterface
{
    /**
     * Initialize the repository with the MetaValueBoolean model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueBoolean::class);
    }
}
