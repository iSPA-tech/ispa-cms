<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\WidgetTheme;
use Cms\Core\Interfaces\WidgetThemeInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for widget theme/skin configuration persistence and queries.
 *
 * Implements {@see WidgetThemeInterface} for {@see WidgetTheme} persistence.
 *
 * @see \Cms\Core\Models\WidgetTheme
 * @see \Cms\Core\Interfaces\WidgetThemeInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WidgetThemeRepository extends RepositoriesAbstract implements WidgetThemeInterface
{
    /**
     * Initialize the repository with the WidgetTheme model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(WidgetTheme::class);
    }
}
