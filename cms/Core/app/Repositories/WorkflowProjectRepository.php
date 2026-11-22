<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Interfaces\WorkflowProjectInterface;

/**
 * Eloquent repository for workflow project definition persistence and queries.
 *
 * Implements {@see WorkflowProjectInterface} for {@see WorkflowProject} persistence.
 *
 * @see \Cms\Core\Models\Workflows\WorkflowProject
 * @see \Cms\Core\Interfaces\WorkflowProjectInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowProjectRepository extends RepositoriesAbstract implements WorkflowProjectInterface
{
    /**
     * Initialize the repository with the WorkflowProject model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(WorkflowProject::class);
    }
}
 
