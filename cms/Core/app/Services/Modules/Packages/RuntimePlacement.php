<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use Closure;
use Throwable;

/**
 * Moves a staged, verified module folder into the runtime modules folder atomically.
 *
 * The folder is renamed to "<runtimeRoot>/.versions/<Name>-<version>", then a temporary symlink pointing at
 * ".versions/<Name>-<version>" is renamed onto "<runtimeRoot>/<Name>". Both steps are rename() on one filesystem, so
 * readers see either nothing or the complete module. Only first installs are handled: an existing version folder or
 * module link is refused. The staging folder is removed in every case; the runtime folder is untouched on failure.
 */
final class RuntimePlacement
{
    public const CROSS_DEVICE = 'MODULE_PLACE_CROSS_DEVICE';
    public const TARGET_EXISTS = 'MODULE_PLACE_TARGET_EXISTS';
    public const LINK_EXISTS = 'MODULE_PLACE_LINK_EXISTS';
    public const STAGED_INVALID = 'MODULE_PLACE_STAGED_INVALID';
    public const IO = 'MODULE_PLACE_IO';

    /** Folder of version folders inside the runtime folder. */
    public const VERSIONS_FOLDER = '.versions';

    /** @var Closure(string): ?int */
    private Closure $deviceOf;

    /**
     * @param (callable(string): ?int)|null $deviceOf returns the filesystem device id of a path (default: stat dev)
     */
    public function __construct(?callable $deviceOf = null)
    {
        $this->deviceOf = $deviceOf !== null
            ? Closure::fromCallable($deviceOf)
            : static function (string $path): ?int {
                $stat = @stat($path);

                return $stat === false ? null : (int) $stat['dev'];
            };
    }

    /**
     * Place a staged module folder ("<staging>/<Name>") into the runtime folder and return its version folder path.
     *
     * @throws PackageVerificationException with one of the MODULE_PLACE_* codes
     */
    public function place(string $stagedModuleDir, ReleaseManifest $manifest, string $runtimeRoot): string
    {
        $stagedModuleDir = rtrim($stagedModuleDir, '/');
        $stagingRoot = dirname($stagedModuleDir);
        $runtimeRoot = rtrim($runtimeRoot, '/');

        try {
            return $this->move($stagedModuleDir, $manifest, $runtimeRoot);
        } finally {
            SafeExtractor::removeTree($stagedModuleDir, $stagingRoot);
        }
    }

    /**
     * Run the checks, the version-folder rename and the link swap; roll back the version folder on failure.
     */
    private function move(string $staged, ReleaseManifest $manifest, string $runtimeRoot): string
    {
        if (basename($staged) !== $manifest->name || is_link($staged) || !is_dir($staged)) {
            throw new PackageVerificationException(self::STAGED_INVALID, 'staged module folder is missing or misnamed');
        }
        if ($runtimeRoot === '' || is_link($runtimeRoot) || !is_dir($runtimeRoot)) {
            throw new PackageVerificationException(self::IO, 'runtime folder is missing');
        }

        $versions = $runtimeRoot . '/' . self::VERSIONS_FOLDER;
        $relative = self::VERSIONS_FOLDER . '/' . $manifest->name . '-' . $manifest->version;
        $target = $runtimeRoot . '/' . $relative;
        $link = $runtimeRoot . '/' . $manifest->name;
        $versionsExists = @lstat($versions) !== false;
        if ($versionsExists && (is_link($versions) || !is_dir($versions))) {
            throw new PackageVerificationException(self::IO, 'runtime versions path is not a folder');
        }

        $stagedDevice = ($this->deviceOf)($staged);
        $runtimeDevice = ($this->deviceOf)($versionsExists ? $versions : $runtimeRoot);
        if ($stagedDevice === null || $runtimeDevice === null) {
            throw new PackageVerificationException(self::IO, 'cannot read the filesystem device');
        }
        if ($stagedDevice !== $runtimeDevice) {
            throw new PackageVerificationException(self::CROSS_DEVICE, 'staging and runtime folders are on different filesystems');
        }
        if (@lstat($target) !== false) {
            throw new PackageVerificationException(self::TARGET_EXISTS, 'version folder already exists');
        }
        if (@lstat($link) !== false) {
            throw new PackageVerificationException(self::LINK_EXISTS, 'module already exists in the runtime folder');
        }

        $createdVersions = false;
        if (!$versionsExists) {
            if (!@mkdir($versions, 0755) || !@chmod($versions, 0755)) {
                @rmdir($versions);
                throw new PackageVerificationException(self::IO, 'cannot create the versions folder');
            }
            $createdVersions = true;
        }

        $moved = false;
        $tempLink = $runtimeRoot . '/.' . $manifest->name . '.link-' . bin2hex(random_bytes(6));
        try {
            if (!@rename($staged, $target)) {
                throw new PackageVerificationException(self::IO, 'cannot move the module into the versions folder');
            }
            $moved = true;
            if (!@symlink($relative, $tempLink)) {
                throw new PackageVerificationException(self::IO, 'cannot create the module link');
            }
            if (!@rename($tempLink, $link)) {
                throw new PackageVerificationException(self::IO, 'cannot publish the module link');
            }

            return $target;
        } catch (Throwable $e) {
            if (is_link($tempLink)) {
                @unlink($tempLink);
            }
            if ($moved) {
                SafeExtractor::removeTree($target, $versions);
            }
            if ($createdVersions) {
                @rmdir($versions);
            }
            throw $e instanceof PackageVerificationException
                ? $e
                : new PackageVerificationException(self::IO, 'placement failed unexpectedly');
        }
    }
}
