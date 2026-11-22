<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Logging;

use Carbon\CarbonImmutable;

/**
 * Normalizes workflow step log entries into a consistent structure.
 *
 * Ensures every log entry has a level, message, context, and ISO-8601 timestamp,
 * regardless of whether the input is a structured array, a plain string, or another type.
 *
 * @package Cms\Core\Workflows\Logging
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class StepLogFormatter
{
    /**
     * Normalize a log entry to the standard structure.
     *
     * @param mixed $log
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function format(mixed $log, array $context = []): array
    {
        // If already in expected shape, just ensure timestamp exists
        if (is_array($log) && isset($log['level'], $log['message'])) {
            return array_merge([
                'level' => 'info',
                'message' => '',
                'context' => $context,
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ], $log);
        }

        if (is_string($log)) {
            return [
                'level' => 'info',
                'message' => $log,
                'context' => $context,
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ];
        }

        return [
            'level' => 'info',
            'message' => json_encode($log),
            'context' => $context,
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
