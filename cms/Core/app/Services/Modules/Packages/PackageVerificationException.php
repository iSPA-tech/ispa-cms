<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use RuntimeException;

/**
 * Raised when a module package signature document or release manifest is refused.
 *
 * The machine-readable errorCode tells which verification step failed; the message never carries key material.
 */
final class PackageVerificationException extends RuntimeException
{
    public const ENVELOPE = 'MODULE_PACKAGE_ENVELOPE';
    public const SIGNATURE = 'MODULE_PACKAGE_SIGNATURE';
    public const KEY_UNKNOWN = 'MODULE_PACKAGE_KEY_UNKNOWN';
    public const KEY_REVOKED = 'MODULE_PACKAGE_KEY_REVOKED';
    public const KEY_INVALID = 'MODULE_PACKAGE_KEY_INVALID';
    public const KEY_MISMATCH = 'MODULE_PACKAGE_KEY_MISMATCH';
    public const SCHEMA = 'MODULE_PACKAGE_SCHEMA';
    public const FORMAT = 'MODULE_PACKAGE_FORMAT';

    /**
     * Create the exception with a stable error code and a human message.
     */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($errorCode . ': ' . $message);
    }
}
