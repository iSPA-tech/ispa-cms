<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Support\Modules\ArtisanSubprocess;
use Cms\Core\Services\Admin\AdminRuntimeConfig;
use Cms\Core\Services\CoreUpgrade\CoreAdminSourceOverlay;
use Cms\Core\Services\Toolchain\AdminPaths;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Rebuilds the Vue admin on a deployed site through the deploy script `.github/deployment/admin-rebuild.sh`.
 *
 * The script ships inside every release and is run as `bash <script> <php> <site base dir>` (argv array, never a shell
 * string) from the release folder with the clean environment of {@see ArtisanSubprocess}. The site base dir is the
 * folder that holds `current`, `releases/` and `runtime/`; it is only known when this release lives in `releases/`.
 * Success needs exit code 0 and a final line `ADMIN_BUILD_OK <output dir>`.
 */
class AdminRebuilder
{
    /** The site is not a deployed release, or the release has no rebuild script. */
    public const ERROR_NOT_DEPLOYED = 'MODULE_ADMIN_BUILD_NOT_DEPLOYED';

    /** The build script failed or did not report success. */
    public const ERROR_FAILED = 'MODULE_ADMIN_BUILD_FAILED';

    /** Seconds the build may run. */
    public const TIMEOUT = 2400;

    private const SCRIPT = '.github/deployment/admin-rebuild.sh';

    private readonly ArtisanSubprocess $artisan;

    /**
     * @param ArtisanSubprocess|null $artisan  PHP binary and clean environment provider
     * @param string|null            $basePath Release folder (default: base_path()); tests pass a temp dir
     */
    public function __construct(?ArtisanSubprocess $artisan = null, private readonly ?string $basePath = null)
    {
        $this->artisan = $artisan ?? new ArtisanSubprocess();
    }

    /**
     * Run the admin build.
     *
     * @return array{ok: bool, errorCode: string|null, outputDir: string|null, message: string}
     */
    public function rebuild(): array
    {
        $release = realpath($this->basePath ?? base_path());
        if ($release !== false && basename(dirname($release)) !== 'releases') {
            return $this->rebuildLocally($release);
        }

        if ($release === false) {
            return $this->failure(self::ERROR_NOT_DEPLOYED, 'This site is not running from a deployed release; the admin cannot be rebuilt here.');
        }

        $script = realpath($release . '/' . self::SCRIPT);
        if ($script === false || ! is_file($script)) {
            return $this->failure(self::ERROR_NOT_DEPLOYED, 'This release has no admin rebuild script.');
        }

        // During a core update from the admin, the rebuild reads runtime/admin-workspace/admin: lay the package's admin
        // source over it first. Done here too (not only in CoreUpgrader) because the update that installs this code is
        // still run by the previous CoreUpgrader, which reaches only this class, loaded fresh after the swap.
        $upgradeTree = CoreAdminSourceOverlay::runningUpgradeTree($release);
        if ($upgradeTree !== null) {
            try {
                (new CoreAdminSourceOverlay())->apply($upgradeTree, $release);
            } catch (Throwable $e) {
                Log::warning('The core package admin source was not copied before the rebuild.', ['error' => $e->getMessage()]);
            }
        }

        $result = Process::path($release)
            ->env($this->artisan->environment($this->artisan->inheritedEnvironment()))
            ->timeout(self::TIMEOUT)
            ->run(['bash', $script, $this->artisan->phpBinary(), dirname($release, 2)]);

        if ($result->exitCode() === 0 && preg_match('/^ADMIN_BUILD_OK (\S.*)$/m', $result->output(), $match) === 1) {
            $this->writeConfig($release);

            return ['ok' => true, 'errorCode' => null, 'outputDir' => trim($match[1]), 'message' => 'The admin was rebuilt.'];
        }

        Log::warning('Admin rebuild failed.', [
            'exit_code' => $result->exitCode(),
            'stderr' => ArtisanSubprocess::truncate($result->errorOutput()),
        ]);

        return $this->failure(self::ERROR_FAILED, 'The admin build failed; see the server log.');
    }

    /**
     * A site that is not a deployed release (a `composer create-project` or workspace checkout): build the admin
     * here with `cms:build --admin` when the admin sources, `node` and `pnpm` are all present (spec 5.3). Otherwise
     * the site stays "not deployed" and the caller warns as before.
     *
     * @return array{ok: bool, errorCode: string|null, outputDir: string|null, message: string}
     */
    private function rebuildLocally(string $base): array
    {
        $finder = new ExecutableFinder();
        $ready = (new AdminPaths($base))->root() !== null
            && $finder->find('node') !== null
            && $finder->find('pnpm') !== null;

        if (! $ready) {
            return $this->failure(self::ERROR_NOT_DEPLOYED, 'This site is not running from a deployed release; the admin cannot be rebuilt here.');
        }

        $result = Process::path($base)
            ->env($this->artisan->environment($this->artisan->inheritedEnvironment()))
            ->timeout(self::TIMEOUT)
            ->run([$this->artisan->phpBinary(), $base.'/artisan', 'cms:build', '--admin']);

        if ($result->exitCode() === 0) {
            return ['ok' => true, 'errorCode' => null, 'outputDir' => $base.'/public/iPanel', 'message' => 'The admin was rebuilt.'];
        }

        Log::warning('Local admin build failed.', [
            'exit_code' => $result->exitCode(),
            'stderr' => ArtisanSubprocess::truncate($result->errorOutput()),
        ]);

        return $this->failure(self::ERROR_FAILED, 'The admin build failed; see the server log.');
    }

    /**
     * Write config.js into the rebuilt admin. `<release>/public/iPanel` points at the new build once the script has
     * switched the pointer, so this lands inside it. Not fatal: the admin falls back to the browser location.
     */
    private function writeConfig(string $release): void
    {
        try {
            (new AdminRuntimeConfig())->write($release.'/public/iPanel/config.js');
        } catch (\Throwable $e) {
            Log::warning('Admin config.js could not be written after the rebuild.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Failure result.
     *
     * @return array{ok: bool, errorCode: string|null, outputDir: string|null, message: string}
     */
    private function failure(string $code, string $message): array
    {
        return ['ok' => false, 'errorCode' => $code, 'outputDir' => null, 'message' => $message];
    }
}
