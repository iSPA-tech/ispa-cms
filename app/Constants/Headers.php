<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

class Headers
{
    public const ISPA_AUTH_FAILED_REMAINING_COUNT = 'ISPA-AUTH-FAILED-REMAINING-COUNT';
    public const ISPA_AUTH_FAILED_REMAINING_MESSAGE = 'ISPA-AUTH-FAILED-REMAINING-MESSAGE';
    public const ISPA_AUTH_FAILED_BLOCK = 'ISPA-AUTH-FAILED-BLOCK';
    public const ISPA_AUTH_FAILED_REMAINING_TIME = 'ISPA-AUTH-FAILED-REMAINING-TIME';

    public static function getAll(): array
    {
        return [
            self::ISPA_AUTH_FAILED_BLOCK,
            self::ISPA_AUTH_FAILED_REMAINING_COUNT,
            self::ISPA_AUTH_FAILED_REMAINING_MESSAGE,
            self::ISPA_AUTH_FAILED_REMAINING_TIME,
        ];
    }

    /**
     * Get all constants defined in the class
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-23, 23:25 ICT
     */
    public static function getConstants(): array
    {
        // Get the class name
        $className = static::class;

        // Create a ReflectionClass instance for the class
        // Get all constants defined in the class
        return (new ReflectionClass($className))->getConstants();
    }
}
