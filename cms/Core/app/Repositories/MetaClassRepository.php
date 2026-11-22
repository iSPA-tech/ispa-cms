<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaClass;
use Cms\Core\Interfaces\MetaClassInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for meta class/type definition persistence and queries.
 *
 * Implements {@see MetaClassInterface} for {@see MetaClass} persistence.
 *
 * @see \Cms\Core\Models\MetaClass
 * @see \Cms\Core\Interfaces\MetaClassInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaClassRepository extends RepositoriesAbstract implements MetaClassInterface
{
    /**
     * Initialize the repository with the MetaClass model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaClass::class);
    }
}
