<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Packages\PackageBuilder;
use Cms\Core\Services\Modules\Packages\PackageBuildException;
use Illuminate\Console\Command;

/**
 * Builds a deterministic module package zip and its unsigned manifest for offline signing.
 *
 * A module that declares `frontend.entries` in module.json gets its prebuilt assets under `<Module>/frontend-build/`
 * (FrontendBuilder, `vite.module.config.mjs`). That needs Node, pnpm on PATH and an installed ispa-cms node_modules;
 * when they are missing the build fails with MODULE_PACKAGE_BUILD_FRONTEND_BUILD. There is no option to skip it.
 */
class ModulePackageBuildCommand extends Command
{
    protected $signature = 'cms:module-package-build {module : Module folder name}
        {--out= : Output folder (default storage/app/module-packages)}
        {--modules-path= : Folder holding the module folders (default base_path(\'cms\'))}
        {--key-id= : Key id (16 lowercase hex) of the key that will sign the manifest}';

    protected $description = 'Build <Module>-<version>.zip and <Module>-<version>.manifest.json for a runtime-installable module.';

    /**
     * Build the package; every refusal prints its code and message and exits non-zero.
     */
    public function handle(PackageBuilder $builder): int
    {
        $out = trim((string) $this->option('out')) ?: storage_path('app/module-packages');
        $modules = trim((string) $this->option('modules-path')) ?: base_path('cms');
        try {
            $result = $builder->build((string) $this->argument('module'), $modules, $out, trim((string) $this->option('key-id')));
        } catch (PackageBuildException $e) {
            $this->error($e->errorCode . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $data = $result['manifest_data'];
        $this->info(sprintf('Built %s %s: %d files, zip %d bytes, sha256 %s', $data['name'], $data['version'], count($data['files']), $data['zip_size'], $data['zip_sha256']));
        if (isset($data['frontend'])) {
            $built = array_filter(array_keys($data['files']), static fn (string $path): bool => str_starts_with($path, $data['name'] . '/frontend-build/'));
            $this->line(sprintf('Frontend: %d built files under %s/frontend-build/ (styles: %s)', count($built), $data['name'], $data['frontend']['styles']));
        }
        $this->line('Zip:      ' . $result['zip']);
        $this->line('Manifest: ' . $result['manifest'] . ' (unsigned; sign with cms:module-package-sign)');

        return self::SUCCESS;
    }
}
