<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\Email;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for email configuration persistence and queries.
 *
 * Implements {@see EmailInterface} for {@see Email} persistence.
 *
 * @see \Cms\Core\Models\Email
 * @see \Cms\Core\Interfaces\EmailInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EmailRepository extends RepositoriesAbstract implements EmailInterface
{
    /**
     * Initialize the repository with the Email model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(Email::class);
    }
}
