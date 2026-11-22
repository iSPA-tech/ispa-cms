<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Interfaces\WorkflowStepInterface;

/**
 * Eloquent repository for workflow step persistence and queries.
 *
 * Implements {@see WorkflowStepInterface} for {@see WorkflowStep} persistence.
 *
 * @see \Cms\Core\Models\Workflows\WorkflowStep
 * @see \Cms\Core\Interfaces\WorkflowStepInterface
 *
 * @package Cms\Core\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class WorkflowStepRepository extends RepositoriesAbstract implements WorkflowStepInterface
{
    /**
     * Initialize the repository with the WorkflowStep model.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setModel(WorkflowStep::class);
    }
}
