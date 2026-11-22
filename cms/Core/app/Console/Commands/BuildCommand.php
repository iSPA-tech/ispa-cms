<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Admin\AdminRuntimeConfig;
use Cms\Core\Services\Toolchain\AdminPaths;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `./ispa build`: builds the site assets and/or the Vue admin on this machine, then writes the admin run-time config
 * and the marker that tells upgrades a local build exists (spec 5.3).
 *
 * A failed step leaves the released build in place (the admin is built straight into public/iPanel by Vite only at the
 * end of a successful build, and nothing is deleted first), writes no marker and does not touch config.js.
 */
class BuildCommand extends Command
{
    protected $signature = 'cms:build {--site : Build the site assets only} {--admin : Build the Vue admin only}';

    protected $description = 'Build the site assets and the Vue admin on this machine (ispa build).';

    /**
     * Run the selected builds.
     */
    public function handle(ProcessRunner $runner, AdminPaths $paths, AdminRuntimeConfig $adminConfig): int
    {
        $both = ! $this->option('site') && ! $this->option('admin');
        $site = $both || (bool) $this->option('site');
        $admin = $both || (bool) $this->option('admin');

        $adminRoot = null;
        if ($admin) {
            $adminRoot = $paths->root();
            if ($adminRoot === null) {
                return $this->failWith('The admin sources were not found (looked for admin/package.json and ../base-admin/package.json).', $site
                    ? 'Build only the site with `./ispa build --site`, or put the admin sources in the admin/ folder.'
                    : 'Put the admin sources in the admin/ folder.');
            }
        }

        if ($runner->run(['pnpm', '--version'], null, [], true) !== 0) {
            return $this->failWith('pnpm was not found.', 'Install Node.js 20+ and pnpm (`corepack enable`), then run `pnpm install` and try again.');
        }

        $version = (string) config('ispa-cms.app_version', '');

        if ($site && $runner->run(['pnpm', 'build'], $paths->base(), []) !== 0) {
            return $this->failWith('The site build failed.', 'Read the error above, run `pnpm install` if packages are missing, then try again.');
        }

        if ($admin && $adminRoot !== null) {
            $env = [
                'ADMIN_BUILD_OUTPUT_DIR' => public_path('iPanel'),
                'NODE_ENV' => 'production',
                'VITE_NODE_ENV' => 'production',
                'VITE_BUILD_VERSION' => $version.'-'.date('YmdHis'),
                // Vite reads process env before .env files, so an empty value stops a local .env.local of the admin
                // from baking owner values; the admin reads the site values from config.js (empty falls through).
                'VITE_BASE_URL' => '',
                'VITE_DOMAIN' => '',
                'VITE_PROTOCOL' => '',
                'VITE_SECURE_COOKIE' => '',
                'VITE_APP_ADMIN_AUTH_KEY' => '',
                'VITE_CMS_API_URL' => '',
            ];
            if ($runner->run(['pnpm', '--dir', $adminRoot, 'build'], $paths->base(), $env) !== 0) {
                return $this->failWith('The admin build failed.', 'Read the error above, run `pnpm install` if packages are missing, then try again. The released admin is still in place.');
            }

            $adminConfig->write();
        }

        $this->writeMarker($site, $admin, $version);
        $this->components->info('Build finished'.($admin ? '; admin config written to public/iPanel/config.js' : '').'.');

        return self::SUCCESS;
    }

    /**
     * Print the problem and its fix, return the failure code.
     */
    private function failWith(string $problem, string $fix): int
    {
        $this->components->error($problem);
        $this->line('Fix: '.$fix);

        return self::FAILURE;
    }

    /**
     * Record what was built here. A later partial build keeps the flags of the earlier one.
     */
    private function writeMarker(bool $site, bool $admin, string $version): void
    {
        $path = storage_path('app/ispa/custom-build.json');
        $old = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $old = is_array($old) ? $old : [];

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'site' => $site || ($old['site'] ?? false) === true,
            'admin' => $admin || ($old['admin'] ?? false) === true,
            'built_at' => date('c'),
            'version' => $version,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
