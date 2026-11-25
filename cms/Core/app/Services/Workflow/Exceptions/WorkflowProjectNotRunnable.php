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
 * Raised by WorkflowProjectRunService::start(): System-managed workflow wrappers run only from their agent schedule.
 *
 * @package Cms\Core\Services\Workflow\Exceptions
 * @author  iSPA Team
 * @since   2026.11 (2026-11-24)
 */
class WorkflowProjectNotRunnable extends RuntimeException
{
    /**
     * Machine-readable reason returned to the admin API.
     */
    public const CODE = 'workflow_not_runnable';

    public function __construct(string $message = 'System-managed workflow wrappers run only from their agent schedule.')
    {
        parent::__construct($message);
    }
}
