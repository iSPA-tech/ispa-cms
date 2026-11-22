<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaValueText;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaValueTextInterface;

/**
 * Eloquent repository for long text meta value persistence and queries.
 *
 * Implements {@see MetaValueTextInterface} for {@see MetaValueText} persistence.
 *
 * @see \Cms\Core\Models\MetaValueText
 * @see \Cms\Core\Interfaces\MetaValueTextInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueTextRepository extends RepositoriesAbstract implements MetaValueTextInterface
{
    /**
     * Initialize the repository with the MetaValueText model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaValueText::class);
    }
}
