<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\FieldItem;
use Cms\Core\Interfaces\FieldItemInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for field item (option/value) persistence and queries.
 *
 * Implements {@see FieldItemInterface} for {@see FieldItem} persistence.
 *
 * @see \Cms\Core\Models\FieldItem
 * @see \Cms\Core\Interfaces\FieldItemInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class FieldItemRepository extends RepositoriesAbstract implements FieldItemInterface
{
    /**
     * Initialize the repository with the FieldItem model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(FieldItem::class);
    }
}
