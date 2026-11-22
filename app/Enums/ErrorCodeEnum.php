<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Enums;

enum ErrorCodeEnum: string
{
    case UNAUTHORIZED = 'UNAUTHORIZED'; // Unauthorized
    case AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED'; // Authentication failed
    case USER_NOT_FOUND = 'USER_NOT_FOUND'; // User not found
    case PASSWORD_NOT_MATCH = 'PASSWORD_NOT_MATCH'; // Password not match
    case EMAIL_NOT_FOUND = 'EMAIL_NOT_FOUND'; // Email not found
    case EMAIL_ALREADY_EXISTS = 'EMAIL_ALREADY_EXISTS'; // Email already exists
    case USERNAME_NOT_FOUND = 'USERNAME_NOT_FOUND'; // Username not found
    case USERNAME_ALREADY_EXISTS = 'USERNAME_ALREADY_EXISTS'; // Username already exists
    case TOKEN_EXPIRED = 'TOKEN_EXPIRED'; // Token expired
    case ACCOUNT_BANNED = 'ACCOUNT_BANNED'; // Account is not allowed to sign in
    case PERMISSION_DENIED = 'PERMISSION_DENIED'; // Permission denied
    case VALIDATION_FAILED = 'VALIDATION_FAILED'; // Validation failed
    case INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR'; // Internal server error
    case OBJECT_NOT_FOUND = 'OBJECT_NOT_FOUND'; // Not found
    case OBJECT_ALREADY_EXISTS = 'OBJECT_ALREADY_EXISTS'; // Already exists


    public static function get(string $key): ?string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $case->value;
            }
        }

        return null;
    }
}
