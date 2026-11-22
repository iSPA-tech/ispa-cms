<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\MetaAttribute;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\MetaAttributeInterface;

/**
 * Eloquent repository for meta attribute definition persistence and queries.
 *
 * Implements {@see MetaAttributeInterface} for {@see MetaAttribute} persistence.
 *
 * @see \Cms\Core\Models\MetaAttribute
 * @see \Cms\Core\Interfaces\MetaAttributeInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaAttributeRepository extends RepositoriesAbstract implements MetaAttributeInterface
{
    /**
     * Initialize the repository with the MetaAttribute model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(MetaAttribute::class);
    }
}
