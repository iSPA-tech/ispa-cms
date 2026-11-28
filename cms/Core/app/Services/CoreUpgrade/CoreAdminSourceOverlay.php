<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Brings the admin source of a core package to a CI-deployed site before its admin is rebuilt.
 *
 * A CI deploy builds the admin from the release's `admin/`, copies that folder to
 * `<site base>/runtime/admin-workspace/admin` (deploy.sh "Admin workspace sync") and removes it from the release; every
 * later rebuild (`admin-rebuild.sh`) reads the workspace copy. An update of Core from the admin replaces only the files
 * of the core build list inside the release, so without this step the rebuilt admin kept the shell of the last deploy
 * and base-admin changes reached the site only with its next deploy.
 *
 * The package's `admin/` files are laid over the workspace copy: a file that differs is replaced (temporary file, then
 * rename), an equal one is left alone, and nothing is ever deleted, so admin code the public package does not carry
 * (a client's or a game's pages) stays as the deploy left it. `node_modules`, links and the admin's own `VERSION` (the
 * deploy owns the version, as it owns the root VERSION, which keys the admin bundle and its language packs) are
 * skipped. Only a release that lives in `<base>/releases/<name>` with an existing workspace folder is touched;
 * anything else is a no-op.
 */
final class CoreAdminSourceOverlay
{
    /** Files at the admin root the deploy owns. */
    private const DEPLOY_OWNED = ['VERSION'];

    /**
     * The unpacked package tree of the core update running in this release, or null when none runs.
     *
     * Read from the runner's private state file (`.ispa-runner/core-upgrade-state.json`, a regular file owned by this
     * process's user, as {@see CoreUpgrader} trusts it); its `staging` must be a direct child of
     * `.ispa-runner/upgrade-staging/` whose `tree` is a real folder of the same user. {@see \Cms\Core\Services\Modules\Install\AdminRebuilder}
     * asks this before a deploy rebuild: the runner of the update that installs this code still runs the previous
     * CoreUpgrader, which only rebuilds the admin, after the swap, so the new admin source arrives with that update.
     */
    public static function runningUpgradeTree(string $releaseRoot): ?string
    {
        $release = realpath($releaseRoot);
        if ($release === false) {
            return null;
        }
        $uid = CoreRunnerTick::currentUid($release);
        $stateFile = $release.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE;
        if (! CoreRunnerTick::ownedRegularFile($stateFile, $uid)) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($stateFile), true);
        $staging = is_array($state) && is_string($state['staging'] ?? null) ? realpath($state['staging']) : false;
        $stagingRoot = realpath($release.'/'.CoreUpgrader::RUNNER_DIR.'/upgrade-staging');
        if ($staging === false || $stagingRoot === false || dirname($staging) !== $stagingRoot) {
            return null;
        }

        return CoreRunnerTick::ownedDirectory($staging.'/tree', $uid) ? $staging.'/tree' : null;
    }

    /**
     * @return array{applied: bool, copied: int, reason: string|null}
     */
    public function apply(string $packageTree, string $releaseRoot): array
    {
        $release = realpath($releaseRoot);
        if ($release === false || basename(dirname($release)) !== 'releases') {
            return $this->skipped('not a release folder under releases/');
        }
        $workspace = dirname($release, 2).'/runtime/admin-workspace/admin';
        if (! is_dir($workspace) || is_link($workspace)) {
            return $this->skipped('no runtime/admin-workspace/admin');
        }
        $source = realpath($packageTree.'/admin');
        if ($source === false || ! is_dir($source)) {
            return $this->skipped('the package has no admin/ folder');
        }

        $copied = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($files as $path => $info) {
            $rel = substr((string) $path, strlen($source) + 1);
            if ($info->isLink() || in_array('node_modules', explode('/', $rel), true) || in_array($rel, self::DEPLOY_OWNED, true)) {
                continue;
            }
            if (! $info->isFile()) {
                continue;
            }
            $target = $workspace.'/'.$rel;
            if (is_link($target) || is_dir($target)) {
                continue;
            }
            if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', (string) $path)) {
                continue;
            }
            $dir = dirname($target);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new CoreUpgradeException('CORE_ADMIN_SOURCE_FAILED', 'Could not create '.$dir.' for the admin source.');
            }
            $temporary = $target.'.core-'.bin2hex(random_bytes(4));
            if (! @copy((string) $path, $temporary) || ! @rename($temporary, $target)) {
                @unlink($temporary);

                throw new CoreUpgradeException('CORE_ADMIN_SOURCE_FAILED', 'Could not write the admin source file '.$rel.'.');
            }
            $copied++;
        }

        return ['applied' => true, 'copied' => $copied, 'reason' => null];
    }

    /**
     * @return array{applied: bool, copied: int, reason: string|null}
     */
    private function skipped(string $reason): array
    {
        return ['applied' => false, 'copied' => 0, 'reason' => $reason];
    }
}
