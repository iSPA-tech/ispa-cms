<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Traits\Models;

use DateTimeInterface;

/**
 * Trait SerializeDateTrait
 *
 * Provides custom serialization for date attributes in Eloquent models, allowing for consistent
 * formatting and optional custom formatted date accessors.
 *
 * Usage:
 *   - Add this trait to your Eloquent model to override the default date serialization.
 *   - Optionally use the getFormattedDateAttribute() accessor for human-readable date strings.
 *
 * @package Cms\Core\Traits\Models
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
trait SerializeDateTrait
{
    /**
     * Set default date attribute format for serialization.
     *
     * @param DateTimeInterface $date
     *
     * @return string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        // Use global helper or fallback to app timezone
        $timezone = function_exists('get_timezone') ? get_timezone() : config('app.timezone', 'UTC');

        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    /**
     * Accessor: Get a human-readable formatted date string.
     *
     * @return string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('M d, Y');
    }
}
