<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Models\EmailTemplate;
use Cms\Core\Interfaces\EmailTemplateInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for email template persistence and queries.
 *
 * Implements {@see EmailTemplateInterface} for {@see EmailTemplate} persistence.
 *
 * @see \Cms\Core\Models\EmailTemplate
 * @see \Cms\Core\Interfaces\EmailTemplateInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EmailTemplateRepository extends RepositoriesAbstract implements EmailTemplateInterface
{
    /**
     * Initialize the repository with the EmailTemplate model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(EmailTemplate::class);
    }
}
