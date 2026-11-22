<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Agents\Runtime;

/**
 * Enforces timeout on tool execution using set_time_limit.
 *
 * Temporarily reduces the PHP time limit for the duration of a callable,
 * then restores the original limit. If the callable exceeds the timeout,
 * PHP raises a fatal error which is caught as a Throwable.
 *
 * @package Cms\Core\Agents\Runtime
 * @author  iSPA Team
 * @since   8.0.0
 */
class ToolExecutionTimeout
{
    /**
     * Execute a callable with a timeout constraint.
     *
     * @param  callable $callable      The callable to execute.
     * @param  int|null $timeoutSeconds Maximum execution time (null = no limit).
     * @return mixed The callable's return value.
     *
     * @throws \RuntimeException When the execution exceeds the timeout.
     */
    public static function execute(callable $callable, ?int $timeoutSeconds = null): mixed
    {
        if ($timeoutSeconds === null || $timeoutSeconds <= 0) {
            return $callable();
        }

        $previousLimit = (int) ini_get('max_execution_time');

        set_time_limit($timeoutSeconds);

        try {
            $result = $callable();
        } finally {
            // Restore the original limit (0 = unlimited)
            set_time_limit($previousLimit);
        }

        return $result;
    }
}
