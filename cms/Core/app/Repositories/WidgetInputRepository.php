<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\WidgetInput;
use Cms\Core\Interfaces\WidgetInputInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for widget input configuration persistence and queries.
 *
 * Implements {@see WidgetInputInterface} for {@see WidgetInput} persistence.
 *
 * @see \Cms\Core\Models\WidgetInput
 * @see \Cms\Core\Interfaces\WidgetInputInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WidgetInputRepository extends RepositoriesAbstract implements WidgetInputInterface
{
    /**
     * Initialize the repository with the WidgetInput model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(WidgetInput::class);
    }
}
