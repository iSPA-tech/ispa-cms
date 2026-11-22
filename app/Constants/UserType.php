<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

class UserType
{
    public const WEBMASTER = 0;
    public const ADMIN = 1;
    public const CLIENT = 2;
    public const USER = 3;

    /** @var array user setting default (group -> key -> value) */
    public const SETTING_DEFAULT = [
        'security' => [
            'mfa_mode' => [
                'value' => 'off',
                'type' => 'select',
                'options' => [],
            ],
            'two_fa' => [
                'value' => false,
                'type' => 'boolean',
            ],
            'email_change_password' => [
                'value' => false,
                'type' => 'boolean',
            ],
        ],
        'localization' => [
            'language' => [
                'value' => 'en',
                'type' => 'select',
                'options' => [],
            ],
            'timezone' => [
                'value' => 'Asia/Ho_Chi_Minh',
                'type' => 'select',
                'options' => [],
            ],
        ],
//        'social' => [
//            'facebook' => [
//                'value' => null,
//                'type' => 'string',
//            ],
//            'google' => [
//                'value' => null,
//                'type' => 'string',
//            ],
//        ]
    ];
    /** @var array user meta default (key -> value) */
    public const USER_META_DEFAULT = [
    ];

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
