<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

/**
 * A step of switching module install on failed ({@see ModuleInstallPolicy::enable()}); `code` is the API error code.
 */
final class ModuleInstallSettingException extends \RuntimeException
{
    public const RUNTIME_FOLDER_FAILED = 'MODULE_INSTALL_RUNTIME_FOLDER_FAILED';

    public const MARKER_FAILED = 'MODULE_INSTALL_ACTIVATOR_MARKER_FAILED';

    public const CONFIG_CACHE_FAILED = 'MODULE_INSTALL_CONFIG_CACHE_FAILED';

    public const WORKER_RESTART_FAILED = 'MODULE_INSTALL_WORKER_RESTART_FAILED';

    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
