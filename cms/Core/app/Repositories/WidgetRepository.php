<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Widget;
use Cms\Core\Interfaces\WidgetInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for CMS widget definition persistence and queries.
 *
 * Implements {@see WidgetInterface} for {@see Widget} persistence.
 *
 * @see \Cms\Core\Models\Widget
 * @see \Cms\Core\Interfaces\WidgetInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WidgetRepository extends RepositoriesAbstract implements WidgetInterface
{
    /**
     * Initialize the repository with the Widget model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(Widget::class);
    }
}
