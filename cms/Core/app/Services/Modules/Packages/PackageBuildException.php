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
 * Refusal raised by PackageBuilder when a module cannot be packaged for runtime installation.
 *
 * The message is a clear English sentence; the code is one of the constants below.
 */
final class PackageBuildException extends RuntimeException
{
    public const LINT = 'MODULE_PACKAGE_BUILD_LINT';
    public const VERSION = 'MODULE_PACKAGE_BUILD_VERSION';
    public const FORBIDDEN_MODULE = 'MODULE_PACKAGE_BUILD_FORBIDDEN_MODULE';
    public const VITE_CONFIG = 'MODULE_PACKAGE_BUILD_VITE_CONFIG';
    public const COMPOSER_REQUIRE = 'MODULE_PACKAGE_BUILD_COMPOSER_REQUIRE';
    public const COMPOSER_AUTOLOAD = 'MODULE_PACKAGE_BUILD_COMPOSER_AUTOLOAD';
    public const SYMLINK = 'MODULE_PACKAGE_BUILD_SYMLINK';
    public const ADMIN_IMPORT = 'MODULE_PACKAGE_BUILD_ADMIN_IMPORT';
    public const SHARED_PACKAGES = 'MODULE_PACKAGE_BUILD_SHARED_PACKAGES';
    public const LIMITS = 'MODULE_PACKAGE_BUILD_LIMITS';
    public const KEY_ID = 'MODULE_PACKAGE_BUILD_KEY_ID';
    public const NOT_FOUND = 'MODULE_PACKAGE_BUILD_NOT_FOUND';
    public const IO = 'MODULE_PACKAGE_BUILD_IO';
    public const DEPLOY_ONLY = 'MODULE_PACKAGE_BUILD_DEPLOY_ONLY';
    public const FRONTEND_BUILD = 'MODULE_PACKAGE_BUILD_FRONTEND_BUILD';
    /** a Content/Account migration drops or renames a column or table. */
    public const DESTRUCTIVE_MIGRATION = 'MODULE_PACKAGE_BUILD_DESTRUCTIVE_MIGRATION';
    /** CHANGELOG.md has no section for the version and `core.module_packages.require_changelog` is on. */
    public const CHANGELOG = 'MODULE_PACKAGE_BUILD_CHANGELOG';

    /** an `open` module requires a premium, internal, client or game module. */
    public const EDITION = 'MODULE_PACKAGE_BUILD_EDITION';

    /**
     * @param string $errorCode one of the class constants
     */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
