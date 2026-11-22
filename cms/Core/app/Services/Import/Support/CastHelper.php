<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Import\Support;

use Carbon\Carbon;

/**
 * Utility for casting import field values to specified types.
 *
 * Supports int, float, bool, string, date, datetime, and json casts.
 * Returns null for null or empty string inputs. Used during the import
 * pipeline after transformers and before validation.
 *
 * @see CoreImportEngine
 * @package Cms\Core\Services\Import\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CastHelper
{
    /**
     * Cast a value to the specified type.
     *
     * @param mixed  $value The value to cast.
     * @param string $type  The target type (int, float, bool, string, date, datetime, json).
     * @return mixed The cast value, or null for null/empty input.
     */
    public static function cast(mixed $value, string $type): mixed
    {
        // Handle null and empty string
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string)$value,
            'date' => Carbon::parse($value)->toDateString(),
            'datetime' => Carbon::parse($value)->toDateTimeString(),
            'json' => is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            default => $value,
        };
    }
}
