<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\EmailLog;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for email log record persistence and queries.
 *
 * Implements {@see EmailLogInterface} for {@see EmailLog} persistence.
 *
 * @see \Cms\Core\Models\EmailLog
 * @see \Cms\Core\Repositories\Interfaces\EmailLogInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EmailLogRepository extends RepositoriesAbstract implements EmailLogInterface
{
    /**
     * Initialize the repository with the EmailLog model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(EmailLog::class);
    }
}
