<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Workflow\Exceptions;

use RuntimeException;

/**
 * Raised by WorkflowProjectRunService::start(): This workflow project already has a run in progress.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowAlreadyRunning extends RuntimeException
{
    /**
     * Machine-readable reason returned to the admin API.
     */
    public const CODE = 'workflow_already_running';

    public function __construct(string $message = 'This workflow project already has a run in progress.')
    {
        parent::__construct($message);
    }
}
